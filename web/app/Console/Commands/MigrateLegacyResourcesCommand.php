<?php

namespace App\Console\Commands;

use App\Models\MediaGallery;
use App\Models\MediaResource;
use App\Models\MediaStorageSource;
use App\Services\Media\PathResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateLegacyResourcesCommand extends Command
{
    protected $signature = 'media:migrate-legacy
                            {--managed-source= : 目标 managed 存储源 ID，默认使用第一个 is_managed=true 的存储源}
                            {--gallery= : 目标图库 ID（必填，用于指定迁移目标图库）}
                            {--dry-run : 预览模式，不执行实际迁移（默认）}
                            {--execute : 执行真实迁移}
                            {--limit= : 每次处理数量，默认 50}
                            {--id= : 仅迁移指定 ID 的 legacy 资源}';

    protected $description = 'Legacy 资源迁移到 managed 存储源（默认 --dry-run 预览，--execute 真实执行）。';

    public function handle(PathResolverService $pathResolver): int
    {
        $isDryRun = ! $this->option('execute');

        if ($isDryRun) {
            $this->warn('=== DRY RUN 模式：不会修改任何数据 ===');
        } else {
            $this->warn('=== EXECUTE 模式：将真实迁移文件与数据库记录 ===');
            if (! $this->confirm('确认执行迁移？此操作不可撤销。')) {
                $this->info('已取消。');

                return self::SUCCESS;
            }
        }

        $galleryId = $this->option('gallery');
        if (! $galleryId) {
            $this->error('必须指定 --gallery=ID（目标图库）。');

            return self::FAILURE;
        }

        $gallery = MediaGallery::find($galleryId);
        if (! $gallery) {
            $this->error("图库 ID={$galleryId} 不存在。");

            return self::FAILURE;
        }

        $source = $gallery->storageSource;
        if (! $source || ! $source->is_managed) {
            $this->error('目标图库必须属于 managed 存储源。');

            return self::FAILURE;
        }

        if ($source->is_read_only || ! $source->isWritable()) {
            $this->error('目标存储源不可写。');

            return self::FAILURE;
        }

        $query = MediaResource::query()->whereNull('storage_source_id');

        if ($this->option('id')) {
            $query->where('id', $this->option('id'));
        }

        $limit = (int) ($this->option('limit') ?: 50);
        $total = $query->count();

        $this->info("待迁移 legacy 资源: {$total} 个");
        $this->info("目标: source={$source->name}(ID={$source->id}), gallery={$gallery->name}(ID={$gallery->id})");
        $this->info("root_path={$source->root_path}, gallery relative_path={$gallery->relative_path}");

        if ($total === 0) {
            $this->info('没有需要迁移的 legacy 资源。');

            return self::SUCCESS;
        }

        $disk = Storage::disk('local');
        $migrated = 0;
        $skipped = 0;
        $errors = 0;

        $query->chunk($limit, function ($resources) use ($disk, $pathResolver, $source, $gallery, $isDryRun, &$migrated, &$skipped, &$errors) {
            foreach ($resources as $resource) {
                $this->line('');
                $this->line("--- 资源 #{$resource->id}: {$resource->title} ---");

                $legacyOriginalPath = $disk->path($resource->originalRelativePath());
                $this->line("  旧原件: {$legacyOriginalPath}");

                if (! is_file($legacyOriginalPath)) {
                    $this->warn("  跳过: 原件不存在");
                    $skipped++;

                    continue;
                }

                $currentChecksum = hash_file('sha256', $legacyOriginalPath);
                $currentSize = filesize($legacyOriginalPath);

                if ($resource->checksum_sha256 && $resource->checksum_sha256 !== $currentChecksum) {
                    $this->warn("  校验和不匹配: DB={$resource->checksum_sha256}, file={$currentChecksum}");
                }

                if ((int) $resource->size_bytes !== $currentSize) {
                    $this->warn("  文件大小不一致: DB={$resource->size_bytes}, file={$currentSize}");
                }

                $galleryFullPath = rtrim($source->root_path, '/').'/'.ltrim($gallery->relative_path, '/');
                $newRelativePath = "migrated/{$resource->id}/original.{$resource->original_ext}";
                $newTargetDir = $galleryFullPath.'/migrated/'.$resource->id;
                $newAbsPath = $newTargetDir.'/original.'.$resource->original_ext;

                $this->line("  新原件: {$newAbsPath}");
                $this->line("  新 relative_path: {$newRelativePath}");

                if ($isDryRun) {
                    $this->info("  [DRY RUN] 将会: 复制文件 + 更新 DB 记录");
                    $migrated++;

                    continue;
                }

                if (! is_dir($newTargetDir)) {
                    @mkdir($newTargetDir, 0775, true);
                }

                if (! @copy($legacyOriginalPath, $newAbsPath)) {
                    $this->error("  复制文件失败");
                    $errors++;

                    continue;
                }

                $resource->update([
                    'storage_source_id' => $source->id,
                    'gallery_id' => $gallery->id,
                    'relative_path' => $newRelativePath,
                    'source_size_bytes' => $currentSize,
                    'source_modified_at' => now(),
                    'source_identity' => hash('sha256', $newRelativePath.'|'.$currentSize.'|'.filemtime($newAbsPath)),
                    'storage_dir' => null,
                ]);

                $legacyThumbPath = $disk->path($resource->thumbRelativePath());
                $legacyPreviewPath = $disk->path($resource->previewRelativePath());

                $this->line("  已迁移，旧文件保留: {$legacyOriginalPath}");

                $migrated++;
            }
        });

        $this->line('');
        $this->line(str_repeat('-', 40));
        $verb = $isDryRun ? '[DRY RUN] ' : '';
        $this->info("{$verb}完成: migrated={$migrated}, skipped={$skipped}, errors={$errors}");

        if ($isDryRun && $migrated > 0) {
            $this->line('');
            $this->warn('这是预览结果。使用 --execute 执行真实迁移。');
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
