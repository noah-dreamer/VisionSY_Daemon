<?php

namespace App\Console\Commands;

use App\Enums\StorageSourceType;
use App\Models\MediaStorageSource;
use App\Services\Media\GalleryService;
use Illuminate\Console\Command;

class InitManagedSourceCommand extends Command
{
    protected $signature = 'media:init-managed-source
                            {--root= : managed 根路径，默认从配置读取}
                            {--name= : 存储源名称，默认 "VisionSY Managed"}';

    protected $description = '初始化默认媒体目录（幂等，扫描和上传共用）。';

    public function handle(): int
    {
        $name = $this->option('name') ?: '媒体库';
        $root = $this->option('root') ?: config('visionsy.media.managed_root', '/data/visionsy/managed');

        $existing = MediaStorageSource::query()
            ->where('root_path', $root)
            ->first();

        if ($existing) {
            app(GalleryService::class)->ensureRootGallery($existing);
            $this->info("媒体目录已存在: ID={$existing->id}, name={$existing->name}");

            return self::SUCCESS;
        }

        if (! is_dir($root)) {
            @mkdir($root, 0775, true);
            $this->line("创建目录: {$root}");
        }

        $source = MediaStorageSource::create([
            'name' => $name,
            'driver' => StorageSourceType::Local->value,
            'root_path' => $root,
            'is_enabled' => true,
            'is_read_only' => false,
            'is_managed' => true,
            'scan_recursive' => true,
            'created_by' => null,
        ]);
        app(GalleryService::class)->ensureRootGallery($source);

        $this->info("媒体目录已创建: ID={$source->id}, name={$name}, root={$root}");

        return self::SUCCESS;
    }
}
