<?php

namespace App\Jobs\Media;

use App\Enums\DerivativeStatus;
use App\Models\MediaResource;
use App\Services\Media\ImageDerivativeService;
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

class GenerateMediaDerivatives implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;
    public int $backoff = 30;

    public function __construct(
        private int $resourceId,
    ) {
        $this->queue = 'derivatives';
    }

    public function uniqueId(): string
    {
        return 'derivatives:'.$this->resourceId;
    }

    public function handle(
        PathResolverService $pathResolver,
        ImageDerivativeService $imageService,
        MediaPreviewConverter $converter,
    ): void {
        $resource = MediaResource::find($this->resourceId);

        if ($resource === null || $resource->isLegacy()) {
            return;
        }

        if ($resource->type->value !== 'image') {
            return;
        }

        if (! ImageDerivativeService::available()) {
            $resource->update([
                'derivative_status' => DerivativeStatus::Failed->value,
                'derivative_error' => 'PHP GD 扩展不可用。',
            ]);

            return;
        }

        try {
            $this->validateSourceUnchanged($resource, $pathResolver);

            $resource->update([
                'derivative_status' => DerivativeStatus::Processing->value,
                'derivative_attempts' => ($resource->derivative_attempts ?? 0) + 1,
            ]);

            if ($resource->is_raw) {
                $result = $converter->toJpeg(
                    $pathResolver->resolveOriginalAbsolute($resource),
                    sys_get_temp_dir(),
                    (int) config('visionsy.media.preview_max_edge', 1600)
                );
                $sourceJpeg = $result['path'];
                $converterVersion = $converter->version();
            } else {
                $sourceJpeg = $pathResolver->resolveOriginalAbsolute($resource);
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
        } catch (Throwable $e) {
            $this->recordFailure($resource, $e);
            throw $e;
        }
    }

    private function validateSourceUnchanged(MediaResource $resource, PathResolverService $pathResolver): void
    {
        $absPath = $pathResolver->resolveOriginalAbsolute($resource);

        if (! is_file($absPath) || ! is_readable($absPath)) {
            $resource->update([
                'index_status' => \App\Enums\MediaStatus::Missing->value,
                'missing_at' => now(),
                'derivative_status' => DerivativeStatus::Failed->value,
                'derivative_error' => '源文件丢失。',
            ]);
            throw new \RuntimeException('源文件不可访问。');
        }

        if ($resource->source_identity === null) {
            return;
        }

        $currentSize = filesize($absPath);
        $currentMtime = filemtime($absPath);
        $currentIdentity = hash('sha256', str_replace('\\', '/', $resource->relative_path).'|'.$currentSize.'|'.$currentMtime);

        if (! hash_equals($resource->source_identity, $currentIdentity)) {
            throw new \RuntimeException('源文件在处理期间发生变化，结果可能过期。');
        }
    }

    private function recordFailure(MediaResource $resource, Throwable $e): void
    {
        Log::error("派生图生成失败 #{$resource->id}", [
            'error' => $e->getMessage(),
            'is_raw' => $resource->is_raw,
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
