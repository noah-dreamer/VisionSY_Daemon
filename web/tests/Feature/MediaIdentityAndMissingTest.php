<?php

namespace Tests\Feature;

use App\Enums\MediaStatus;
use App\Jobs\Media\DiscoverMediaGalleries;
use App\Jobs\Media\GenerateMediaDerivatives;
use App\Jobs\Media\IndexMediaFile;
use App\Jobs\Media\ScanMediaGallery;
use App\Models\MediaGallery;
use App\Models\MediaResource;
use App\Models\MediaStorageSource;
use App\Services\Media\MediaScannerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 阶段 1：DAM 数据正确性（资源身份 / Missing / 扫描锁）与 Queue 基础修复测试。
 *
 * 使用 .txt 文件避免对 GD 的依赖（非图片类型不触发派生生成）。
 */
class MediaIdentityAndMissingTest extends TestCase
{
    use RefreshDatabase;

    private string $testRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testRoot = sys_get_temp_dir().'/visionsy-identity-'.bin2hex(random_bytes(8));
        mkdir($this->testRoot, 0775, true);
        config()->set('visionsy.media.allowed_roots', [$this->testRoot]);
    }

    protected function tearDown(): void
    {
        config(['visionsy.media.allowed_roots' => []]);
        $this->cleanDir($this->testRoot);
        parent::tearDown();
    }

    private function cleanDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) && ! is_link($path) ? $this->cleanDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function createSource(string $name = 'Src'): MediaStorageSource
    {
        return MediaStorageSource::create([
            'name' => $name,
            'driver' => 'local',
            'root_path' => str_replace('\\', '/', realpath($this->testRoot)),
            'is_enabled' => true,
            'is_read_only' => true,
            'is_managed' => false,
            'scan_recursive' => true,
        ]);
    }

    private function createGallery(MediaStorageSource $source, string $relativePath): MediaGallery
    {
        if (! is_dir($this->testRoot.'/'.$relativePath)) {
            mkdir($this->testRoot.'/'.$relativePath, 0775, true);
        }

        return MediaGallery::create([
            'storage_source_id' => $source->id,
            'relative_path' => $relativePath,
            'name' => $relativePath,
            'is_managed' => false,
            'is_visible' => true,
            'scan_status' => 'pending',
        ]);
    }

    private function putFile(MediaGallery $gallery, string $relativePath, string $content = 'data'): string
    {
        $abs = $this->testRoot.'/'.$gallery->relative_path.'/'.$relativePath;
        $dir = dirname($abs);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($abs, $content);

        return $abs;
    }

    private function makeResource(MediaGallery $gallery, string $relativePath, array $overrides = []): MediaResource
    {
        return MediaResource::create(array_merge([
            'storage_source_id' => $gallery->storage_source_id,
            'gallery_id' => $gallery->id,
            'relative_path' => $relativePath,
            'title' => 'T',
            'type' => 'other',
            'status' => 'active',
            'index_status' => 'active',
            'original_name' => basename($relativePath),
            'original_ext' => 'txt',
            'mime' => 'text/plain',
            'size_bytes' => 10,
            'checksum_sha256' => hash('sha256', $relativePath),
            'is_system_managed' => false,
        ], $overrides));
    }

    /* ---------------- 资源身份 ---------------- */

    public function test_same_relative_path_in_different_galleries_are_independent(): void
    {
        $source = $this->createSource();
        $galleryA = $this->createGallery($source, 'GalleryA');
        $galleryB = $this->createGallery($source, 'GalleryB');

        // 两个图库内存在相同相对路径 DCIM/IMG.txt
        $this->putFile($galleryA, 'DCIM/IMG.txt', 'A');
        $this->putFile($galleryB, 'DCIM/IMG.txt', 'B');

        $scanner = app(MediaScannerService::class);
        $scanner->scanGallery($galleryA);
        $scanner->scanGallery($galleryB);

        // 两个图库应各自拥有独立资源，互不覆盖
        $this->assertSame(1, MediaResource::where('gallery_id', $galleryA->id)->count());
        $this->assertSame(1, MediaResource::where('gallery_id', $galleryB->id)->count());
        $this->assertSame(2, MediaResource::query()->count());

        $resA = MediaResource::where('gallery_id', $galleryA->id)->first();
        $resB = MediaResource::where('gallery_id', $galleryB->id)->first();
        $this->assertSame('DCIM/IMG.txt', $resA->relative_path);
        $this->assertSame('DCIM/IMG.txt', $resB->relative_path);
        $this->assertEquals(MediaStatus::Active, $resA->index_status);
        $this->assertEquals(MediaStatus::Active, $resB->index_status);
        $this->assertNotSame($resA->id, $resB->id);
    }

    public function test_relative_path_hash_is_maintained_on_create(): void
    {
        $source = $this->createSource();
        $gallery = $this->createGallery($source, 'G');

        $resource = $this->makeResource($gallery, 'sub/a.txt');

        $this->assertSame(MediaResource::hashRelativePath('sub/a.txt'), $resource->relative_path_hash);
        $this->assertSame(hash('sha256', 'sub/a.txt'), $resource->relative_path_hash);
    }

    public function test_scanned_resource_persists_hash(): void
    {
        $source = $this->createSource();
        $gallery = $this->createGallery($source, 'G');
        $this->putFile($gallery, 'DCIM/IMG.txt');

        app(MediaScannerService::class)->scanGallery($gallery);

        $resource = MediaResource::where('gallery_id', $gallery->id)->first();
        $this->assertNotNull($resource->relative_path_hash);
        $this->assertSame(MediaResource::hashRelativePath('DCIM/IMG.txt'), $resource->relative_path_hash);
    }

    public function test_unique_constraint_prevents_duplicate_identity(): void
    {
        $source = $this->createSource();
        $gallery = $this->createGallery($source, 'G');

        $this->makeResource($gallery, 'dup.txt');

        $this->expectException(QueryException::class);
        $this->makeResource($gallery, 'dup.txt');
    }

    public function test_same_relative_path_allowed_across_different_galleries_by_constraint(): void
    {
        $source = $this->createSource();
        $galleryA = $this->createGallery($source, 'A');
        $galleryB = $this->createGallery($source, 'B');

        $this->makeResource($galleryA, 'same.txt');
        $second = $this->makeResource($galleryB, 'same.txt');

        $this->assertNotNull($second->id);
        $this->assertSame(2, MediaResource::query()->count());
    }

    /* ---------------- Missing ---------------- */

    public function test_empty_gallery_scan_marks_existing_resources_missing(): void
    {
        $source = $this->createSource();
        $gallery = $this->createGallery($source, 'G');
        $this->putFile($gallery, 'a.txt');
        $this->putFile($gallery, 'b.txt');

        $scanner = app(MediaScannerService::class);
        $scanner->scanGallery($gallery);
        $this->assertSame(2, MediaResource::where('gallery_id', $gallery->id)
            ->where('index_status', MediaStatus::Active->value)->count());

        // 清空图库（成功扫描空图库）
        @unlink($this->testRoot.'/G/a.txt');
        @unlink($this->testRoot.'/G/b.txt');

        $result = $scanner->scanGallery($gallery);

        $this->assertSame(2, $result['missing']);
        $this->assertSame(2, MediaResource::where('gallery_id', $gallery->id)
            ->where('index_status', MediaStatus::Missing->value)->count());
        $this->assertSame(0, $gallery->fresh()->media_count);
    }

    public function test_inaccessible_gallery_does_not_mark_missing(): void
    {
        $source = $this->createSource();
        $gallery = $this->createGallery($source, 'G');
        $this->putFile($gallery, 'a.txt');

        $scanner = app(MediaScannerService::class);
        $scanner->scanGallery($gallery);
        $this->assertSame(1, MediaResource::where('gallery_id', $gallery->id)
            ->where('index_status', MediaStatus::Active->value)->count());

        // 来源/目录不可访问（重命名使其消失）
        rename($this->testRoot.'/G', $this->testRoot.'/G_moved');

        try {
            $scanner->scanGallery($gallery);
            $this->fail('目录不可访问应抛出异常。');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('不可访问', $e->getMessage());
        } finally {
            rename($this->testRoot.'/G_moved', $this->testRoot.'/G');
        }

        // 不得批量标记 missing
        $this->assertSame(1, MediaResource::where('gallery_id', $gallery->id)
            ->where('index_status', MediaStatus::Active->value)->count());
        $this->assertSame(0, MediaResource::where('gallery_id', $gallery->id)
            ->where('index_status', MediaStatus::Missing->value)->count());
    }

    public function test_recovered_file_clears_missing(): void
    {
        $source = $this->createSource();
        $gallery = $this->createGallery($source, 'G');
        $abs = $this->putFile($gallery, 'a.txt');

        $scanner = app(MediaScannerService::class);
        $scanner->scanGallery($gallery);

        @unlink($abs);
        $scanner->scanGallery($gallery);
        $this->assertTrue(MediaResource::where('gallery_id', $gallery->id)->first()->isMissing());

        // 文件恢复
        file_put_contents($abs, 'data');
        $scanner->scanGallery($gallery);

        $resource = MediaResource::where('gallery_id', $gallery->id)->first();
        $this->assertFalse($resource->isMissing());
        $this->assertEquals(MediaStatus::Active, $resource->index_status);
        $this->assertNull($resource->missing_at);
    }

    public function test_is_missing_uses_enum_comparison(): void
    {
        $source = $this->createSource();
        $gallery = $this->createGallery($source, 'G');

        $resource = $this->makeResource($gallery, 'a.txt');
        $this->assertFalse($resource->isMissing());

        $resource->update(['index_status' => MediaStatus::Missing->value, 'missing_at' => now()]);
        $this->assertTrue($resource->fresh()->isMissing());

        $resource->update(['index_status' => MediaStatus::Active->value, 'missing_at' => null]);
        $this->assertFalse($resource->fresh()->isMissing());
    }

    /* ---------------- 扫描锁 ---------------- */

    public function test_scan_failure_releases_lock_and_marks_failed(): void
    {
        $source = $this->createSource();
        $gallery = $this->createGallery($source, 'LockFail');
        $this->putFile($gallery, 'a.txt');

        // 使目录不可访问，扫描会失败
        rename($this->testRoot.'/LockFail', $this->testRoot.'/LockFail_moved');

        try {
            ScanMediaGallery::dispatchSync($gallery->id);
            $this->fail('扫描失败应抛出异常。');
        } catch (\Throwable $e) {
            // 预期异常
        } finally {
            rename($this->testRoot.'/LockFail_moved', $this->testRoot.'/LockFail');
        }

        $gallery->refresh();
        $this->assertSame('failed', $gallery->scan_status);
        $this->assertNull($gallery->locked_at, '失败后扫描锁必须释放，图库不得停留在 scanning。');
    }

    public function test_scan_lock_stale_threshold_exceeds_job_timeout(): void
    {
        $scanTimeout = (new ScanMediaGallery(1))->timeout;

        $this->assertGreaterThan(
            $scanTimeout,
            MediaGallery::staleLockMinutes() * 60,
            '扫描锁 stale 阈值必须大于扫描 Job timeout，避免合法长扫描被并发重入。'
        );
    }

    public function test_concurrent_scan_is_blocked_by_lock(): void
    {
        $source = $this->createSource();
        $gallery = $this->createGallery($source, 'G');

        $this->assertTrue($gallery->acquireScanLock());
        $this->assertFalse($gallery->fresh()->acquireScanLock(), '已持有锁时第二次获取应失败。');

        $gallery->releaseScanLock();
        $this->assertTrue($gallery->fresh()->acquireScanLock());
    }

    /* ---------------- Queue timeout ---------------- */

    public function test_queue_retry_after_exceeds_longest_job_timeout(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');

        $jobs = [
            new ScanMediaGallery(1),
            new IndexMediaFile(1),
            new GenerateMediaDerivatives(1),
            new DiscoverMediaGalleries(1),
        ];
        $maxTimeout = max(array_map(fn ($j) => $j->timeout, $jobs));

        $this->assertGreaterThan(
            $maxTimeout,
            $retryAfter,
            "database 队列 retry_after ({$retryAfter}) 必须大于最长 Job timeout ({$maxTimeout})。"
        );
    }

    /* ---------------- 冲突预检查命令 ---------------- */

    public function test_identity_conflicts_command_reports_clean(): void
    {
        $source = $this->createSource();
        $gallery = $this->createGallery($source, 'G');
        $this->makeResource($gallery, 'a.txt');
        $this->makeResource($gallery, 'b.txt');

        $this->artisan('media:identity-conflicts')
            ->expectsOutputToContain('未发现资源身份冲突')
            ->assertExitCode(0);
    }

    public function test_identity_conflicts_command_detects_duplicates(): void
    {
        $source = $this->createSource();
        $gallery = $this->createGallery($source, 'G');

        // 绕过模型直接写入两条相同 (gallery_id, relative_path) 但不同 hash 的数据，
        // 模拟历史遗留冲突（唯一索引按 hash 区分，故可插入；命令按 relative_path 检测）。
        $base = [
            'storage_source_id' => $source->id,
            'gallery_id' => $gallery->id,
            'relative_path' => 'dup.txt',
            'title' => 'T',
            'type' => 'other',
            'original_name' => 'dup.txt',
            'original_ext' => 'txt',
            'mime' => 'text/plain',
            'size_bytes' => 10,
        ];
        DB::table('media_resources')->insert(array_merge($base, [
            'checksum_sha256' => hash('sha256', 'one'),
            'relative_path_hash' => hash('sha256', 'one'),
        ]));
        DB::table('media_resources')->insert(array_merge($base, [
            'checksum_sha256' => hash('sha256', 'two'),
            'relative_path_hash' => hash('sha256', 'two'),
        ]));

        $this->artisan('media:identity-conflicts')
            ->expectsOutputToContain('身份冲突')
            ->assertExitCode(1);
    }
}
