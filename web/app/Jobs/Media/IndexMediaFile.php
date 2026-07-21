<?php

namespace App\Jobs\Media;

use App\Enums\DerivativeStatus;
use App\Models\MediaResource;
use App\Services\Media\MediaMetadataService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class IndexMediaFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;

    public function __construct(
        private int $resourceId,
    ) {
    }

    public function handle(
        MediaMetadataService $metadataService,
    ): void {
        $resource = MediaResource::findOrFail($this->resourceId);

        if ($resource->isLegacy()) {
            return;
        }

        try {
            $pathResolver = app(\App\Services\Media\PathResolverService::class);
            $absolutePath = $pathResolver->resolveOriginalAbsolute($resource);

            if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
                $resource->update([
                    'index_status' => \App\Enums\MediaStatus::Missing->value,
                    'missing_at' => now(),
                ]);

                return;
            }

            $extracted = $metadataService->extract($absolutePath, $resource->mime ?? 'application/octet-stream');

            $resource->update([
                'metadata' => $extracted['all'] ?: null,
                'taken_at' => $extracted['quick']['taken_at'] ?? null,
                'camera' => isset($extracted['quick']['camera']) ? mb_substr($extracted['quick']['camera'], 0, 120) : null,
                'duration_seconds' => $extracted['quick']['duration_seconds'] ?? null,
                'derivative_status' => DerivativeStatus::Pending->value,
                'last_seen_at' => now(),
            ]);

            if ($resource->type->value === 'image' && ! $resource->is_raw) {
                $imageService = app(\App\Services\Media\ImageDerivativeService::class);
                if (\App\Services\Media\ImageDerivativeService::available()) {
                    $dim = $imageService->probeSize($absolutePath);
                    if ($dim !== null) {
                        $resource->update(['width' => $dim['width'], 'height' => $dim['height']]);
                    }
                }
            }

            if ($resource->type->value === 'image') {
                \App\Jobs\Media\GenerateMediaDerivatives::dispatch($resource->id);
            }
        } catch (\Throwable $e) {
            Log::error("媒体文件索引失败 #{$resource->id}", [
                'error' => $e->getMessage(),
            ]);

            $resource->update([
                'derivative_status' => DerivativeStatus::Failed->value,
                'derivative_error' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            throw $e;
        }
    }
}
