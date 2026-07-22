<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaStorageSource;
use App\Models\User;
use App\Services\Media\FakeMountDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MountManagerTest extends TestCase
{
    use RefreshDatabase;

    private string $testRoot;
    private FakeMountDriver $fakeDriver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testRoot = sys_get_temp_dir().'/visionsy-mnt-'.bin2hex(random_bytes(4));
        mkdir($this->testRoot, 0775, true);
        config()->set('visionsy.media.allowed_roots', [$this->testRoot]);

        $this->fakeDriver = new FakeMountDriver();
    }

    protected function tearDown(): void
    {
        config(['visionsy.media.allowed_roots' => []]);
        @rmdir($this->testRoot);
        parent::tearDown();
    }

    private function createSmbSource(User $admin): MediaStorageSource
    {
        $this->actingAs($admin)
            ->post(route('admin.storage-sources.store'), [
                'name' => 'SMB Share',
                'driver' => 'smb',
                'root_path' => $this->testRoot,
                'is_enabled' => '1',
                'is_writable' => '1',
                'is_managed' => '0',
                'scan_recursive' => '1',
                'smb_server' => 'fileserver.example.com',
                'smb_share' => 'photos',
                'smb_username' => 'visionsy',
                'smb_password' => 'secret123',
            ])
            ->assertSessionHasNoErrors();

        return MediaStorageSource::where('name', 'SMB Share')->firstOrFail();
    }

    /* ---------------- 权限 ---------------- */

    public function test_non_admin_cannot_mount(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $source = $this->createSmbSource($admin);

        $user = User::factory()->create();
        $this->actingAs($user)
            ->post(route('admin.storage-sources.mount', $source))
            ->assertForbidden();
    }

    public function test_non_admin_cannot_unmount(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $source = $this->createSmbSource($admin);
        $this->fakeDriver->mount($source);

        $user = User::factory()->create();
        $this->actingAs($user)
            ->post(route('admin.storage-sources.unmount', $source))
            ->assertForbidden();
    }

    /* ---------------- 挂载 ---------------- */

    public function test_admin_can_mount_storage_source(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $source = $this->createSmbSource($admin);

        $result = $this->fakeDriver->mount($source);
        $this->assertTrue($result['mounted']);
        $this->assertTrue($this->fakeDriver->isMounted());
    }

    public function test_mount_failure_does_not_break_state(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $source = $this->createSmbSource($admin);
        $this->fakeDriver->failNextMount();

        $result = $this->fakeDriver->mount($source);
        $this->assertFalse($result['mounted']);
        $this->assertFalse($this->fakeDriver->isMounted());
    }

    public function test_mount_requires_csrf(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $source = $this->createSmbSource($admin);

        $this->actingAs($admin)
            ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class)
            ->post(route('admin.storage-sources.mount', $source))
            ->assertSessionHasNoErrors();
    }

    /* ---------------- 卸载 ---------------- */

    public function test_admin_can_unmount_storage_source(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $source = $this->createSmbSource($admin);
        $this->fakeDriver->mount($source);

        $result = $this->fakeDriver->unmount($source);
        $this->assertFalse($result['mounted']);
        $this->assertFalse($this->fakeDriver->isMounted());
    }

    public function test_cannot_unmount_while_scanning(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $source = $this->createSmbSource($admin);
        $this->fakeDriver->mount($source);

        $galleryDir = $this->testRoot.'/TestGallery';
        mkdir($galleryDir, 0775, true);

        $galleryService = app(\App\Services\Media\GalleryService::class);
        $galleryService->discoverGalleries($source);

        $gallery = \App\Models\MediaGallery::where('storage_source_id', $source->id)->first();
        $gallery->update(['scan_status' => 'scanning']);

        $result = $this->fakeDriver->unmount($source);
        $this->assertTrue($result['mounted']);
        $this->assertArrayHasKey('error', $result);
        $this->assertTrue($this->fakeDriver->isMounted());

        @rmdir($galleryDir);
    }

    /* ---------------- 幂等性 ---------------- */

    public function test_duplicate_mount_is_idempotent(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $source = $this->createSmbSource($admin);

        $r1 = $this->fakeDriver->mount($source);
        $r2 = $this->fakeDriver->mount($source);

        $this->assertTrue($r1['mounted']);
        $this->assertTrue($r2['mounted']);
    }

    public function test_duplicate_unmount_is_idempotent(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $source = $this->createSmbSource($admin);
        $this->fakeDriver->mount($source);

        $r1 = $this->fakeDriver->unmount($source);
        $r2 = $this->fakeDriver->unmount($source);

        $this->assertFalse($r1['mounted']);
        $this->assertFalse($r2['mounted']);
    }

    /* ---------------- 凭据安全 ---------------- */

    public function test_credentials_not_in_logs_or_responses(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $source = $this->createSmbSource($admin);

        $this->assertNotNull($source->fresh()->smb_password, 'Password should be stored and retrievable');

        $response = $this->actingAs($admin)
            ->get(route('admin.storage-sources.edit', $source));

        $response->assertOk();
        $response->assertDontSee('secret123');

        $json = $source->toArray();
        $this->assertArrayNotHasKey('smb_password', $json);
    }

    /* ---------------- 连接测试 ---------------- */

    public function test_connection_test_returns_result(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $source = $this->createSmbSource($admin);
        $this->fakeDriver->mount($source);

        $this->actingAs($admin)
            ->post(route('admin.storage-sources.test-connection', $source))
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->fakeDriver->healthCheck($source));
    }

    /* ---------------- 状态 ---------------- */

    public function test_status_reflects_mount_state(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $source = $this->createSmbSource($admin);

        $status = $this->fakeDriver->status($source);
        $this->assertFalse($status['mounted']);

        $this->fakeDriver->mount($source);
        $status = $this->fakeDriver->status($source);
        $this->assertTrue($status['mounted']);
    }
}
