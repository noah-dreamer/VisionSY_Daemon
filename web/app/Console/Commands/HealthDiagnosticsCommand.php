<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class HealthDiagnosticsCommand extends Command
{
    protected $signature = 'visionsy:health-diagnostics';

    protected $description = 'Run production health diagnostics (DB, DAM mounts, queue, scheduler)';

    public function handle(): int
    {
        $allOk = true;

        $this->info('=== VisionSY Health Diagnostics ===');

        // 1. 数据库连接
        $this->line('');
        $this->info('[1/6] Database connectivity...');
        try {
            DB::connection()->getPdo();
            $this->info('  DB connection: OK');
        } catch (\Throwable $e) {
            $this->error("  DB connection: FAILED ({$e->getMessage()})");
            $allOk = false;
        }

        // 2. DAM 挂载点
        $this->line('');
        $this->info('[2/6] DAM mount points...');
        $mounts = [
            'managed' => config('visionsy.managed_root', '/data/visionsy/managed'),
            'derivatives' => config('visionsy.derivatives_root', '/data/visionsy/derivatives'),
        ];
        $allowedRoots = explode(',', config('visionsy.allowed_roots', ''));
        foreach ($allowedRoots as $i => $root) {
            $mounts["sources[{$i}]"] = $root;
        }

        foreach ($mounts as $label => $path) {
            if (! $path || ! is_dir($path)) {
                $this->warn("  {$label} ({$path}): NOT FOUND or not a directory");
                $allOk = false;
            } elseif (! is_readable($path)) {
                $this->warn("  {$label} ({$path}): NOT READABLE");
                $allOk = false;
            } else {
                $writable = is_writable($path) ? 'writable' : 'not writable';
                $this->info("  {$label} ({$path}): OK ({$writable})");
            }
        }

        // 3. 存储空间
        $this->line('');
        $this->info('[3/6] Storage space...');
        foreach (['/app/storage', '/data/visionsy/managed', '/data/visionsy/derivatives'] as $dir) {
            if (! is_dir($dir)) {
                $this->warn("  {$dir}: not found");

                continue;
            }
            $free = disk_free_space($dir);
            $total = disk_total_space($dir);
            $freeGb = round($free / 1024 / 1024 / 1024, 1);
            $totalGb = round($total / 1024 / 1024 / 1024, 1);
            $pct = round(($total - $free) / $total * 100, 1);
            if ($pct > 90) {
                $this->error("  {$dir}: {$freeGb}GB free / {$totalGb}GB total ({$pct}% used) - CRITICAL");
                $allOk = false;
            } elseif ($pct > 75) {
                $this->warn("  {$dir}: {$freeGb}GB free / {$totalGb}GB total ({$pct}% used) - WARNING");
            } else {
                $this->info("  {$dir}: {$freeGb}GB free / {$totalGb}GB total ({$pct}% used)");
            }
        }

        // 4. 失败队列任务
        $this->line('');
        $this->info('[4/6] Failed jobs...');
        $failedCount = DB::table('failed_jobs')->count();
        if ($failedCount > 0) {
            $this->warn("  Failed jobs: {$failedCount}");
            $recent = DB::table('failed_jobs')->orderBy('failed_at', 'desc')->limit(3)->get(['uuid', 'queue', 'failed_at']);
            foreach ($recent as $job) {
                $this->line("    - {$job->queue} / {$job->uuid} ({$job->failed_at})");
            }
        } else {
            $this->info('  Failed jobs: 0');
        }

        // 5. 迁移状态
        $this->line('');
        $this->info('[5/6] Migration status...');
        $migrations = DB::table('migrations')->count();
        $this->info("  Migrations applied: {$migrations}");

        // 6. 图库与资源概览
        $this->line('');
        $this->info('[6/6] Media overview...');
        $galleryCount = DB::table('media_galleries')->count();
        $resourceCount = DB::table('media_resources')->count();
        $missingCount = DB::table('media_resources')->where('index_status', 'missing')->count();
        $sourceCount = DB::table('media_storage_sources')->count();
        $this->info("  Storage sources: {$sourceCount}");
        $this->info("  Galleries: {$galleryCount}");
        $this->info("  Resources: {$resourceCount} (missing: {$missingCount})");

        $this->line('');
        if ($allOk) {
            $this->info('=== All checks passed ===');

            return 0;
        }

        $this->warn('=== Some checks failed (see above) ===');

        return 1;
    }
}
