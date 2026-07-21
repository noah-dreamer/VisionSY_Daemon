<?php

namespace App\Services\Media;

use App\Jobs\Media\GenerateMediaDerivatives;
use App\Enums\DerivativeStatus;
use App\Enums\MediaStatus;
use App\Models\MediaGallery;
use App\Models\MediaResource;
use App\Models\MediaStorageSource;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class MediaScannerService
{
    public function __construct(
        private PathResolverService $pathResolver,
        private FileTypeDetector $fileTypeDetector,
        private MediaMetadataService $metadata,
    ) {
    }

    /**
     * 递归扫描图库内的文件。
     *
     * @return array{indexed: int, updated: int, missing: int, errors: int}
     */
    public function scanGallery(MediaGallery $gallery): array
    {
        $source = $gallery->storageSource;
        $galleryPath = $gallery->fullPath();

        if (! is_dir($galleryPath) || ! is_readable($galleryPath)) {
            $gallery->update([
                'scan_status' => 'failed',
                'last_scanned_at' => now(),
            ]);
            throw new RuntimeException("图库目录不可访问: {$galleryPath}");
        }

        $discovered = $this->enumerateFiles($galleryPath, $source, $gallery);
        $indexed = 0;
        $updated = 0;
        $errors = 0;

        foreach ($discovered as $fileInfo) {
            try {
                $result = $this->indexOrUpdateFile($source, $gallery, $fileInfo);
                if ($result === 'indexed') {
                    $indexed++;
                } elseif ($result === 'updated') {
                    $updated++;
                }
            } catch (Throwable $e) {
                $errors++;
                Log::error('扫描文件失败', [
                    'path' => $fileInfo['relative_path'],
                    'gallery_id' => $gallery->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $pendingResources = MediaResource::query()
            ->where('gallery_id', $gallery->id)
            ->where('derivative_status', DerivativeStatus::Pending->value)
            ->whereIn('type', ['image'])
            ->pluck('id');

        foreach ($pendingResources as $resourceId) {
            GenerateMediaDerivatives::dispatch($resourceId);
        }

        $missing = $this->markMissingFiles($gallery, $discovered);

        $gallery->update([
            'scan_status' => 'completed',
            'last_scanned_at' => now(),
            'media_count' => MediaResource::query()
                ->where('gallery_id', $gallery->id)
                ->where('index_status', '!=', MediaStatus::Missing->value)
                ->count(),
        ]);

        return [
            'indexed' => $indexed,
            'updated' => $updated,
            'missing' => $missing,
            'errors' => $errors,
        ];
    }

    /**
     * 枚举图库内所有文件（递归，忽略 _visionsy）。
     *
     * @return array<int, array{absolute_path: string, relative_path: string, size: int, mtime: int}>
     */
    private function enumerateFiles(string $galleryPath, MediaStorageSource $source, MediaGallery $gallery): array
    {
        $files = [];
        $excludeList = config('visionsy.media.scan_exclude_patterns', []);

        $recursiveDir = new \RecursiveDirectoryIterator(
            $galleryPath,
            \RecursiveDirectoryIterator::SKIP_DOTS
        );

        $iterator = new \RecursiveIteratorIterator(
            $recursiveDir,
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $galleryPathLen = strlen(rtrim($galleryPath, '/')) + 1;

        foreach ($iterator as $item) {
            $itemPath = str_replace('\\', '/', $item->getPathname());

            if (str_contains($itemPath, '/'.PathResolverService::DERIVATIVE_DIR.'/')
                || str_ends_with($itemPath, '/'.PathResolverService::DERIVATIVE_DIR)) {
                continue;
            }

            $basename = $item->getBasename();

            if (str_starts_with($basename, '.')) {
                continue;
            }

            if ($item->isLink()) {
                continue;
            }

            $skip = false;
            foreach ($excludeList as $pattern) {
                if ($pattern === '') {
                    continue;
                }
                $regex = '/^'.str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($pattern, '/')).'$/i';
                if (preg_match($regex, $basename)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            if (! $item->isFile()) {
                continue;
            }

            $absPath = $item->getPathname();
            $relPath = str_replace('\\', '/', substr($absPath, $galleryPathLen));

            try {
                $this->pathResolver->validateRelativePath($relPath);
            } catch (RuntimeException) {
                continue;
            }

            $size = $item->getSize();
            $mtime = $item->getMTime();

            $files[] = [
                'absolute_path' => $absPath,
                'relative_path' => $relPath,
                'size' => $size,
                'mtime' => $mtime,
                'identity' => hash('sha256', $relPath.'|'.$size.'|'.$mtime),
            ];
        }

        return $files;
    }

    /**
     * 索引或更新单个文件。
     */
    private function indexOrUpdateFile(MediaStorageSource $source, MediaGallery $gallery, array $fileInfo): string
    {
        $relativePath = $fileInfo['relative_path'];
        $fileMtime = \Illuminate\Support\Carbon::createFromTimestamp($fileInfo['mtime']);

        $existing = MediaResource::query()
            ->where('storage_source_id', $source->id)
            ->where('relative_path', $relativePath)
            ->first();

        if ($existing !== null) {
            $needsUpdate = false;

            if ($existing->source_size_bytes !== $fileInfo['size']) {
                $needsUpdate = true;
            }

            if ($existing->source_modified_at === null ||
                $existing->source_modified_at->timestamp !== $fileInfo['mtime']) {
                $needsUpdate = true;
            }

            if ($existing->isMissing()) {
                $existing->update([
                    'index_status' => MediaStatus::Active->value,
                    'missing_at' => null,
                    'last_seen_at' => now(),
                    'source_size_bytes' => $fileInfo['size'],
                    'source_modified_at' => $fileMtime,
                    'source_identity' => $fileInfo['identity'],
                ]);

                return 'updated';
            }

            if ($needsUpdate) {
                $checksum = $this->computeChecksumIfNeeded($existing, $fileInfo);
                $typeResult = $this->fileTypeDetector->detect($fileInfo['absolute_path']);
                $metaType = $this->fileTypeDetector->toMediaType($typeResult['category']);

                $existing->update([
                    'source_size_bytes' => $fileInfo['size'],
                    'source_modified_at' => $fileMtime,
                    'source_identity' => $fileInfo['identity'],
                    'mime' => $typeResult['mime'],
                    'is_raw' => $typeResult['is_raw'],
                    'last_seen_at' => now(),
                    'derivative_status' => DerivativeStatus::Pending->value,
                    'derivative_error' => null,
                ]);

                if ($checksum !== null) {
                    $existing->update(['checksum_sha256' => $checksum]);
                }

                return 'updated';
            }

            $existing->update(['last_seen_at' => now()]);

            return 'unchanged';
        }

        $typeResult = $this->fileTypeDetector->detect($fileInfo['absolute_path']);
        $metaType = $this->fileTypeDetector->toMediaType($typeResult['category']);
        $originalName = basename($relativePath);
        $ext = pathinfo($originalName, PATHINFO_EXTENSION);

        $checksum = hash_file('sha256', $fileInfo['absolute_path']);

        MediaResource::create([
            'storage_source_id' => $source->id,
            'gallery_id' => $gallery->id,
            'relative_path' => $relativePath,
            'title' => pathinfo($originalName, PATHINFO_FILENAME),
            'type' => $metaType,
            'status' => MediaStatus::Active,
            'index_status' => MediaStatus::Active,
            'original_name' => mb_substr($originalName, 0, 255),
            'original_ext' => strtolower($ext) ?: 'bin',
            'mime' => $typeResult['mime'],
            'size_bytes' => $fileInfo['size'],
            'source_size_bytes' => $fileInfo['size'],
            'source_modified_at' => $fileMtime,
            'source_identity' => $fileInfo['identity'],
            'is_raw' => $typeResult['is_raw'],
            'is_system_managed' => false,
            'derivative_status' => DerivativeStatus::Pending->value,
            'last_seen_at' => now(),
            'checksum_sha256' => $checksum,
        ]);

        return 'indexed';
    }

    /**
     * 标记在扫描中消失的文件。
     */
    private function markMissingFiles(MediaGallery $gallery, array $discovered): int
    {
        $discoveredPaths = array_column($discovered, 'relative_path');

        if ($discoveredPaths === []) {
            return 0;
        }

        return MediaResource::query()
            ->where('gallery_id', $gallery->id)
            ->where('index_status', '!=', MediaStatus::Missing->value)
            ->whereNotIn('relative_path', $discoveredPaths)
            ->update([
                'index_status' => MediaStatus::Missing->value,
                'missing_at' => now(),
            ]);
    }

    private function computeChecksumIfNeeded(?MediaResource $existing, array $fileInfo): ?string
    {
        $checksumChangedOnly = config('visionsy.media.scanner.checksum_changed_only', true);

        if ($checksumChangedOnly && $existing !== null) {
            if ($existing->source_size_bytes === $fileInfo['size'] &&
                $existing->source_modified_at !== null &&
                $existing->source_modified_at->timestamp === $fileInfo['mtime'] &&
                $existing->checksum_sha256 !== null) {
                return null;
            }
        }

        return hash_file('sha256', $fileInfo['absolute_path']);
    }
}
