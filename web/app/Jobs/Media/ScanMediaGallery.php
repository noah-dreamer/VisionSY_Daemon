<?php

namespace App\Jobs\Media;

use App\Models\MediaGallery;
use App\Services\AuditLogger;
use App\Services\Media\MediaScannerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScanMediaGallery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 3600;

    public function __construct(
        private int $galleryId,
        private ?int $actorId = null,
    ) {
        $this->queue = 'scan';
    }

    public function handle(MediaScannerService $scanner, AuditLogger $audit): void
    {
        $gallery = MediaGallery::findOrFail($this->galleryId);

        if (! $gallery->canStartScan()) {
            Log::info("图库 #{$gallery->id} 正在被其他进程扫描，跳过。");

            return;
        }

        if (! $gallery->acquireScanLock()) {
            Log::info("图库 #{$gallery->id} 获取扫描锁失败，跳过。");

            return;
        }

        try {
            $result = $scanner->scanGallery($gallery);

            $gallery->releaseScanLock();

            $audit->log(
                'gallery.scanned',
                $this->actorId !== null ? \App\Models\User::find($this->actorId) : null,
                'media_gallery',
                (string) $gallery->id,
                $result
            );
        } catch (\Throwable $e) {
            $gallery->releaseScanLock();
            $gallery->update([
                'scan_status' => 'failed',
                'last_scanned_at' => now(),
            ]);

            Log::error("图库扫描失败 #{$gallery->id}", [
                'error' => $e->getMessage(),
                'gallery' => $gallery->name,
            ]);

            throw $e;
        }
    }
}
