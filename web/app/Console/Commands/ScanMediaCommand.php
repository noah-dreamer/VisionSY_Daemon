<?php

namespace App\Console\Commands;

use App\Jobs\Media\DiscoverMediaGalleries;
use App\Jobs\Media\ScanMediaGallery;
use App\Models\MediaGallery;
use App\Models\MediaStorageSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class ScanMediaCommand extends Command
{
    protected $signature = 'media:scan
                            {--source= : 指定存储源 ID}
                            {--gallery= : 指定图库 ID}
                            {--discover-only : 仅发现图库，不扫描文件}
                            {--force : 强制重新扫描，忽略已有状态}';

    protected $description = '扫描媒体存储源和图库';

    public function handle(): int
    {
        $galleryId = $this->option('gallery');
        $sourceId = $this->option('source');
        $discoverOnly = $this->option('discover-only');
        $force = $this->option('force');

        if ($galleryId !== null) {
            return $this->scanSingleGallery((int) $galleryId, $force);
        }

        if ($sourceId !== null) {
            return $this->scanSource((int) $sourceId, $discoverOnly);
        }

        return $this->scanAllSources($discoverOnly);
    }

    private function scanSource(int $sourceId, bool $discoverOnly): int
    {
        $source = MediaStorageSource::find($sourceId);

        if ($source === null) {
            $this->error("存储源 #{$sourceId} 不存在。");

            return self::FAILURE;
        }

        $this->info("开始处理存储源: {$source->name}");

        if ($discoverOnly) {
            $galleryService = app(\App\Services\Media\GalleryService::class);
            $found = $galleryService->discoverGalleries($source);
            $this->info("发现 {$found} 个新图库。");

            return self::SUCCESS;
        }

        DiscoverMediaGalleries::dispatchSync($source->id);

        $this->info("存储源 {$source->name} 处理完成。");

        return self::SUCCESS;
    }

    private function scanSingleGallery(int $galleryId, bool $force): int
    {
        $gallery = MediaGallery::find($galleryId);

        if ($gallery === null) {
            $this->error("图库 #{$galleryId} 不存在。");

            return self::FAILURE;
        }

        if ($force) {
            $gallery->update(['scan_status' => 'pending', 'locked_at' => null]);
        }

        $this->info("开始扫描图库: {$gallery->name}");

        ScanMediaGallery::dispatchSync($gallery->id);

        $this->info("图库 {$gallery->name} 扫描完成。");
        $this->info("素材数量: {$gallery->fresh()->media_count}");

        return self::SUCCESS;
    }

    private function scanAllSources(bool $discoverOnly): int
    {
        $sources = MediaStorageSource::enabled()->get();

        if ($sources->isEmpty()) {
            $this->warn('没有启用的存储源。');

            return self::SUCCESS;
        }

        $jobs = [];
        foreach ($sources as $source) {
            if ($discoverOnly) {
                $galleryService = app(\App\Services\Media\GalleryService::class);
                $found = $galleryService->discoverGalleries($source);
                $this->info("存储源 {$source->name}: 发现 {$found} 个新图库。");
            } else {
                $jobs[] = new DiscoverMediaGalleries($source->id);
            }
        }

        if ($jobs !== []) {
            Bus::batch($jobs)
                ->name('Media Scan - All Sources')
                ->dispatch();

            $this->info(count($jobs).' 个存储源扫描任务已派发。');
        }

        return self::SUCCESS;
    }
}
