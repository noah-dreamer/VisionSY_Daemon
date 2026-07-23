<?php

namespace App\Jobs\Media;

use App\Models\MediaStorageSource;
use App\Services\Media\GalleryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DiscoverMediaGalleries implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(
        private int $sourceId,
    ) {
        $this->queue = 'scan';
    }

    public function handle(GalleryService $galleryService): void
    {
        $source = MediaStorageSource::findOrFail($this->sourceId);

        $source->markScanStarted();

        try {
            $found = $galleryService->discoverGalleries($source);
            $source->markScanCompleted();

            $pendingGalleries = $source->galleries()
                ->where('scan_status', 'pending')
                ->get();

            foreach ($pendingGalleries as $gallery) {
                ScanMediaGallery::dispatch($gallery->id);
            }
        } catch (\Throwable $e) {
            $source->markScanFailed($e->getMessage());
            throw $e;
        }
    }
}
