<?php

namespace App\Console\Commands;

use App\Models\MediaResource;
use Illuminate\Console\Command;

class LegacyResourceStatsCommand extends Command
{
    protected $signature = 'media:legacy-stats';

    protected $description = '审计所有 legacy 资源（storage_source_id IS NULL），只读，不修改数据。';

    public function handle(): int
    {
        $total = MediaResource::query()->whereNull('storage_source_id')->count();
        $managed = MediaResource::query()->whereNull('storage_source_id')->where('is_system_managed', true)->count();
        $external = MediaResource::query()->whereNull('storage_source_id')->where('is_system_managed', false)->count();
        $missing = MediaResource::query()->whereNull('storage_source_id')->where('index_status', 'missing')->count();
        $hasFile = MediaResource::query()->whereNull('storage_source_id')->where('has_thumb', true)->count();
        $totalSize = MediaResource::query()->whereNull('storage_source_id')->sum('size_bytes');

        $this->table(
            ['指标', '值'],
            [
                ['Legacy 资源总数', $total],
                ['系统管理（上传）', $managed],
                ['外部索引（扫描）', $external],
                ['标记 Missing', $missing],
                ['有缩略图', $hasFile],
                ['总大小 (bytes)', number_format((int) $totalSize)],
            ]
        );

        $types = MediaResource::query()
            ->whereNull('storage_source_id')
            ->selectRaw('type, COUNT(*) as cnt')
            ->groupBy('type')
            ->get();

        if ($types->isNotEmpty()) {
            $this->line('');
            $this->info('按类型分布:');
            foreach ($types as $row) {
                $this->line("  {$row->type}: {$row->cnt}");
            }
        }

        $dirs = MediaResource::query()
            ->whereNull('storage_source_id')
            ->whereNotNull('storage_dir')
            ->selectRaw('SUBSTR(storage_dir, 1, 4) as prefix, COUNT(*) as cnt')
            ->groupBy('prefix')
            ->orderBy('prefix')
            ->get();

        if ($dirs->isNotEmpty()) {
            $this->line('');
            $this->info('Storage dir 前缀分布:');
            foreach ($dirs as $row) {
                $this->line("  {$row->prefix}: {$row->cnt}");
            }
        }

        return self::SUCCESS;
    }
}
