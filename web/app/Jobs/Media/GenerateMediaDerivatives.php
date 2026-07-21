<?php

namespace App\Jobs\Media;

use App\Enums\DerivativeStatus;
use App\Models\MediaResource;
use App\Services\Media\ImageDerivativeService;
use App\Services\Media\PathResolverService;
use App\Services\Media\RawConverterInterface;
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
    }

    public function uniqueId(): string
    {
        return 'derivatives:'.$this->resourceId;
    }

    public function handle(
        PathResolverService $pathResolver,
        ImageDerivativeService $imageService,
        RawConverterInterface $rawConverter,
    ): void {
        $resource = MediaResource::find($this->resourceId);

        if ($resource === null) {
            return;
        }

        if ($resource->isLegacy()) {
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

            $resource->update(['derivative_status' => DerivativeStatus::Processing->value]);

            $originalPath = $pathResolver->resolveOriginalAbsolute($resource);

            if ($resource->is_raw) {
                $convertedPath = $this->convertRawToJpeg($resource, $originalPath, $pathResolver, $rawConverter);
                $dim = $imageService->generateAllDerivatives($convertedPath, $resource, $pathResolver);
                @unlink($convertedPath);
            } else {
                $dim = $imageService->generateAllDerivatives($originalPath, $resource, $pathResolver);
            }

            $resource->update([
                'width' => $dim['width'],
                'height' => $dim['height'],
                'has_thumb' => true,
                'has_preview' => true,
                'derivative_status' => DerivativeStatus::Completed->value,
                'derivative_error' => null,
            ]);
        } catch (Throwable $e) {
            Log::error("派生图生成失败 #{$resource->id}", [
                'error' => $e->getMessage(),
                'is_raw' => $resource->is_raw,
            ]);

            $resource->update([
                'derivative_status' => DerivativeStatus::Failed->value,
                'derivative_error' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            throw $e;
        }
    }

    /**
     * 检查源文件是否在处理期间被修改。
     */
    private function validateSourceUnchanged(MediaResource $resource, PathResolverService $pathResolver): void
    {
        $absPath = $pathResolver->resolveOriginalAbsolute($resource);

        if (! is_file($absPath) || ! is_readable($absPath)) {
            throw new \RuntimeException('源文件不可访问。');
        }

        $currentSize = filesize($absPath);
        $currentMtime = filemtime($absPath);

        if ($resource->source_size_bytes !== null && $resource->source_size_bytes !== $currentSize) {
            throw new \RuntimeException('源文件在处理期间大小发生变化，结果可能过期。');
        }

        if ($resource->source_modified_at !== null
            && abs($resource->source_modified_at->timestamp - $currentMtime) > 1) {
            throw new \RuntimeException('源文件在处理期间发生变化，结果可能过期。');
        }
    }

    /**
     * 将 RAW 文件转换为 JPEG。
     *
     * @return string 转换后的 JPEG 临时文件路径
     */
    private function convertRawToJpeg(
        MediaResource $resource,
        string $originalPath,
        PathResolverService $pathResolver,
        RawConverterInterface $rawConverter,
    ): string {
        $convertedPath = sys_get_temp_dir().'/visionsy-raw-'.$resource->id.'-'.bin2hex(random_bytes(8)).'.jpg';

        $rawConverter->convert($originalPath, $convertedPath, (int) config('visionsy.media.preview_max_edge', 1600));

        return $convertedPath;
    }
}
