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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaStorageService
{
    public function __construct(
        private ImageDerivativeService $derivatives,
        private MediaMetadataService $metadata,
        private AuditLogger $audit,
        private PathResolverService $pathResolver,
    ) {
    }

    public function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk('local');
    }

    /**
     * Managed 上传：原件写入 managed 根目录下的，资源必须归属明确的 storage_source 和 gallery。
     */
    public function store(UploadedFile $file, array $meta, User $uploader): MediaResource
    {
        $sourceId = $meta['storage_source_id'] ?? null;
        $galleryId = $meta['gallery_id'] ?? null;

        if ($sourceId === null || $galleryId === null) {
            return $this->storeLegacy($file, $meta, $uploader);
        }

        $source = MediaStorageSource::findOrFail($sourceId);
        $gallery = MediaGallery::where('id', $galleryId)
            ->where('storage_source_id', $sourceId)
            ->firstOrFail();

        if ($source->is_read_only || ! $source->isWritable()) {
            throw new \RuntimeException('目标存储源不可写入。');
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $type = MediaType::fromMime($mime);

        $ulid = strtolower((string) Str::ulid());
        $relativePath = substr($ulid, 0, 2).'/'.substr($ulid, 2, 2).'/'.$ulid.'/original.'.$ext;

        $galleryFullPath = rtrim($source->root_path, '/').'/'.ltrim($gallery->relative_path, '/');
        $targetDir = $galleryFullPath.'/'.substr($ulid, 0, 2).'/'.substr($ulid, 2, 2).'/'.$ulid;

        if (! is_dir($targetDir)) {
            @mkdir($targetDir, 0775, true);
        }

        $absOriginal = $targetDir.'/original.'.$ext;
        $file->move($targetDir, 'original.'.$ext);

        $checksum = hash_file('sha256', $absOriginal);
        $size = filesize($absOriginal);

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
        ]);

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
            $process = new \Symfony\Component\Process\Process([
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
     * 删除媒体资源：系统管理的文件物理删除，外部扫描的文件仅从索引移除。
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

        if ($resource->is_system_managed && ! $resource->isLegacy()) {
            $source = $resource->storageSource;
            if ($source !== null && $source->isWritable()) {
                $absPath = $this->pathResolver->resolveOriginalAbsolute($resource);
                $this->pathResolver->validateWithinRoot($absPath, $source->root_path);

                if (is_file($absPath)) {
                    @unlink($absPath);
                }

                @rmdir(dirname($absPath));
                @rmdir(dirname($absPath, 2));
                @rmdir(dirname($absPath, 3));

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

                $derivativeBase = $this->pathResolver->getDerivativesRoot().'/'
                    .(string) $resource->storage_source_id.'/'
                    .(string) $resource->gallery_id.'/'
                    .(string) $resource->id;
                $this->deleteTree($derivativeBase);

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
            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
