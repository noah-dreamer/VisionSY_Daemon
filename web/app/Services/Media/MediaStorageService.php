<?php

namespace App\Services\Media;

use App\Enums\DerivativeStatus;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Jobs\Media\ProcessNewUpload;
use App\Models\MediaGallery;
use App\Models\MediaResource;
use App\Models\MediaStorageSource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class MediaStorageService
{
    public function __construct(
        private ImageDerivativeService $derivatives,
        private MediaMetadataService $metadata,
        private AuditLogger $audit,
        private PathResolverService $pathResolver,
    ) {}

    public function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    /**
     * 上传原件写入所选图库，与目录扫描发现的文件共用索引和派生流程。
     */
    public function store(UploadedFile $file, array $meta, User $uploader): MediaResource
    {
        $sourceId = $meta['storage_source_id'] ?? null;
        $galleryId = $meta['gallery_id'] ?? null;
        if ($galleryId !== null) {
            $gallery = MediaGallery::findOrFail($galleryId);
            $source = $gallery->storageSource;
            if ($sourceId !== null && (int) $sourceId !== (int) $source->id) {
                throw new \RuntimeException('所选图库不属于所选存储源。');
            }
        } else {
            $source = $sourceId !== null ? MediaStorageSource::findOrFail($sourceId) : $this->defaultUploadSource();
            $gallery = app(GalleryService::class)->ensureRootGallery($source);
        }

        if (! $source->is_enabled || ! $source->isWritable()) {
            throw new \RuntimeException('目标存储源不可写入或已停用。');
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $ext = substr(preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin', 0, 16);
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $type = MediaType::fromMime($mime);
        $ulid = strtolower((string) Str::ulid());
        $stem = pathinfo(basename(str_replace('\\', '/', $file->getClientOriginalName())), PATHINFO_FILENAME);
        $stem = trim(preg_replace('/[\x00-\x1f\x7f<>:"|?*]/u', '_', $stem), '. ');
        $stem = mb_strcut($stem ?: 'upload', 0, 160, 'UTF-8');
        $relativePath = $stem.'-'.$ulid.'.'.$ext;
        $targetDir = $this->pathResolver->resolvePath($source->root_path, $gallery->relative_path);
        if (! is_dir($targetDir)) {
            throw new \RuntimeException('图库目录不存在，请先重新扫描。');
        }
        $absOriginal = $this->pathResolver->resolvePath($source->root_path, $gallery->relative_path, $relativePath);
        // Copy to a hidden temporary file before publishing, so scans never see partial uploads.
        $temporary = $targetDir.'/.upload-'.$ulid;
        $input = fopen($file->getRealPath(), 'rb');
        $output = fopen($temporary, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($temporary);
            throw new \RuntimeException('无法写入图库。');
        }
        $copyError = null;
        $copied = false;
        try {
            $copied = stream_copy_to_stream($input, $output);
        } catch (\Throwable $e) {
            $copyError = $e;
        } finally {
            fclose($input);
            fclose($output);
        }
        if ($copyError !== null || $copied === false || $copied !== $file->getSize()) {
            @unlink($temporary);
            throw new \RuntimeException('上传文件写入不完整。', 0, $copyError);
        }
        if (! rename($temporary, $absOriginal)) {
            @unlink($temporary);
            throw new \RuntimeException('无法完成上传文件写入。');
        }

        $checksum = hash_file('sha256', $absOriginal);
        $size = filesize($absOriginal);

        $tags = collect(explode(',', (string) ($meta['tags'] ?? '')))
            ->map(fn ($t) => trim($t))
            ->filter()
            ->unique()
            ->take(12)
            ->values()
            ->all();

        $attributes = [
            'title' => $meta['title'],
            'description' => $meta['description'] ?? null,
            'tags' => $tags ?: null,
            'type' => $type,
            'status' => MediaStatus::Active,
            'index_status' => MediaStatus::Active,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'original_ext' => $ext,
            'mime' => $mime,
            'size_bytes' => $size,
            'checksum_sha256' => $checksum,
            'metadata' => null,
            'storage_source_id' => $source->id,
            'gallery_id' => $gallery->id,
            'relative_path' => $relativePath,
            'source_size_bytes' => $size,
            'source_modified_at' => now(),
            'source_identity' => hash('sha256', $relativePath.'|'.$size.'|'.filemtime($absOriginal)),
            'has_thumb' => false,
            'has_preview' => false,
            'uploaded_by' => $uploader->id,
            'is_system_managed' => true,
            'derivative_status' => DerivativeStatus::Pending->value,
            'derivative_attempts' => 0,
        ];
        $resource = MediaResource::firstOrCreate([
            'gallery_id' => $gallery->id,
            'relative_path' => $relativePath,
        ], $attributes);
        if (! $resource->wasRecentlyCreated) {
            $resource->update(array_intersect_key($attributes, array_flip([
                'title', 'description', 'tags', 'uploaded_by', 'original_name', 'is_system_managed',
            ])));
        }
        $gallery->update(['media_count' => $gallery->mediaResources()->notMissing()->count()]);

        $this->audit->log('media.uploaded', $uploader, 'media_resource', (string) $resource->id, [
            'title' => $resource->title,
            'mime' => $mime,
            'size' => $size,
            'checksum' => $checksum,
            'storage_source_id' => $source->id,
            'gallery_id' => $gallery->id,
        ]);

        ProcessNewUpload::dispatch($resource->id);

        return $resource;
    }

    private function defaultUploadSource(): MediaStorageSource
    {
        $source = MediaStorageSource::enabled()->where('is_read_only', false)->orderBy('id')->get()
            ->first(fn ($source) => $source->isWritable());
        if ($source !== null) {
            return $source;
        }
        if (MediaStorageSource::exists()) {
            throw new \RuntimeException('没有可写的图库目录，请先由管理员配置。');
        }
        $root = (string) config('visionsy.media.managed_root', '/data/visionsy/managed');
        $root = app(StorageSourceService::class)->validateAndNormalizeRootPath($root, 'local');
        if (! is_writable($root)) {
            throw new \RuntimeException('图库目录不可写入。');
        }

        return MediaStorageSource::create([
            'name' => '媒体库', 'driver' => 'local', 'root_path' => $root,
            'is_enabled' => true, 'is_read_only' => false, 'scan_recursive' => true,
        ]);
    }

    /**
     * 旧模式上传（legacy）：不推荐，保留向后兼容。
     */
    public function storeLegacy(UploadedFile $file, array $meta, User $uploader): MediaResource
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $type = MediaType::fromMime($mime);

        $ulid = strtolower((string) Str::ulid());
        $dir = substr($ulid, 0, 2).'/'.substr($ulid, 2, 2).'/'.$ulid;

        $file->storeAs("media/{$dir}", "original.{$ext}", ['disk' => 'local']);
        $absOriginal = $this->disk()->path("media/{$dir}/original.{$ext}");

        $checksum = hash_file('sha256', $absOriginal);
        $size = filesize($absOriginal);

        $extracted = $this->metadata->extract($absOriginal, $mime);

        $width = $height = null;
        $hasDerivatives = false;

        $frameSource = null;
        if ($type === MediaType::Image) {
            $frameSource = $absOriginal;
        } elseif ($type === MediaType::Video) {
            $frameSource = $this->extractVideoFrame($absOriginal, $this->disk()->path("media/{$dir}/frame.jpg"));
        }

        if ($frameSource !== null && ImageDerivativeService::available()) {
            try {
                $dim = $this->derivatives->generate(
                    $frameSource,
                    $this->disk()->path("media/{$dir}/thumb.jpg"),
                    $this->disk()->path("media/{$dir}/preview.jpg"),
                );
                if ($type === MediaType::Image) {
                    $width = $dim['width'];
                    $height = $dim['height'];
                }
                $hasDerivatives = true;
            } catch (\Throwable) {
            }
        }

        $tags = collect(explode(',', (string) ($meta['tags'] ?? '')))
            ->map(fn ($t) => trim($t))
            ->filter()
            ->unique()
            ->take(12)
            ->values()
            ->all();

        $resource = MediaResource::create([
            'title' => $meta['title'],
            'description' => $meta['description'] ?? null,
            'tags' => $tags ?: null,
            'type' => $type,
            'status' => MediaStatus::Active,
            'index_status' => MediaStatus::Active,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'original_ext' => $ext,
            'mime' => $mime,
            'size_bytes' => $size,
            'checksum_sha256' => $checksum,
            'width' => $width,
            'height' => $height,
            'metadata' => $extracted['all'] ?: null,
            'taken_at' => $extracted['quick']['taken_at'] ?? null,
            'camera' => isset($extracted['quick']['camera']) ? mb_substr($extracted['quick']['camera'], 0, 120) : null,
            'duration_seconds' => $extracted['quick']['duration_seconds'] ?? null,
            'storage_dir' => $dir,
            'has_thumb' => $hasDerivatives,
            'has_preview' => $hasDerivatives,
            'uploaded_by' => $uploader->id,
            'is_system_managed' => true,
        ]);

        $this->audit->log('media.uploaded', $uploader, 'media_resource', (string) $resource->id, [
            'title' => $resource->title,
            'mime' => $mime,
            'size' => $size,
            'checksum' => $checksum,
        ]);

        return $resource;
    }

    private function extractVideoFrame(string $videoPath, string $framePath): ?string
    {
        $bin = config('visionsy.media.ffmpeg_path') ?: 'ffmpeg';

        try {
            $process = new Process([
                $bin, '-y', '-ss', '1', '-i', $videoPath,
                '-frames:v', '1', '-q:v', '3', $framePath,
            ]);
            $process->setTimeout(30);
            $process->run();

            return $process->isSuccessful() && is_file($framePath) ? $framePath : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function findDuplicate(string $checksum): ?MediaResource
    {
        return MediaResource::query()->where('checksum_sha256', $checksum)->first();
    }

    /**
     * 删除媒体资源：可写图库统一删除原件和派生文件；只读目录仅移除索引。
     */
    public function delete(MediaResource $resource, User $actor): void
    {
        $id = (string) $resource->id;
        $title = $resource->title;

        if ($resource->is_system_managed && $resource->isLegacy()) {
            $this->disk()->deleteDirectory("media/{$resource->storage_dir}");
            $resource->delete();

            $this->audit->log('media.deleted', $actor, 'media_resource', $id, ['title' => $title]);

            return;
        }

        if (! $resource->isLegacy()) {
            $source = $resource->storageSource;
            if ($source !== null && $source->isWritable()) {
                $absPath = $this->pathResolver->resolveOriginalAbsolute($resource);
                $this->pathResolver->validateWithinRoot($absPath, $source->root_path);

                if (is_file($absPath)) {
                    @unlink($absPath);
                }

                $derivativeVariants = [
                    DerivativeVariant::Thumb,
                    DerivativeVariant::Large,
                    DerivativeVariant::WatermarkedJpeg,
                    DerivativeVariant::WatermarkedPng,
                ];
                foreach ($derivativeVariants as $variant) {
                    try {
                        $derivativePath = $this->pathResolver->resolveNewDerivativeAbsolute($resource, $variant);
                        if (is_file($derivativePath)) {
                            @unlink($derivativePath);
                        }
                    } catch (\Throwable) {
                    }
                    try {
                        $legacyPath = $this->pathResolver->resolveDerivativeAbsolute($resource, $variant);
                        if ($legacyPath !== $derivativePath && is_file($legacyPath)) {
                            @unlink($legacyPath);
                        }
                    } catch (\Throwable) {
                    }
                }

                $previewBase = dirname($this->pathResolver->resolveNewDerivativeAbsolute($resource, DerivativeVariant::Thumb));
                $this->deleteTree($previewBase);

                $resource->delete();
                $this->audit->log('media.deleted', $actor, 'media_resource', $id, ['title' => $title]);

                return;
            }
        }

        $resource->update([
            'index_status' => MediaStatus::Missing->value,
            'missing_at' => now(),
        ]);

        $this->audit->log('media.removed_from_index', $actor, 'media_resource', $id, [
            'title' => $title,
            'note' => '仅从索引移除，原文件未删除',
        ]);
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $path = $dir.'/'.$item;
            is_dir($path) && ! is_link($path) ? $this->deleteTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
