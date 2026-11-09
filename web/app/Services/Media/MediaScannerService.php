<?php

namespace App\Services\Media;

use App\Enums\DerivativeStatus;
use App\Enums\MediaStatus;
use App\Jobs\Media\ProcessNewUpload;
use App\Models\MediaGallery;
use App\Models\MediaResource;
use App\Models\MediaStorageSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class MediaScannerService
{
    public function __construct(
        private PathResolverService $pathResolver,
        private FileTypeDetector $fileTypeDetector,
        private MediaMetadataService $metadata,
    ) {}

    /**
     * 扫描图库内的文件（有界内存：使用 generator + last_seen_at 标记）。
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

        $indexed = 0;
        $updated = 0;
        $errors = 0;
        $discoveredPaths = [];

        foreach ($this->enumerateFiles($source, $gallery) as $fileInfo) {
            $discoveredPaths[] = $fileInfo['relative_path'];
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

        $missing = $this->markMissingFiles($gallery, $discoveredPaths);

        $pendingResources = MediaResource::query()
            ->where('gallery_id', $gallery->id)
            ->where('index_status', MediaStatus::Active->value)
            ->where('derivative_status', DerivativeStatus::Pending->value)
            ->pluck('id');

        foreach ($pendingResources as $resourceId) {
            try {
                ProcessNewUpload::dispatch($resourceId);
            } catch (Throwable $e) {
                Log::warning("派生生成任务调度失败 #{$resourceId}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

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
     * 枚举图库内文件（generator，有界内存）。
     * 整个跳过 preview、旧 _visionsy、隐藏目录、符号链接和排除模式。
     * scan_recursive=false 时仅扫描一级目录。
     *
     * @return \Generator<array{absolute_path: string, relative_path: string, size: int, mtime: int, identity: string}>
     */
    private function enumerateFiles(MediaStorageSource $source, MediaGallery $gallery): \Generator
    {
        $galleryPath = $gallery->fullPath();
        $excludeList = config('visionsy.media.scan_exclude_patterns', []);
        // The root gallery indexes loose files; child folders are separate galleries.
        $recursive = (bool) $source->scan_recursive && $gallery->relative_path !== '';
        $normalizedGalleryPath = rtrim(str_replace('\\', '/', $galleryPath), '/').'/';
        $galleryPathLen = strlen($normalizedGalleryPath);

        $accept = function (\SplFileInfo $item) use ($excludeList): bool {
            $name = $item->getBasename();
            if ($item->isLink() || str_starts_with($name, '.')
                || in_array(strtolower($name), [PathResolverService::DERIVATIVE_DIR, PathResolverService::LEGACY_DERIVATIVE_DIR], true)) {
                return false;
            }
            foreach ($excludeList as $pattern) {
                if ($pattern !== '' && fnmatch($pattern, $name, FNM_CASEFOLD)) {
                    return false;
                }
            }

            return true;
        };

        if ($recursive) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($galleryPath, \FilesystemIterator::SKIP_DOTS),
                    $accept
                )
            );
        } else {
            $iterator = new \FilesystemIterator($galleryPath, \FilesystemIterator::SKIP_DOTS);
        }

        foreach ($iterator as $item) {
            if (! $accept($item) || ! $item->isFile()) {
                continue;
            }
            $itemPath = str_replace('\\', '/', $item->getPathname());

            $absPath = $item->getPathname();
            $relPath = substr($itemPath, $galleryPathLen);

            try {
                $this->pathResolver->validateRelativePath($relPath);
            } catch (RuntimeException) {
                continue;
            }

            $size = $item->getSize();
            $mtime = $item->getMTime();

            yield [
                'absolute_path' => $absPath,
                'relative_path' => $relPath,
                'size' => $size,
                'mtime' => $mtime,
                'identity' => hash('sha256', $relPath.'|'.$size.'|'.$mtime),
            ];
        }
    }

    /**
     * 索引或更新单个文件。
     */
    private function indexOrUpdateFile(MediaStorageSource $source, MediaGallery $gallery, array $fileInfo): string
    {
        $relativePath = $fileInfo['relative_path'];
        $fileMtime = Carbon::createFromTimestamp($fileInfo['mtime'], 'UTC');

        $existing = MediaResource::query()
            ->where('gallery_id', $gallery->id)
            ->where('relative_path', $relativePath)
            ->first();

        if ($existing !== null) {
            $needsUpdate = false;

            if ($existing->source_identity !== $fileInfo['identity']) {
                $needsUpdate = true;
            }

            if ($existing->isMissing()) {
                $needsUpdate = true;
            }

            if ($needsUpdate) {
                $checksum = $this->computeChecksumIfNeeded($existing, $fileInfo);
                $typeResult = $this->fileTypeDetector->detect($fileInfo['absolute_path']);

                $existing->update([
                    'index_status' => MediaStatus::Active->value,
                    'missing_at' => null,
                    'size_bytes' => $fileInfo['size'],
                    'type' => $this->fileTypeDetector->toMediaType($typeResult['category']),
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

            $updates = ['last_seen_at' => now()];
            if (in_array($existing->type->value, ['image', 'video'], true)
                && $existing->derivative_status === DerivativeStatus::Ready->value) {
                foreach (DerivativeVariant::cases() as $variant) {
                    if (! is_file($this->pathResolver->resolveNewDerivativeAbsolute($existing, $variant))) {
                        $updates['derivative_status'] = DerivativeStatus::Pending->value;
                        break;
                    }
                }
            }
            $existing->update($updates);

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
    private function markMissingFiles(MediaGallery $gallery, array $discoveredPaths): int
    {
        $query = MediaResource::query()
            ->where('gallery_id', $gallery->id)
            ->where('index_status', '!=', MediaStatus::Missing->value);

        if ($discoveredPaths !== []) {
            $query->whereNotIn('relative_path', $discoveredPaths);
        }

        return $query->update([
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
