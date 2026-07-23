<?php

namespace Tests\Feature;

use App\Enums\MediaStatus;
use App\Enums\StorageSourceType;
use App\Models\MediaGallery;
use App\Models\MediaResource;
use App\Models\MediaStorageSource;
use App\Services\Media\DerivativeVariant;
use App\Services\Media\PathResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class MediaPathRefactoringTest extends TestCase
{
    use RefreshDatabase;

    private MediaStorageSource $managedSource;
    private MediaStorageSource $externalSource;
    private MediaGallery $managedGallery;
    private MediaGallery $externalGallery;
    private string $managedRoot;
    private string $derivativesRoot;

    private function normalizedManagedRoot(): string
    {
        return str_replace('\\', '/', realpath($this->managedRoot) ?: $this->managedRoot);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->managedRoot = realpath(sys_get_temp_dir()).'/visionsy-test-managed-'.bin2hex(random_bytes(4));
        $this->derivativesRoot = realpath(sys_get_temp_dir()).'/visionsy-test-derivatives-'.bin2hex(random_bytes(4));

        config(['visionsy.media.managed_root' => $this->managedRoot]);
        config(['visionsy.media.derivatives_root' => $this->derivativesRoot]);

        @mkdir($this->managedRoot, 0775, true);
        @mkdir($this->derivativesRoot, 0775, true);

        $this->managedSource = MediaStorageSource::create([
            'name' => 'Test Managed',
            'driver' => StorageSourceType::Local->value,
            'root_path' => $this->managedRoot,
            'is_enabled' => true,
            'is_read_only' => false,
            'is_managed' => true,
        ]);

        $this->externalSource = MediaStorageSource::create([
            'name' => 'Test External',
            'driver' => StorageSourceType::Local->value,
            'root_path' => $this->managedRoot.'/external',
            'is_enabled' => true,
            'is_read_only' => false,
            'is_managed' => false,
        ]);
        @mkdir($this->externalSource->root_path, 0775, true);

        $this->managedGallery = MediaGallery::create([
            'storage_source_id' => $this->managedSource->id,
            'relative_path' => 'test-gallery',
            'name' => 'Test Gallery',
            'is_managed' => true,
            'is_visible' => true,
            'scan_status' => 'pending',
        ]);
        @mkdir($this->managedRoot.'/test-gallery', 0775, true);

        $this->externalGallery = MediaGallery::create([
            'storage_source_id' => $this->externalSource->id,
            'relative_path' => 'ext-gallery',
            'name' => 'External Gallery',
            'is_managed' => false,
            'is_visible' => true,
            'scan_status' => 'pending',
        ]);
        @mkdir($this->externalSource->root_path.'/ext-gallery', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->managedRoot);
        $this->deleteTree($this->derivativesRoot);
        parent::tearDown();
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $path = $dir.'/'.$item;
            is_dir($path) && ! is_link($path) ? $this->deleteTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function createLegacyResource(): MediaResource
    {
        $resource = MediaResource::create([
            'title' => 'Legacy Resource',
            'type' => \App\Enums\MediaType::Image,
            'status' => MediaStatus::Active,
            'index_status' => MediaStatus::Active,
            'original_name' => 'legacy.jpg',
            'original_ext' => 'jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 1024,
            'checksum_sha256' => hash('sha256', 'legacy-content'),
            'storage_dir' => 'ab/cd/legacy-ulid',
            'has_thumb' => true,
            'has_preview' => true,
            'is_system_managed' => true,
        ]);

        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        $dir = dirname($disk->path($resource->originalRelativePath()));
        @mkdir($dir, 0775, true);
        file_put_contents($disk->path($resource->originalRelativePath()), 'legacy-content');
        file_put_contents($disk->path($resource->thumbRelativePath()), 'thumb-content');
        file_put_contents($disk->path($resource->previewRelativePath()), 'preview-content');

        return $resource;
    }

    private function createManagedResource(string $title = 'Managed Resource'): MediaResource
    {
        $relativePath = 'aa/bb/'.bin2hex(random_bytes(4)).'/original.jpg';
        $targetDir = $this->managedRoot.'/test-gallery/'.dirname($relativePath);
        @mkdir($targetDir, 0775, true);
        file_put_contents($this->managedRoot.'/test-gallery/'.$relativePath, 'managed-content');
        $content = 'managed-content';

        return MediaResource::create([
            'title' => $title,
            'type' => \App\Enums\MediaType::Image,
            'status' => MediaStatus::Active,
            'index_status' => MediaStatus::Active,
            'original_name' => 'managed.jpg',
            'original_ext' => 'jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => strlen($content),
            'checksum_sha256' => hash('sha256', $content),
            'storage_source_id' => $this->managedSource->id,
            'gallery_id' => $this->managedGallery->id,
            'relative_path' => $relativePath,
            'source_size_bytes' => strlen($content),
            'source_modified_at' => now(),
            'source_identity' => hash('sha256', $relativePath.'|'.strlen($content).'|'.filemtime($this->managedRoot.'/test-gallery/'.$relativePath)),
            'is_system_managed' => true,
            'derivative_status' => 'pending',
        ]);
    }

    /** @test */
    public function new_upload_has_source_and_gallery(): void
    {
        $file = UploadedFile::fake()->image('test.jpg');
        $storage = app(\App\Services\Media\MediaStorageService::class);

        $resource = $storage->store($file, [
            'title' => 'Test Upload',
            'storage_source_id' => $this->managedSource->id,
            'gallery_id' => $this->managedGallery->id,
        ], \App\Models\User::factory()->create());

        $this->assertNotNull($resource->storage_source_id);
        $this->assertNotNull($resource->gallery_id);
        $this->assertNotNull($resource->relative_path);
        $this->assertFalse($resource->isLegacy());
        $this->assertEquals($this->managedSource->id, $resource->storage_source_id);
        $this->assertEquals($this->managedGallery->id, $resource->gallery_id);

        $pathResolver = app(PathResolverService::class);
        $absPath = $pathResolver->resolveOriginalAbsolute($resource);
        $this->assertStringStartsWith($this->normalizedManagedRoot(), str_replace('\\', '/', realpath($absPath) ?: $absPath));
        $this->assertFileExists($absPath);
    }

    /** @test */
    public function legacy_resource_is_readable(): void
    {
        $resource = $this->createLegacyResource();
        $pathResolver = app(PathResolverService::class);

        $original = $pathResolver->resolveOriginalAbsolute($resource);
        $this->assertFileExists($original);
        $this->assertStringContainsString('legacy-content', file_get_contents($original));

        $thumb = $pathResolver->resolveDerivativeAbsolute($resource, DerivativeVariant::Thumb);
        $this->assertFileExists($thumb);
    }

    /** @test */
    public function derivative_written_to_independent_root(): void
    {
        $resource = $this->createManagedResource();
        $pathResolver = app(PathResolverService::class);

        $newPath = $pathResolver->resolveNewDerivativeAbsolute($resource, DerivativeVariant::Thumb);
        $this->assertStringStartsWith(str_replace('\\', '/', $this->derivativesRoot), str_replace('\\', '/', $newPath));
        $this->assertStringNotContainsString('_visionsy', $newPath);

        $expectedPrefix = str_replace('\\', '/', $this->derivativesRoot).'/'
            .$resource->storage_source_id.'/'
            .$resource->gallery_id.'/'
            .$resource->id.'/';
        $this->assertStringStartsWith($expectedPrefix, $newPath);
    }

    /** @test */
    public function derivative_falls_back_to_legacy_path(): void
    {
        $resource = $this->createManagedResource();
        $gallery = $resource->gallery;

        $legacyDerivativeDir = $this->managedRoot.'/'.$gallery->relative_path
            .'/_visionsy/preview/thumb';
        @mkdir($legacyDerivativeDir, 0775, true);
        $legacyDerivativePath = $legacyDerivativeDir.'/'.$resource->relative_path.'.jpg';
        @mkdir(dirname($legacyDerivativePath), 0775, true);
        file_put_contents($legacyDerivativePath, 'legacy-derivative');

        $pathResolver = app(PathResolverService::class);
        $path = $pathResolver->resolveDerivativeAbsolute($resource, DerivativeVariant::Thumb);
        $this->assertFileExists($path);
        $this->assertStringContainsString('_visionsy', $path);
    }

    /** @test */
    public function derivative_new_path_preferred_over_legacy(): void
    {
        $resource = $this->createManagedResource();

        $pathResolver = app(PathResolverService::class);
        $newPath = $pathResolver->resolveNewDerivativeAbsolute($resource, DerivativeVariant::Thumb);

        @mkdir(dirname($newPath), 0775, true);
        file_put_contents($newPath, 'new-derivative');

        $path = $pathResolver->resolveDerivativeAbsolute($resource, DerivativeVariant::Thumb);
        $this->assertEquals($newPath, $path);
        $this->assertStringNotContainsString('_visionsy', $path);
    }

    /** @test */
    public function managed_path_is_unique_per_resource(): void
    {
        $r1 = $this->createManagedResource('Resource 1');
        $r2 = $this->createManagedResource('Resource 2');

        $pathResolver = app(PathResolverService::class);
        $p1 = $pathResolver->resolveNewDerivativeAbsolute($r1, DerivativeVariant::Thumb);
        $p2 = $pathResolver->resolveNewDerivativeAbsolute($r2, DerivativeVariant::Thumb);

        $this->assertNotEquals($p1, $p2);
    }

    /** @test */
    public function different_galleries_same_name_independent(): void
    {
        $gallery2 = MediaGallery::create([
            'storage_source_id' => $this->managedSource->id,
            'relative_path' => 'gallery-2',
            'name' => 'Gallery 2',
            'is_managed' => true,
            'is_visible' => true,
            'scan_status' => 'pending',
        ]);
        @mkdir($this->managedRoot.'/gallery-2', 0775, true);

        $r1 = $this->createManagedResource('Same Name G1');
        $r2 = MediaResource::create([
            'title' => 'Same Name G2',
            'type' => \App\Enums\MediaType::Image,
            'status' => MediaStatus::Active,
            'index_status' => MediaStatus::Active,
            'original_name' => 'same.jpg',
            'original_ext' => 'jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 100,
            'checksum_sha256' => hash('sha256', 'g2-content'),
            'storage_source_id' => $this->managedSource->id,
            'gallery_id' => $gallery2->id,
            'relative_path' => $r1->relative_path,
            'is_system_managed' => true,
        ]);

        $pathResolver = app(PathResolverService::class);
        $p1 = $pathResolver->resolveNewDerivativeAbsolute($r1, DerivativeVariant::Thumb);
        $p2 = $pathResolver->resolveNewDerivativeAbsolute($r2, DerivativeVariant::Thumb);

        $this->assertNotEquals($p1, $p2);
    }

    /** @test */
    public function allowed_roots_config_is_read(): void
    {
        $roots = config('visionsy.media.allowed_roots');
        $this->assertIsArray($roots);
        $this->assertContains('/data/visionsy/managed', $roots);
    }

    /** @test */
    public function managed_root_config_is_read(): void
    {
        $this->assertEquals($this->managedRoot, config('visionsy.media.managed_root'));
    }

    /** @test */
    public function derivatives_root_config_is_read(): void
    {
        $this->assertEquals($this->derivativesRoot, config('visionsy.media.derivatives_root'));
    }

    /** @test */
    public function path_resolver_rejects_null_bytes(): void
    {
        $this->expectException(\RuntimeException::class);
        $pathResolver = app(PathResolverService::class);
        $pathResolver->normalizePath("valid\0evil");
    }

    /** @test */
    public function path_resolver_rejects_dot_dot(): void
    {
        $this->expectException(\RuntimeException::class);
        $pathResolver = app(PathResolverService::class);
        $pathResolver->normalizePath('../etc/passwd');
    }

    /** @test */
    public function path_resolver_handles_windows_separators(): void
    {
        $pathResolver = app(PathResolverService::class);
        $normalized = $pathResolver->normalizePath('foo\bar\baz');
        $this->assertEquals('foo/bar/baz', $normalized);
    }

    /** @test */
    public function path_resolver_handles_chinese_characters(): void
    {
        $pathResolver = app(PathResolverService::class);
        $normalized = $pathResolver->normalizePath('相册/2024/测试.jpg');
        $this->assertEquals('相册/2024/测试.jpg', $normalized);
    }

    /** @test */
    public function managed_source_initialization_is_idempotent(): void
    {
        $first = MediaStorageSource::query()->where('is_managed', true)->first();
        $this->assertNotNull($first);

        $count = MediaStorageSource::query()->where('is_managed', true)->count();
        $this->assertEquals(1, $count, 'Managed source init should be idempotent');
    }

    /** @test */
    public function legacy_resource_dry_run_does_not_mutate_data(): void
    {
        $resource = $this->createLegacyResource();
        $this->assertTrue($resource->isLegacy());

        $originalSourceId = $resource->storage_source_id;
        $originalGalleryId = $resource->gallery_id;
        $originalStorageDir = $resource->storage_dir;

        $this->artisan('media:migrate-legacy', [
            '--gallery' => $this->managedGallery->id,
            '--dry-run' => true,
            '--limit' => 1,
        ])->assertSuccessful();

        $resource->refresh();
        $this->assertEquals($originalSourceId, $resource->storage_source_id);
        $this->assertEquals($originalGalleryId, $resource->gallery_id);
        $this->assertEquals($originalStorageDir, $resource->storage_dir);
    }

    /** @test */
    public function managed_upload_rejects_readonly_source(): void
    {
        $this->managedSource->update(['is_read_only' => true]);

        $file = UploadedFile::fake()->image('test.jpg');
        $storage = app(\App\Services\Media\MediaStorageService::class);

        $this->expectException(\RuntimeException::class);
        $storage->store($file, [
            'title' => 'Test',
            'storage_source_id' => $this->managedSource->id,
            'gallery_id' => $this->managedGallery->id,
        ], \App\Models\User::factory()->create());
    }

    /** @test */
    public function external_readonly_source_derivative_still_generated(): void
    {
        $this->externalSource->update(['is_read_only' => true]);

        $relativePath = 'ext/file.jpg';
        @mkdir(dirname($this->externalSource->root_path.'/ext-gallery/'.$relativePath), 0775, true);
        file_put_contents($this->externalSource->root_path.'/ext-gallery/'.$relativePath, 'ext-content');

        $resource = MediaResource::create([
            'title' => 'External Resource',
            'type' => \App\Enums\MediaType::Image,
            'status' => MediaStatus::Active,
            'index_status' => MediaStatus::Active,
            'original_name' => 'ext.jpg',
            'original_ext' => 'jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 11,
            'checksum_sha256' => hash('sha256', 'ext-content'),
            'storage_source_id' => $this->externalSource->id,
            'gallery_id' => $this->externalGallery->id,
            'relative_path' => $relativePath,
            'is_system_managed' => false,
            'derivative_status' => 'pending',
        ]);

        $pathResolver = app(PathResolverService::class);
        $path = $pathResolver->resolveNewDerivativeAbsolute($resource, DerivativeVariant::Thumb);
        $this->assertStringStartsWith(str_replace('\\', '/', $this->derivativesRoot), str_replace('\\', '/', $path));
        $this->assertStringStartsWith(str_replace('\\', '/', $this->derivativesRoot), str_replace('\\', '/', $path));
    }

    /** @test */
    public function derivative_directory_auto_created(): void
    {
        $resource = $this->createManagedResource();
        $pathResolver = app(PathResolverService::class);

        $pathResolver->ensureDerivativeDir($resource);

        $base = $this->derivativesRoot.'/'
            .$resource->storage_source_id.'/'
            .$resource->gallery_id.'/'
            .$resource->id;

        $this->assertDirectoryExists($base);
    }
}
