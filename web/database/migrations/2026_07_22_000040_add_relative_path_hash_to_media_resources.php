<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 为媒体资源增加图库内身份唯一保护。
 *
 * 资源在图库内以 (gallery_id, relative_path) 为唯一身份。relative_path 为 varchar(1024)，
 * 在 MySQL/MariaDB（utf8mb4，4 字节/字符）下直接建唯一索引会超过 InnoDB 3072 字节索引上限，
 * 因此改用 relative_path_hash（SHA-256，char(64)）+ 唯一索引 (gallery_id, relative_path_hash) 作为等价保护。
 *
 * MariaDB/MySQL 与 SQLite 差异：
 *  - MySQL/MariaDB 的 DDL 非事务性（隐式提交），因此先做只读冲突预检查，再变更结构；
 *    一旦发现冲突立即抛出，避免“加了列/索引却失败”的半完成状态。
 *  - SQLite 支持 DDL 事务，且无索引长度限制；char(64) 与唯一索引行为一致。
 *  - legacy 资源 gallery_id / relative_path 为 NULL，哈希列为 NULL；
 *    MySQL 与 SQLite 的唯一索引均允许多个 NULL，不会互相冲突。
 *
 * 本迁移不删除、合并或覆盖任何数据；存在冲突时中止（回滚），由人工处理后再迁移。
 */
return new class extends Migration
{
    private const UNIQUE_INDEX = 'media_resources_gallery_path_hash_unique';

    public function up(): void
    {
        // 1) 变更结构前先做只读冲突预检查（MySQL DDL 非事务，必须先检查）。
        //    relative_path 重复 ⟺ relative_path_hash 重复（哈希是确定性函数）。
        $conflicts = DB::table('media_resources')
            ->select('gallery_id', 'relative_path', DB::raw('COUNT(*) as duplicates'))
            ->whereNotNull('gallery_id')
            ->whereNotNull('relative_path')
            ->groupBy('gallery_id', 'relative_path')
            ->havingRaw('COUNT(*) > 1')
            ->limit(50)
            ->get();

        if ($conflicts->isNotEmpty()) {
            $detail = $conflicts->map(fn ($c) => "gallery_id={$c->gallery_id} relative_path={$c->relative_path} x{$c->duplicates}")
                ->implode('; ');

            throw new RuntimeException(
                '检测到媒体资源身份冲突（同一图库内重复 relative_path），无法安全添加唯一约束。'
                .'请先人工处理冲突后再迁移。示例: '.$detail
            );
        }

        // 2) 新增哈希列（纯新增，可回滚）。
        Schema::table('media_resources', function (Blueprint $table) {
            $table->char('relative_path_hash', 64)->nullable()->after('relative_path');
        });

        // 3) 回填已有数据（与 App\Models\MediaResource::hashRelativePath 保持一致）。
        DB::table('media_resources')
            ->whereNotNull('relative_path')
            ->select('id', 'relative_path')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('media_resources')
                        ->where('id', $row->id)
                        ->update([
                            'relative_path_hash' => hash('sha256', str_replace('\\', '/', $row->relative_path)),
                        ]);
                }
            });

        // 4) 添加唯一索引。
        Schema::table('media_resources', function (Blueprint $table) {
            $table->unique(['gallery_id', 'relative_path_hash'], self::UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('media_resources', function (Blueprint $table) {
            $table->dropUnique(self::UNIQUE_INDEX);
        });

        Schema::table('media_resources', function (Blueprint $table) {
            $table->dropColumn('relative_path_hash');
        });
    }
};
