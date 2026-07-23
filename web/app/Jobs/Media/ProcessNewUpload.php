<?php

namespace App\Jobs\Media;

use App\Enums\DerivativeStatus;
use App\Models\MediaResource;
use App\Services\Media\FileTypeDetector;
use App\Services\Media\ImageDerivativeService;
use App\Services\Media\MediaMetadataService;
use App\Services\Media\MediaPreviewConverter;
use App\Services\Media\PathResolverService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessNewUpload implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public int $backoff = 30;

    public function __construct(
        private int $resourceId,
    ) {
        $this->queue = 'upload';
    }

    public function uniqueId(): string
    {
        return 'upload-process:'.$this->resourceId;
    }

    public function handle(
        PathResolverService $pathResolver,
        ImageDerivativeService $imageService,
        MediaMetadataService $metadata,
        FileTypeDetector $fileTypeDetector,
        MediaPreviewConverter $converter,
    ): void {
        $resource = MediaResource::find($this->resourceId);

        if ($resource === null) {
            return;
        }

        if ($resource->derivative_status === DerivativeStatus::Ready->value
            || $resource->derivative_status === DerivativeStatus::Processing->value) {
            return;
        }

        try {
            $resource->update(['derivative_status' => DerivativeStatus::Processing->value]);

            $absPath = $pathResolver->resolveOriginalAbsolute($resource);

            if (! is_file($absPath) || ! is_readable($absPath)) {
                $this->recordFailure($resource, new \RuntimeException('源文件不可访问。'));

                return;
            }

            if ($resource->type->value === 'image') {
                $this->processImage($resource, $absPath, $pathResolver, $imageService, $converter);
            } elseif ($resource->type->value === 'video') {
                $framePath = $this->extractVideoFrame($absPath, dirname($absPath).'/frame.jpg');
                if ($framePath !== null) {
                    $imageService->generateAllDerivatives($framePath, $resource, $pathResolver);
                    @unlink($framePath);
                }

                $resource->update([
                    'has_thumb' => true,
                    'has_preview' => true,
                    'derivative_status' => DerivativeStatus::Ready->value,
                    'derivative_error' => null,
                    'derivative_thumb_at' => now(),
                    'derivative_large_at' => now(),
                    'derivative_wm_jpeg_at' => now(),
                    'derivative_wm_png_at' => now(),
                ]);
            } else {
                $resource->update([
                    'derivative_status' => DerivativeStatus::Ready->value,
                    'derivative_error' => null,
                ]);
            }
        } catch (Throwable $e) {
            $this->recordFailure($resource, $e);
            throw $e;
        }
    }

    private function processImage(
        MediaResource $resource,
        string $absPath,
        PathResolverService $pathResolver,
        ImageDerivativeService $imageService,
        MediaPreviewConverter $converter,
    ): void {
        if (! ImageDerivativeService::available()) {
            $resource->update([
                'derivative_status' => DerivativeStatus::Failed->value,
                'derivative_error' => 'PHP GD 扩展不可用。',
            ]);

            return;
        }

        if ($resource->is_raw) {
            $result = $converter->toJpeg(
                $absPath,
                sys_get_temp_dir(),
                (int) config('visionsy.media.preview_max_edge', 1600)
            );
            $sourceJpeg = $result['path'];
            $converterVersion = $converter->version();
        } else {
            $sourceJpeg = $absPath;
            $converterVersion = 'native';
        }

        $dim = $imageService->generateAllDerivatives($sourceJpeg, $resource, $pathResolver);

        if ($resource->is_raw && isset($result['path'])) {
            @unlink($result['path']);
        }

        $resource->update([
            'width' => $dim['width'],
            'height' => $dim['height'],
            'has_thumb' => true,
            'has_preview' => true,
            'derivative_status' => DerivativeStatus::Ready->value,
            'derivative_error' => null,
            'derivative_thumb_at' => now(),
            'derivative_large_at' => now(),
            'derivative_wm_jpeg_at' => now(),
            'derivative_wm_png_at' => now(),
            'derivative_converter' => $converter->name(),
            'derivative_converter_version' => $converterVersion,
        ]);
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

    private function recordFailure(MediaResource $resource, Throwable $e): void
    {
        Log::error("上传后处理失败 #{$resource->id}", [
            'error' => $e->getMessage(),
            'attempt' => ($resource->derivative_attempts ?? 0) + 1,
        ]);

        $hasAnyVariant = $resource->readyVariantCount() > 0;
        $status = $hasAnyVariant ? DerivativeStatus::Partial->value : DerivativeStatus::Failed->value;

        $resource->update([
            'derivative_status' => $status,
            'derivative_error' => mb_substr($e->getMessage(), 0, 2000),
        ]);
    }
}
