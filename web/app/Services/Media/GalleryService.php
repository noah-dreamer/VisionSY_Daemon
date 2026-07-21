<?php

namespace App\Services\Media;

use App\Enums\ScanStatus;
use App\Models\MediaGallery;
use App\Models\MediaStorageSource;
use RuntimeException;

class GalleryService
{
    public function __construct(
        private PathResolverService $pathResolver,
    ) {
    }

    public function create(MediaStorageSource $source, array $data): MediaGallery
    {
        if ($source->is_read_only) {
            throw new RuntimeException('只读存储源无法创建图库目录。');
        }

        $folderName = $data['folder_name'] ?? $data['name'];
        $this->pathResolver->validateRelativePath($folderName);
        $safeFolder = $this->pathResolver->safeBasename($folderName);

        $relativePath = $this->pathResolver->normalizePath($safeFolder);

        $existing = MediaGallery::query()
            ->where('storage_source_id', $source->id)
            ->where('relative_path', $relativePath)
            ->exists();

        if ($existing) {
            throw new RuntimeException("图库目录已存在: {$relativePath}");
        }

        $fullPath = $source->root_path.'/'.$relativePath;

        if (! is_dir($fullPath)) {
            if (! @mkdir($fullPath, 0775, true)) {
                throw new RuntimeException("无法创建图库目录: {$relativePath}");
            }
        }

        $gallery = MediaGallery::create([
            'storage_source_id' => $source->id,
            'relative_path' => $relativePath,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'tags' => $this->normalizeTags($data['tags'] ?? null),
            'event_started_at' => $data['event_started_at'] ?? null,
            'event_ended_at' => $data['event_ended_at'] ?? null,
            'is_managed' => true,
            'is_visible' => $data['is_visible'] ?? true,
            'scan_status' => ScanStatus::Pending->value,
        ]);

        return $gallery;
    }

    public function update(MediaGallery $gallery, array $data): MediaGallery
    {
        if (isset($data['name'])) {
            $gallery->name = $data['name'];
        }
        if (array_key_exists('description', $data)) {
            $gallery->description = $data['description'];
        }
        if (array_key_exists('tags', $data)) {
            $gallery->tags = $this->normalizeTags($data['tags']);
        }
        if (array_key_exists('event_started_at', $data)) {
            $gallery->event_started_at = $data['event_started_at'];
        }
        if (array_key_exists('event_ended_at', $data)) {
            $gallery->event_ended_at = $data['event_ended_at'];
        }
        if (array_key_exists('is_visible', $data)) {
            $gallery->is_visible = $data['is_visible'];
        }
        if (array_key_exists('cover_media_resource_id', $data)) {
            $gallery->cover_media_resource_id = $data['cover_media_resource_id'];
        }

        $gallery->save();

        return $gallery;
    }

    public function discoverGalleries(MediaStorageSource $source): int
    {
        $rootPath = $source->root_path;
        if (! is_dir($rootPath) || ! is_readable($rootPath)) {
            throw new RuntimeException('存储源根路径不可访问。');
        }

        $entries = scandir($rootPath);
        if ($entries === false) {
            throw new RuntimeException('无法读取存储源根目录。');
        }

        $found = 0;
        $excludeList = config('visionsy.media.scan_exclude_patterns', []);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            // 跳过 _visionsy
            if ($entry === PathResolverService::DERIVATIVE_DIR) {
                continue;
            }

            // 跳过隐藏文件/目录
            if (str_starts_with($entry, '.')) {
                continue;
            }

            $fullPath = $rootPath.'/'.$entry;

            // 只扫描目录
            if (! is_dir($fullPath)) {
                continue;
            }

            // 跳过符号链接目录
            if (is_link($fullPath)) {
                continue;
            }

            // 跳过配置排除的目录
            if ($this->matchesExcludePattern($entry, $excludeList)) {
                continue;
            }

            // 跳过无权限目录
            if (! is_readable($fullPath)) {
                continue;
            }

            $relativePath = $this->pathResolver->normalizePath($entry);

            $gallery = MediaGallery::query()
                ->where('storage_source_id', $source->id)
                ->where('relative_path', $relativePath)
                ->first();

            if ($gallery === null) {
                MediaGallery::create([
                    'storage_source_id' => $source->id,
                    'relative_path' => $relativePath,
                    'name' => $entry,
                    'is_managed' => false,
                    'is_visible' => true,
                    'scan_status' => ScanStatus::Pending->value,
                ]);
                $found++;
            }
        }

        return $found;
    }

    public function delete(MediaGallery $gallery): void
    {
        if ($gallery->mediaResources()->count() > 0) {
            throw new RuntimeException('图库下存在媒体资源，请先清理。');
        }

        $gallery->delete();
    }

    private function matchesExcludePattern(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                continue;
            }

            $regex = '/^'.str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($pattern, '/')).'$/i';
            if (preg_match($regex, $name)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeTags(mixed $tags): ?array
    {
        if ($tags === null || $tags === '') {
            return null;
        }

        if (is_string($tags)) {
            $tags = collect(explode(',', $tags))
                ->map(fn ($t) => trim($t))
                ->filter()
                ->unique()
                ->take(12)
                ->values()
                ->all();
        }

        if (is_array($tags)) {
            $tags = collect($tags)
                ->map(fn ($t) => trim((string) $t))
                ->filter()
                ->unique()
                ->take(12)
                ->values()
                ->all();
        }

        return $tags === [] ? null : $tags;
    }
}
