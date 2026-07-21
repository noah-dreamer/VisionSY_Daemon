<?php

namespace Tests\Feature;

use App\Enums\MediaStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Media\PathResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GalleryScanTest extends TestCase
{
    use RefreshDatabase;

    private string $testRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testRoot = sys_get_temp_dir().'/visionsy-scan-'.bin2hex(random_bytes(8));
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
            is_dir($path) ? $this->cleanDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function createTestSourceAndGallery(): array
    {
        $admin = User::factory()->systemAdmin()->create();

        // Create images to scan
        $photosDir = $this->testRoot.'/2024-Test-Gallery';
        mkdir($photosDir, 0775, true);
        $subDir = $photosDir.'/DCIM';
        mkdir($subDir, 0775, true);

        $img1 = $subDir.'/IMG_0001.jpg';
        $this->createTestImage($img1, 800, 600);

        $img2 = $subDir.'/IMG_0002.jpg';
        $this->createTestImage($img2, 1200, 800);

        // Create storage source
        $this->actingAs($admin)
            ->post(route('admin.storage-sources.store'), [
                'name' => 'Scan Source',
                'driver' => 'local',
                'root_path' => $this->testRoot,
                'is_enabled' => '1',
                'is_writable' => '1',
                'is_managed' => '0',
                'scan_recursive' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('media_storage_sources', [
            'name' => 'Scan Source',
            'driver' => 'local',
            'root_path' => realpath($this->testRoot),
        ]);

        $source = \App\Models\MediaStorageSource::where('name', 'Scan Source')->first();

        return [$source, $admin, $photosDir];
    }

    private function createTestImage(string $path, int $width, int $height): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('PHP GD 扩展不可用。');
        }

        $img = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($img, 100, 150, 200);
        imagefilledrectangle($img, 0, 0, $width, $height, $color);
        imagejpeg($img, $path, 90);
        imagedestroy($img);
    }

    /* ---------------- 图库发现 ---------------- */

    public function test_first_level_folders_are_discovered_as_galleries(): void
    {
        [$source, $admin] = $this->createTestSourceAndGallery();

        $galleryService = app(\App\Services\Media\GalleryService::class);
        $found = $galleryService->discoverGalleries($source);

        $this->assertGreaterThanOrEqual(1, $found);
        $this->assertDatabaseHas('media_galleries', [
            'storage_source_id' => $source->id,
            'relative_path' => '2024-Test-Gallery',
            'is_managed' => false,
        ]);
    }

    public function test_visionsy_directory_is_ignored_in_discovery(): void
    {
        $admin = User::factory()->systemAdmin()->create();

        $visionsyDir = $this->testRoot.'/'.PathResolverService::DERIVATIVE_DIR;
        mkdir($visionsyDir, 0775, true);

        $normalDir = $this->testRoot.'/Normal-Gallery';
        mkdir($normalDir, 0775, true);
        $img = $normalDir.'/test.jpg';
        $this->createTestImage($img, 100, 100);

        $this->actingAs($admin)
            ->post(route('admin.storage-sources.store'), [
                'name' => 'Ignore Source',
                'driver' => 'local',
                'root_path' => $this->testRoot,
                'is_enabled' => '1',
                'is_writable' => '1',
                'is_managed' => '0',
                'scan_recursive' => '1',
            ])
            ->assertSessionHasNoErrors();

        $source = \App\Models\MediaStorageSource::where('name', 'Ignore Source')->first();
        $galleryService = app(\App\Services\Media\GalleryService::class);
        $galleryService->discoverGalleries($source);

        $this->assertDatabaseHas('media_galleries', [
            'storage_source_id' => $source->id,
            'relative_path' => 'Normal-Gallery',
        ]);
        $this->assertDatabaseMissing('media_galleries', [
            'storage_source_id' => $source->id,
            'relative_path' => PathResolverService::DERIVATIVE_DIR,
        ]);
    }

    public function test_hidden_directories_are_ignored(): void
    {
        $admin = User::factory()->systemAdmin()->create();

        $hiddenDir = $this->testRoot.'/.hidden-gallery';
        mkdir($hiddenDir, 0775, true);
        $normalDir = $this->testRoot.'/Visible-Gallery';
        mkdir($normalDir, 0775, true);

        $this->actingAs($admin)
            ->post(route('admin.storage-sources.store'), [
                'name' => 'Hidden Source',
                'driver' => 'local',
                'root_path' => $this->testRoot,
                'is_enabled' => '1',
                'is_writable' => '1',
                'is_managed' => '0',
                'scan_recursive' => '1',
            ])
            ->assertSessionHasNoErrors();

        $source = \App\Models\MediaStorageSource::where('name', 'Hidden Source')->first();
        $galleryService = app(\App\Services\Media\GalleryService::class);
        $galleryService->discoverGalleries($source);

        $this->assertDatabaseHas('media_galleries', ['storage_source_id' => $source->id, 'relative_path' => 'Visible-Gallery']);
        $this->assertDatabaseMissing('media_galleries', ['storage_source_id' => $source->id, 'relative_path' => '.hidden-gallery']);
    }

    /* ---------------- 文件扫描 ---------------- */

    public function test_files_in_gallery_are_indexed_on_scan(): void
    {
        [$source, $admin] = $this->createTestSourceAndGallery();

        $galleryService = app(\App\Services\Media\GalleryService::class);
        $galleryService->discoverGalleries($source);

        $gallery = \App\Models\MediaGallery::where('relative_path', '2024-Test-Gallery')->first();
        $this->assertNotNull($gallery);

        $scanner = app(\App\Services\Media\MediaScannerService::class);
        $result = $scanner->scanGallery($gallery);

        $this->assertGreaterThanOrEqual(2, $result['indexed']);

        $resources = \App\Models\MediaResource::where('gallery_id', $gallery->id)->get();
        $this->assertCount(2, $resources);

        foreach ($resources as $resource) {
            $this->assertEquals(MediaStatus::Active, $resource->index_status);
            $this->assertNotNull($resource->relative_path);
            $this->assertNotNull($resource->source_identity);
        }
    }

    public function test_duplicate_scan_is_idempotent(): void
    {
        [$source, $admin] = $this->createTestSourceAndGallery();

        $galleryService = app(\App\Services\Media\GalleryService::class);
        $galleryService->discoverGalleries($source);

        $gallery = \App\Models\MediaGallery::where('relative_path', '2024-Test-Gallery')->first();
        $scanner = app(\App\Services\Media\MediaScannerService::class);

        $result1 = $scanner->scanGallery($gallery);
        $count1 = \App\Models\MediaResource::where('gallery_id', $gallery->id)->count();

        $result2 = $scanner->scanGallery($gallery);
        $count2 = \App\Models\MediaResource::where('gallery_id', $gallery->id)->count();

        $this->assertEquals($count1, $count2);
        $this->assertEquals(0, $result2['indexed']);
    }

    public function test_new_files_are_indexed_on_rescan(): void
    {
        [$source, $admin] = $this->createTestSourceAndGallery();

        $galleryService = app(\App\Services\Media\GalleryService::class);
        $galleryService->discoverGalleries($source);

        $gallery = \App\Models\MediaGallery::where('relative_path', '2024-Test-Gallery')->first();
        $scanner = app(\App\Services\Media\MediaScannerService::class);

        $scanner->scanGallery($gallery);
        $count1 = \App\Models\MediaResource::where('gallery_id', $gallery->id)->count();

        $newFile = $gallery->fullPath().'/DCIM/IMG_0003.jpg';
        $this->createTestImage($newFile, 400, 300);

        $scanner->scanGallery($gallery);
        $count2 = \App\Models\MediaResource::where('gallery_id', $gallery->id)->count();

        $this->assertEquals($count1 + 1, $count2);
    }

    public function test_missing_files_are_marked_not_deleted(): void
    {
        [$source, $admin] = $this->createTestSourceAndGallery();

        $galleryService = app(\App\Services\Media\GalleryService::class);
        $galleryService->discoverGalleries($source);

        $gallery = \App\Models\MediaGallery::where('relative_path', '2024-Test-Gallery')->first();
        $scanner = app(\App\Services\Media\MediaScannerService::class);

        $scanner->scanGallery($gallery);
        $count = \App\Models\MediaResource::where('gallery_id', $gallery->id)
            ->where('index_status', '!=', MediaStatus::Missing->value)
            ->count();

        $fileToRemove = $gallery->fullPath().'/DCIM/IMG_0001.jpg';
        @unlink($fileToRemove);

        $scanner->scanGallery($gallery);

        $missingCount = \App\Models\MediaResource::where('gallery_id', $gallery->id)
            ->where('index_status', MediaStatus::Missing->value)
            ->count();
        $activeCount = \App\Models\MediaResource::where('gallery_id', $gallery->id)
            ->where('index_status', MediaStatus::Active->value)
            ->count();

        $this->assertEquals(1, $missingCount);
        $this->assertEquals($count - 1, $activeCount);
        $this->assertFileDoesNotExist($fileToRemove);
    }

    public function test_recovered_files_clear_missing_status(): void
    {
        [$source, $admin] = $this->createTestSourceAndGallery();

        $galleryService = app(\App\Services\Media\GalleryService::class);
        $galleryService->discoverGalleries($source);

        $gallery = \App\Models\MediaGallery::where('relative_path', '2024-Test-Gallery')->first();
        $scanner = app(\App\Services\Media\MediaScannerService::class);

        $scanner->scanGallery($gallery);

        $filePath = $gallery->fullPath().'/DCIM/IMG_0001.jpg';
        @unlink($filePath);

        $scanner->scanGallery($gallery);

        $this->createTestImage($filePath, 800, 600);

        $scanner->scanGallery($gallery);

        $activeCount = \App\Models\MediaResource::where('gallery_id', $gallery->id)
            ->where('index_status', MediaStatus::Active->value)
            ->count();

        $this->assertEquals(2, $activeCount);
    }

    public function test_readonly_source_cannot_create_gallery_directory(): void
    {
        $admin = User::factory()->systemAdmin()->create();

        $this->actingAs($admin)
            ->post(route('admin.storage-sources.store'), [
                'name' => 'RO Source',
                'driver' => 'local',
                'root_path' => $this->testRoot,
                'is_enabled' => '1',
                'is_writable' => '0',
                'is_managed' => '0',
                'scan_recursive' => '1',
            ])
            ->assertSessionHasNoErrors();

        $source = \App\Models\MediaStorageSource::where('name', 'RO Source')->first();

        $this->actingAs($admin)
            ->post(route('admin.galleries.store'), [
                'storage_source_id' => $source->id,
                'name' => 'Should Fail',
                'folder_name' => 'should-fail',
                'is_visible' => '1',
            ])
            ->assertSessionHasErrors();
    }

    public function test_admin_created_gallery_creates_physical_directory(): void
    {
        $admin = User::factory()->systemAdmin()->create();

        $this->actingAs($admin)
            ->post(route('admin.storage-sources.store'), [
                'name' => 'Writable Source',
                'driver' => 'local',
                'root_path' => $this->testRoot,
                'is_enabled' => '1',
                'is_writable' => '1',
                'is_managed' => '1',
                'scan_recursive' => '1',
            ])
            ->assertSessionHasNoErrors();

        $source = \App\Models\MediaStorageSource::where('name', 'Writable Source')->first();

        $this->actingAs($admin)
            ->post(route('admin.galleries.store'), [
                'storage_source_id' => $source->id,
                'name' => 'Managed Gallery',
                'folder_name' => 'managed-gallery',
                'description' => 'A managed gallery',
                'tags' => 'test, managed',
                'event_started_at' => '2024-06-01T10:00',
                'event_ended_at' => '2024-06-03T18:00',
                'is_visible' => '1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.galleries.index'));

        $this->assertDirectoryExists($this->testRoot.'/managed-gallery');

        $this->assertDatabaseHas('media_galleries', [
            'relative_path' => 'managed-gallery',
            'name' => 'Managed Gallery',
            'is_managed' => true,
        ]);
    }

    /* ---------------- 安全性测试 ---------------- */

    public function test_visionsy_in_subdirectory_is_ignored_during_scan(): void
    {
        [$source, $admin] = $this->createTestSourceAndGallery();

        $galleryService = app(\App\Services\Media\GalleryService::class);
        $galleryService->discoverGalleries($source);

        $gallery = \App\Models\MediaGallery::where('relative_path', '2024-Test-Gallery')->first();

        $visionsySub = $gallery->fullPath().'/DCIM/'.PathResolverService::DERIVATIVE_DIR;
        mkdir($visionsySub, 0775, true);
        $badFile = $visionsySub.'/should-be-ignored.jpg';
        $this->createTestImage($badFile, 100, 100);

        $scanner = app(\App\Services\Media\MediaScannerService::class);
        $result = $scanner->scanGallery($gallery);

        $this->assertEquals(2, $result['indexed'], 'Only the 2 real files should be indexed');
        $this->assertDatabaseMissing('media_resources', [
            'relative_path' => 'DCIM/' . PathResolverService::DERIVATIVE_DIR . '/should-be-ignored.jpg',
        ]);

        @unlink($badFile);
        @rmdir($visionsySub);
    }

    public function test_symlinks_are_skipped_during_scan(): void
    {
        $admin = User::factory()->systemAdmin()->create();

        $photosDir = $this->testRoot.'/Symlink-Test-Gallery';
        mkdir($photosDir, 0775, true);

        $normalFile = $photosDir.'/real.jpg';
        $this->createTestImage($normalFile, 100, 100);

        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('符号链接测试需要 Linux 环境。');
        }

        $outsideDir = $this->testRoot.'/outside-gallery';
        mkdir($outsideDir, 0775, true);
        $outsideFile = $outsideDir.'/outside.jpg';
        $this->createTestImage($outsideFile, 200, 200);

        $symlinkFile = $photosDir.'/link-to-outside.jpg';
        symlink($outsideFile, $symlinkFile);

        $symlinkDir = $photosDir.'/link-dir';
        symlink($outsideDir, $symlinkDir);

        try {
            $this->actingAs($admin)
                ->post(route('admin.storage-sources.store'), [
                    'name' => 'Symlink Source',
                    'driver' => 'local',
                    'root_path' => $this->testRoot,
                    'is_enabled' => '1',
                    'is_writable' => '1',
                    'is_managed' => '0',
                    'scan_recursive' => '1',
                ])
                ->assertSessionHasNoErrors();

            $source = \App\Models\MediaStorageSource::where('name', 'Symlink Source')->first();

            $galleryService = app(\App\Services\Media\GalleryService::class);
            $galleryService->discoverGalleries($source);

            $gallery = \App\Models\MediaGallery::where('relative_path', 'Symlink-Test-Gallery')->first();
            $this->assertNotNull($gallery);

            $scanner = app(\App\Services\Media\MediaScannerService::class);
            $result = $scanner->scanGallery($gallery);

            $this->assertEquals(1, $result['indexed'], 'Only real file should be indexed, symlinks excluded');

            $resources = \App\Models\MediaResource::where('gallery_id', $gallery->id)
                ->where('index_status', \App\Enums\MediaStatus::Active->value)
                ->get();
            $this->assertCount(1, $resources);
        } finally {
            @unlink($symlinkFile);
            @unlink($symlinkDir);
            @unlink($outsideFile);
            @rmdir($outsideDir);
        }
    }

    public function test_files_with_chinese_space_and_special_chars_are_scanned(): void
    {
        $admin = User::factory()->systemAdmin()->create();

        $galleryDir = $this->testRoot.'/特殊字符测试';
        mkdir($galleryDir, 0775, true);
        $subDir = $galleryDir.'/照片 #2024 & 活动';
        mkdir($subDir, 0775, true);

        $img1 = $subDir.'/IMG_0001.jpg';
        $this->createTestImage($img1, 100, 100);

        $img2 = $subDir.'/文件 名称.jpg';
        $this->createTestImage($img2, 200, 200);

        $this->actingAs($admin)
            ->post(route('admin.storage-sources.store'), [
                'name' => 'Unicode Source',
                'driver' => 'local',
                'root_path' => $this->testRoot,
                'is_enabled' => '1',
                'is_writable' => '1',
                'is_managed' => '0',
                'scan_recursive' => '1',
            ])
            ->assertSessionHasNoErrors();

        $source = \App\Models\MediaStorageSource::where('name', 'Unicode Source')->first();

        $galleryService = app(\App\Services\Media\GalleryService::class);
        $found = $galleryService->discoverGalleries($source);
        $this->assertGreaterThanOrEqual(1, $found);

        $gallery = \App\Models\MediaGallery::where('relative_path', '特殊字符测试')->first();
        $this->assertNotNull($gallery, '中文目录名应被正确发现');

        $scanner = app(\App\Services\Media\MediaScannerService::class);
        $result = $scanner->scanGallery($gallery);
        $this->assertEquals(2, $result['indexed']);

        $resources = \App\Models\MediaResource::where('gallery_id', $gallery->id)
            ->where('index_status', \App\Enums\MediaStatus::Active->value)
            ->get();
        $this->assertCount(2, $resources);

        foreach ($resources as $resource) {
            $this->assertNotNull($resource->relative_path);
            $this->assertNotNull($resource->source_identity);
        }
    }

    public function test_hidden_files_are_excluded_during_scan(): void
    {
        [$source, $admin] = $this->createTestSourceAndGallery();
        $this->assertNotNull($source);

        $galleryService = app(\App\Services\Media\GalleryService::class);
        $galleryService->discoverGalleries($source);

        $gallery = \App\Models\MediaGallery::where('relative_path', '2024-Test-Gallery')->first();

        $hiddenFile = $gallery->fullPath().'/DCIM/.hidden.jpg';
        $this->createTestImage($hiddenFile, 100, 100);

        $scanner = app(\App\Services\Media\MediaScannerService::class);
        $result = $scanner->scanGallery($gallery);

        $this->assertEquals(2, $result['indexed'], 'Hidden files should not be indexed');

        @unlink($hiddenFile);
    }
}
