<?php

namespace App\Jobs\Media;

use App\Models\MediaStorageSource;
use App\Services\Media\GalleryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DiscoverMediaGalleries implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 660;

    public function uniqueId(): string
    {
        return 'library-scan:'.$this->sourceId;
    }

    public function __construct(
        private int $sourceId,
    ) {
        $this->queue = 'scan';
    }

    public function handle(GalleryService $galleryService): void
    {
        $source = MediaStorageSource::find($this->sourceId);
        if ($source === null || ! $source->is_enabled) {
            return;
        }

        $source->markScanStarted();

        try {
            $found = $galleryService->discoverGalleries($source);
            $source->markScanCompleted();

            $pendingGalleries = $source->galleries()->get();

            foreach ($pendingGalleries as $gallery) {
                ScanMediaGallery::dispatch($gallery->id);
            }
        } catch (\Throwable $e) {
            $source->markScanFailed($e->getMessage());
            throw $e;
        }
    }
}
