<?php

namespace Tests\Feature;

use App\Jobs\Media\DiscoverMediaGalleries;
use App\Models\MediaGallery;
use App\Models\MediaResource;
use App\Models\MediaStorageSource;
use App\Models\User;
use App\Services\Media\DerivativeVariant;
use App\Services\Media\GalleryService;
use App\Services\Media\MediaScannerService;
use App\Services\Media\MediaStorageService;
use App\Services\Media\PathResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class UnifiedMediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private MediaStorageSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/visionsy-library-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0775, true);
        config(['visionsy.media.allowed_roots' => [$this->root]]);
        $this->source = MediaStorageSource::create([
            'name' => '统一图库', 'driver' => 'local', 'root_path' => $this->root,
            'is_enabled' => true, 'is_read_only' => false,
            'is_managed' => false, 'scan_recursive' => true,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function image(string $relative, int $width = 40): void
    {
        $path = $this->root.'/'.$relative;
        File::ensureDirectoryExists(dirname($path));
        $image = imagecreatetruecolor($width, 30);
        imagefilledrectangle($image, 0, 0, $width, 30, imagecolorallocate($image, 10, 90, 160));
        imagejpeg($image, $path);
        imagedestroy($image);
    }

    public function test_root_files_and_subfolders_are_scanned_once_with_previews_beside_originals(): void
    {
        $this->image('loose.jpg');
        $this->image('相册/photo.jpg', 50);
        $this->image('preview/ignored.jpg');
        $this->image('相册/preview/ignored.jpg');
        $this->image('相册/nested/preview/ignored.jpg');
        $this->image('相册/.hidden/ignored.jpg');
        $this->image('相册/_visionsy/ignored.jpg');

        DiscoverMediaGalleries::dispatchSync($this->source->id);
        DiscoverMediaGalleries::dispatchSync($this->source->id);

        $this->assertSame(2, MediaResource::count());
        $this->assertSame(2, MediaGallery::count());
        foreach (MediaResource::with('gallery', 'storageSource')->get() as $resource) {
            $this->assertSame('ready', $resource->derivative_status);
            $resolver = app(PathResolverService::class);
            foreach (DerivativeVariant::cases() as $variant) {
                $path = $resolver->resolveDerivativeAbsolute($resource, $variant);
                $this->assertStringStartsWith($resource->gallery->fullPath().'/preview/', $path);
                $this->assertFileExists($path);
            }
        }
    }

    public function test_completed_galleries_are_rescanned_for_added_changed_and_removed_files(): void
    {
        $this->image('相册/first.jpg');
        DiscoverMediaGalleries::dispatchSync($this->source->id);
        $first = MediaResource::where('relative_path', 'first.jpg')->firstOrFail();
        $checksum = $first->checksum_sha256;

        $this->image('相册/first.jpg', 80);
        $this->image('相册/second.jpg', 60);
        clearstatcache();
        DiscoverMediaGalleries::dispatchSync($this->source->id);
        $this->assertSame(2, MediaResource::count());
        $this->assertNotSame($checksum, $first->fresh()->checksum_sha256);

        unlink($this->root.'/相册/first.jpg');
        DiscoverMediaGalleries::dispatchSync($this->source->id);
        $this->assertTrue($first->fresh()->isMissing());
        $this->assertFalse(MediaResource::where('relative_path', 'second.jpg')->firstOrFail()->isMissing());
    }

    public function test_discovered_gallery_accepts_uploads_and_rescans_keep_the_same_records(): void
    {
        $this->image('相册/photo.jpg');
        DiscoverMediaGalleries::dispatchSync($this->source->id);
        $gallery = MediaGallery::where('relative_path', '相册')->firstOrFail();
        $admin = User::factory()->systemAdmin()->create();
        $originalChecksum = hash_file('sha256', $this->root.'/相册/photo.jpg');

        $this->actingAs($admin)->get(route('media.create'))->assertOk()->assertSee('统一图库')->assertSee('相册');
        $this->post(route('media.store'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 70, 40),
            'title' => '网页上传', 'storage_source_id' => $this->source->id, 'gallery_id' => $gallery->id,
        ])->assertSessionHasNoErrors();
        $uploaded = MediaResource::where('title', '网页上传')->firstOrFail();
        $this->assertSame($gallery->id, $uploaded->gallery_id);
        $this->assertSame($originalChecksum, hash_file('sha256', $this->root.'/相册/photo.jpg'));
        $this->assertFileExists($this->root.'/相册/'.$uploaded->relative_path);

        app(MediaScannerService::class)->scanGallery($gallery);
        $this->assertSame(2, MediaResource::count());
        $this->assertFalse($uploaded->fresh()->isMissing());
        $this->assertSame('网页上传', $uploaded->fresh()->title);
    }

    public function test_upload_without_selection_uses_the_same_root_gallery_as_scanned_files(): void
    {
        $this->image('existing.jpg');
        DiscoverMediaGalleries::dispatchSync($this->source->id);
        $admin = User::factory()->systemAdmin()->create();
        $this->actingAs($admin)->post(route('media.store'), [
            'file' => UploadedFile::fake()->image('new.jpg', 65, 45), 'title' => '根目录上传',
        ])->assertSessionHasNoErrors();
        $rootGallery = MediaGallery::where('relative_path', '')->firstOrFail();
        app(MediaScannerService::class)->scanGallery($rootGallery);
        $this->assertSame(2, $rootGallery->mediaResources()->count());
        $this->assertSame(0, MediaResource::whereNull('storage_source_id')->count());
    }

    public function test_readonly_library_rejects_uploads_regardless_of_origin_flags(): void
    {
        $gallery = app(GalleryService::class)->ensureRootGallery($this->source);
        $this->source->update(['is_read_only' => true]);
        $this->actingAs(User::factory()->systemAdmin()->create())->post(route('media.store'), [
            'file' => UploadedFile::fake()->image('rejected.jpg'), 'title' => '不可上传',
            'storage_source_id' => $this->source->id, 'gallery_id' => $gallery->id,
        ])->assertSessionHasErrors('file');
        $this->assertSame(0, MediaResource::count());
    }

    public function test_authorized_deletion_of_a_scanned_file_uses_library_write_permission(): void
    {
        $this->image('existing.jpg');
        $this->image('keep.jpg', 60);
        DiscoverMediaGalleries::dispatchSync($this->source->id);
        $resource = MediaResource::where('relative_path', 'existing.jpg')->firstOrFail();
        app(MediaStorageService::class)->delete($resource, User::factory()->systemAdmin()->create());
        $this->assertFileDoesNotExist($this->root.'/existing.jpg');
        $this->assertFileExists($this->root.'/keep.jpg');
        $this->assertDatabaseMissing('media_resources', ['id' => $resource->id]);
    }

    public function test_preview_symlink_cannot_write_outside_the_library(): void
    {
        $gallery = app(GalleryService::class)->ensureRootGallery($this->source);
        $outside = $this->root.'-outside';
        mkdir($outside);
        symlink($outside, $this->root.'/preview');
        try {
            $resource = MediaResource::make(['relative_path' => 'test.jpg']);
            $resource->id = 999;
            $resource->setRelation('storageSource', $this->source);
            $resource->storage_source_id = $this->source->id;
            $resource->setRelation('gallery', $gallery);
            $this->expectException(\RuntimeException::class);
            app(PathResolverService::class)->resolveNewDerivativeAbsolute($resource, DerivativeVariant::Thumb);
        } finally {
            unlink($this->root.'/preview');
            rmdir($outside);
        }
    }

    public function test_old_independent_cache_remains_readable_and_rescan_builds_gallery_preview(): void
    {
        $this->image('existing.jpg');
        $oldRoot = $this->root.'-old-cache';
        config(['visionsy.media.derivatives_root' => $oldRoot]);
        $gallery = app(GalleryService::class)->ensureRootGallery($this->source);
        $resource = MediaResource::create([
            'storage_source_id' => $this->source->id, 'gallery_id' => $gallery->id,
            'relative_path' => 'existing.jpg', 'title' => '旧缓存素材', 'type' => 'image',
            'status' => 'active', 'index_status' => 'active', 'original_name' => 'existing.jpg',
            'original_ext' => 'jpg', 'mime' => 'image/jpeg', 'size_bytes' => filesize($this->root.'/existing.jpg'),
            'checksum_sha256' => hash_file('sha256', $this->root.'/existing.jpg'),
            'source_identity' => hash('sha256', 'existing.jpg|'.filesize($this->root.'/existing.jpg').'|'.filemtime($this->root.'/existing.jpg')),
            'source_size_bytes' => filesize($this->root.'/existing.jpg'),
            'source_modified_at' => Carbon::createFromTimestamp(filemtime($this->root.'/existing.jpg'), 'UTC'),
            'derivative_status' => 'ready',
        ]);
        try {
            $resolver = app(PathResolverService::class);
            $oldPath = $resolver->resolveIndependentDerivativePath($resource, DerivativeVariant::Thumb);
            File::ensureDirectoryExists(dirname($oldPath));
            file_put_contents($oldPath, 'old-cache');
            $this->assertSame($oldPath, $resolver->resolveDerivativeAbsolute($resource, DerivativeVariant::Thumb));

            app(MediaScannerService::class)->scanGallery($gallery);
            $path = $resolver->resolveDerivativeAbsolute($resource->fresh(), DerivativeVariant::Thumb);
            $this->assertStringStartsWith($this->root.'/preview/', $path);
            $this->assertFileExists($path);
            $this->assertFileExists($this->root.'/existing.jpg');
        } finally {
            File::deleteDirectory($oldRoot);
        }
    }
}
