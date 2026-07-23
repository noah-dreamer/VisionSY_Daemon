<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 只读预检查：检测媒体资源身份冲突（同一图库内重复的 relative_path）。
 *
 * 在添加唯一约束（迁移 2026_07_22_000040）之前运行，确认没有会被约束拒绝的重复数据。
 * 本命令不修改任何数据。
 */
class CheckMediaIdentityConflictsCommand extends Command
{
    protected $signature = 'media:identity-conflicts {--limit=50 : 最多展示的冲突组数}';

    protected $description = '只读检测同一图库内重复的 relative_path（资源身份冲突）';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $conflicts = DB::table('media_resources')
            ->select('gallery_id', 'relative_path', DB::raw('COUNT(*) as duplicates'))
            ->whereNotNull('gallery_id')
            ->whereNotNull('relative_path')
            ->groupBy('gallery_id', 'relative_path')
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('duplicates')
            ->limit($limit)
            ->get();

        if ($conflicts->isEmpty()) {
            $this->info('未发现资源身份冲突。可以安全添加唯一约束。');

            return self::SUCCESS;
        }

        $this->error("发现 {$conflicts->count()} 组身份冲突（同一图库内重复 relative_path）：");
        $this->newLine();

        foreach ($conflicts as $conflict) {
            $this->line(sprintf(
                '  gallery_id=%s  relative_path=%s  重复 %d 次',
                $conflict->gallery_id,
                $conflict->relative_path,
                $conflict->duplicates
            ));

            $ids = DB::table('media_resources')
                ->where('gallery_id', $conflict->gallery_id)
                ->where('relative_path', $conflict->relative_path)
                ->pluck('id')
                ->implode(', ');
            $this->line("    涉及 resource id: {$ids}");
        }

        $this->newLine();
        $this->warn('请人工核对并处理上述冲突（不要静默删除/合并/覆盖），处理后再运行迁移添加唯一约束。');

        return self::FAILURE;
    }
}
