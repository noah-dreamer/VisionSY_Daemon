<?php

namespace Tests\Feature;

use App\Enums\DerivativeStatus;
use App\Enums\MediaStatus;
use App\Enums\UserRole;
use App\Models\MediaResource;
use App\Models\User;
use App\Services\Media\DerivativeVariant;
use App\Services\Media\PathResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DerivativeGenerationTest extends TestCase
{
    use RefreshDatabase;

    private string $testRoot;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('gd')) {
            $this->markTestSkipped('PHP GD 扩展不可用。');
        }

        $this->testRoot = sys_get_temp_dir().'/visionsy-deriv-'.bin2hex(random_bytes(8));
        mkdir($this->testRoot, 0775, true);
        config()->set('visionsy.media.allowed_roots', [$this->testRoot]);
        Storage::fake('local');
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

    private function createTestImage(string $path, int $width, int $height): void
    {
        $img = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($img, 100, 150, 200);
        imagefilledrectangle($img, 0, 0, $width, $height, $color);
        imagejpeg($img, $path, 90);
        imagedestroy($img);
    }

    private function createTestDngFile(string $path): void
    {
        $data = str_repeat("\x00", 512);
        $data .= "DNG\x00";
        $data .= str_repeat("\x00", 1024);

        $thumbW = 256;
        $thumbH = 256;
        $thumb = imagecreatetruecolor($thumbW, $thumbH);
        $c = imagecolorallocate($thumb, 200, 100, 50);
        imagefilledrectangle($thumb, 0, 0, $thumbW, $thumbH, $c);
        ob_start();
        imagejpeg($thumb, null, 95);
        $thumbData = ob_get_clean();
        imagedestroy($thumb);

        $data .= "\xff\xd8\xff\xe1";
        $lenLo = strlen($thumbData) + 2;
        $data .= chr(($lenLo >> 8) & 0xff).chr($lenLo & 0xff);
        $data .= "Exif\x00\x00";
        $data .= $thumbData;

        $data .= str_repeat("\x00", 4096);

        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, $data);
    }

    private function createUploadedJpeg(string $path, int $w, int $h): void
    {
        $this->createTestImage($path, $w, $h);
    }

    /* ---------------- JPEG 派生图生成 ---------------- */

    public function test_jpeg_upload_generates_all_four_derivative_types(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $galleryDir = $this->testRoot.'/Deriv-Gallery';
        mkdir($galleryDir, 0775, true);

        $this->actingAs($admin)
            ->post(route('admin.storage-sources.store'), [
                'name' => 'Deriv Source',
                'driver' => 'local',
                'root_path' => $this->testRoot,
                'is_enabled' => '1',
                'is_writable' => '1',
                'is_managed' => '0',
                'scan_recursive' => '1',
            ])
            ->assertSessionHasNoErrors();

        $source = \App\Models\MediaStorageSource::where('name', 'Deriv Source')->first();

        $galleryService = app(\App\Services\Media\GalleryService::class);
        $galleryService->discoverGalleries($source);

        $gallery = \App\Models\MediaGallery::where('relative_path', 'Deriv-Gallery')->first();
        $this->assertNotNull($gallery);

        $testFile = $galleryDir.'/test-photo.jpg';
        $this->createUploadedJpeg($testFile, 1200, 800);

        $scanner = app(\App\Services\Media\MediaScannerService::class);
        $result = $scanner->scanGallery($gallery);
        $this->assertEquals(1, $result['indexed']);

        $resource = \App\Models\MediaResource::where('gallery_id', $gallery->id)->first();
        $this->assertNotNull($resource);

        \App\Jobs\Media\GenerateMediaDerivatives::dispatchSync($resource->id);

        $resource->refresh();
        $this->assertEquals(DerivativeStatus::Completed->value, $resource->derivative_status);
        $this->assertTrue($resource->has_thumb);
        $this->assertTrue($resource->has_preview);
        $this->assertNotNull($resource->width);
        $this->assertNotNull($resource->height);

        $pathResolver = app(PathResolverService::class);
        $this->assertFileExists($pathResolver->resolveDerivativeAbsolute($resource, DerivativeVariant::Thumb));
        $this->assertFileExists($pathResolver->resolveDerivativeAbsolute($resource, DerivativeVariant::Large));
        $this->assertFileExists($pathResolver->resolveDerivativeAbsolute($resource, DerivativeVariant::WatermarkedJpeg));
        $this->assertFileExists($pathResolver->resolveDerivativeAbsolute($resource, DerivativeVariant::WatermarkedPng));
    }

    /* ---------------- RAW 识别与处理 ---------------- */

    public function test_raw_file_is_identified_as_is_raw_true(): void
    {
        // 模拟 ExifTool 返回预期结果
        $admin = User::factory()->systemAdmin()->create();
        $galleryDir = $this->testRoot.'/Raw-Gallery';
        mkdir($galleryDir, 0775, true);

        $this->actingAs($admin)
            ->post(route('admin.storage-sources.store'), [
                'name' => 'Raw Source',
                'driver' => 'local',
                'root_path' => $this->testRoot,
                'is_enabled' => '1',
                'is_writable' => '1',
                'is_managed' => '0',
                'scan_recursive' => '1',
            ])
            ->assertSessionHasNoErrors();

        $source = \App\Models\MediaStorageSource::where('name', 'Raw Source')->first();

        $galleryService = app(\App\Services\Media\GalleryService::class);
        $galleryService->discoverGalleries($source);

        $gallery = \App\Models\MediaGallery::where('relative_path', 'Raw-Gallery')->first();
        $this->assertNotNull($gallery);

        $rawPath = $galleryDir.'/IMG_0001.CR3';
        $this->createTestDngFile($rawPath);

        $scanner = app(\App\Services\Media\MediaScannerService::class);
        $result = $scanner->scanGallery($gallery);
        $this->assertEquals(1, $result['indexed']);

        $resource = \App\Models\MediaResource::where('gallery_id', $gallery->id)->first();
        $this->assertNotNull($resource);
        $this->assertTrue($resource->is_raw, 'RAW 文件应被标记为 is_raw=true');
        $this->assertEquals('image', $resource->type->value);
    }

    /* ---------------- RAW 转换失败不阻塞 ---------------- */

    public function test_raw_conversion_failure_does_not_block_indexing(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $galleryDir = $this->testRoot.'/RawFail-Gallery';
        mkdir($galleryDir, 0775, true);

        $this->actingAs($admin)
            ->post(route('admin.storage-sources.store'), [
                'name' => 'RawFail Source',
                'driver' => 'local',
                'root_path' => $this->testRoot,
                'is_enabled' => '1',
                'is_writable' => '1',
                'is_managed' => '0',
                'scan_recursive' => '1',
            ])
            ->assertSessionHasNoErrors();

        $source = \App\Models\MediaStorageSource::where('name', 'RawFail Source')->first();

        $galleryService = app(\App\Services\Media\GalleryService::class);
        $galleryService->discoverGalleries($source);

        $gallery = \App\Models\MediaGallery::where('relative_path', 'RawFail-Gallery')->first();
        $this->assertNotNull($gallery);

        // 创建一个没有任何内嵌 JPEG 预览的文件（无效 RAW 模拟）
        $badRawPath = $galleryDir.'/IMG_BAD.CR3';
        file_put_contents($badRawPath, str_repeat("\x00", 1024));

        $scanner = app(\App\Services\Media\MediaScannerService::class);
        $result = $scanner->scanGallery($gallery);
        $this->assertEquals(1, $result['indexed']);

        $resource = \App\Models\MediaResource::where('gallery_id', $gallery->id)->first();
        $this->assertNotNull($resource);
        $this->assertTrue($resource->is_raw);

        \App\Jobs\Media\GenerateMediaDerivatives::dispatchSync($resource->id);

        $resource->refresh();
        $this->assertEquals(DerivativeStatus::Failed->value, $resource->derivative_status,
            'RAW 转换失败时应标记 derivative_status=failed');
        $this->assertNotNull($resource->derivative_error,
            'RAW 转换失败时应记录 derivative_error');

        // 文件仍然可以被扫描和引用
        $this->assertEquals(MediaStatus::Active->value, $resource->index_status);
    }

    /* ---------------- 下载原图字节一致性 ---------------- */

    public function test_download_original_returns_identical_bytes(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $galleryDir = $this->testRoot.'/Download-Gallery';
        mkdir($galleryDir, 0775, true);

        $this->actingAs($admin)
            ->post(route('admin.storage-sources.store'), [
                'name' => 'Download Source',
                'driver' => 'local',
                'root_path' => $this->testRoot,
                'is_enabled' => '1',
                'is_writable' => '1',
                'is_managed' => '0',
                'scan_recursive' => '1',
            ])
            ->assertSessionHasNoErrors();

        $source = \App\Models\MediaStorageSource::where('name', 'Download Source')->first();

        $galleryService = app(\App\Services\Media\GalleryService::class);
        $galleryService->discoverGalleries($source);

        $gallery = \App\Models\MediaGallery::where('relative_path', 'Download-Gallery')->first();
        $this->assertNotNull($gallery);

        $testFile = $galleryDir.'/original-file.jpg';
        $originalBytes = str_repeat("\x00\x01\x02\x03", 256)."\xff\xd8\xff\xe0\x00\x10JFIF\x00";
        $originalBytes .= str_repeat("\x00\x01\xff\xd9", 243);
        file_put_contents($testFile, $originalBytes);

        $scanner = app(\App\Services\Media\MediaScannerService::class);
        $result = $scanner->scanGallery($gallery);
        $this->assertEquals(1, $result['indexed']);

        $resource = \App\Models\MediaResource::where('gallery_id', $gallery->id)->first();
        $this->assertNotNull($resource);

        $this->actingAs($admin)
            ->get(route('media.download.original', $resource))
            ->assertOk()
            ->assertHeader('Content-Type', $resource->mime);

        ob_start();
        $this->actingAs($admin)
            ->get(route('media.download.original', $resource))
            ->sendContent();
        $downloaded = ob_get_clean();

        $this->assertEquals($originalBytes, $downloaded, '下载的原文件应与磁盘上的一致');
    }

    /* ---------------- GPS EXIF 剥离 ---------------- */

    public function test_derivatives_do_not_contain_gps_exif(): void
    {
        $exifToolAvailable = $this->isExifToolAvailable();

        $admin = User::factory()->systemAdmin()->create();
        $galleryDir = $this->testRoot.'/Gps-Gallery';
        mkdir($galleryDir, 0775, true);

        $this->actingAs($admin)
            ->post(route('admin.storage-sources.store'), [
                'name' => 'GPS Source',
                'driver' => 'local',
                'root_path' => $this->testRoot,
                'is_enabled' => '1',
                'is_writable' => '1',
                'is_managed' => '0',
                'scan_recursive' => '1',
            ])
            ->assertSessionHasNoErrors();

        $source = \App\Models\MediaStorageSource::where('name', 'GPS Source')->first();

        $galleryService = app(\App\Services\Media\GalleryService::class);
        $galleryService->discoverGalleries($source);

        $gallery = \App\Models\MediaGallery::where('relative_path', 'Gps-Gallery')->first();
        $this->assertNotNull($gallery);

        $testFile = $galleryDir.'/gps-photo.jpg';
        $this->createUploadedJpeg($testFile, 800, 600);

        $scanner = app(\App\Services\Media\MediaScannerService::class);
        $result = $scanner->scanGallery($gallery);
        $this->assertEquals(1, $result['indexed']);

        $resource = \App\Models\MediaResource::where('gallery_id', $gallery->id)->first();
        \App\Jobs\Media\GenerateMediaDerivatives::dispatchSync($resource->id);

        $resource->refresh();
        $this->assertEquals(DerivativeStatus::Completed->value, $resource->derivative_status);

        $pathResolver = app(PathResolverService::class);

        $thumbPath = $pathResolver->resolveDerivativeAbsolute($resource, DerivativeVariant::Thumb);
        $this->assertFileExists($thumbPath);

        if ($exifToolAvailable) {
            $thumbExif = @exif_read_data($thumbPath);
            $this->assertNotFalse($thumbExif, '缩略图应是有效的 JPEG');
            if (is_array($thumbExif)) {
                $this->assertArrayNotHasKey('GPSLatitude', $thumbExif, '衍生图不应包含 GPS 信息');
                $this->assertArrayNotHasKey('GPSLongitude', $thumbExif);
                $this->assertArrayNotHasKey('GPSLatitudeRef', $thumbExif);
                $this->assertArrayNotHasKey('GPSLongitudeRef', $thumbExif);
            }
        } else {
            $this->assertTrue(true);
        }
    }

    /* ---------------- 唯一锁 ---------------- */

    public function test_concurrent_derivative_generation_only_runs_once(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $galleryDir = $this->testRoot.'/Lock-Gallery';
        mkdir($galleryDir, 0775, true);

        $this->actingAs($admin)
            ->post(route('admin.storage-sources.store'), [
                'name' => 'Lock Source',
                'driver' => 'local',
                'root_path' => $this->testRoot,
                'is_enabled' => '1',
                'is_writable' => '1',
                'is_managed' => '0',
                'scan_recursive' => '1',
            ])
            ->assertSessionHasNoErrors();

        $source = \App\Models\MediaStorageSource::where('name', 'Lock Source')->first();

        $galleryService = app(\App\Services\Media\GalleryService::class);
        $galleryService->discoverGalleries($source);

        $gallery = \App\Models\MediaGallery::where('relative_path', 'Lock-Gallery')->first();

        $testFile = $galleryDir.'/lock-test.jpg';
        $this->createUploadedJpeg($testFile, 400, 400);

        $scanner = app(\App\Services\Media\MediaScannerService::class);
        $scanner->scanGallery($gallery);

        $resource = \App\Models\MediaResource::where('gallery_id', $gallery->id)->first();

        $job1 = new \App\Jobs\Media\GenerateMediaDerivatives($resource->id);
        $job2 = new \App\Jobs\Media\GenerateMediaDerivatives($resource->id);

        $this->assertEquals($job1->uniqueId(), $job2->uniqueId(),
            '同一资源的两个派生任务应有相同的唯一 ID');

        \App\Jobs\Media\GenerateMediaDerivatives::dispatchSync($resource->id);

        $resource->refresh();
        $this->assertEquals(DerivativeStatus::Completed->value, $resource->derivative_status);

        $pathResolver = app(PathResolverService::class);
        $this->assertFileExists($pathResolver->resolveDerivativeAbsolute($resource, DerivativeVariant::Thumb));
        $this->assertFileExists($pathResolver->resolveDerivativeAbsolute($resource, DerivativeVariant::Large));
    }

    /* ---------------- 工具 ---------------- */

    private function isExifToolAvailable(): bool
    {
        try {
            $process = new \Symfony\Component\Process\Process(['exiftool', '-ver']);
            $process->setTimeout(5);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }
}
