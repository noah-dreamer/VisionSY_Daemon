<?php

namespace App\Console\Commands;

use App\Enums\StorageSourceType;
use App\Models\MediaStorageSource;
use Illuminate\Console\Command;

class InitManagedSourceCommand extends Command
{
    protected $signature = 'media:init-managed-source
                            {--root= : managed 根路径，默认从配置读取}
                            {--name= : 存储源名称，默认 "VisionSY Managed"}';

    protected $description = '初始化默认 managed 存储源（幂等）。';

    public function handle(): int
    {
        $name = $this->option('name') ?: 'VisionSY Managed';
        $root = $this->option('root') ?: config('visionsy.media.managed_root', '/data/visionsy/managed');

        $existing = MediaStorageSource::query()
            ->where('is_managed', true)
            ->where('root_path', $root)
            ->first();

        if ($existing) {
            $this->info("Managed 存储源已存在: ID={$existing->id}, name={$existing->name}");

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
            'scan_recursive' => false,
            'created_by' => null,
        ]);

        $this->info("Managed 存储源已创建: ID={$source->id}, name={$name}, root={$root}");

        return self::SUCCESS;
    }
}
