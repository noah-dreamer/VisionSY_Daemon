<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorageSourceTest extends TestCase
{
    use RefreshDatabase;

    private string $testRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testRoot = sys_get_temp_dir().'/visionsy-test-'.bin2hex(random_bytes(8));
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

    /* ---------------- 管理员可以管理存储源 ---------------- */

    public function test_admin_can_create_local_storage_source(): void
    {
        $admin = User::factory()->systemAdmin()->create();

        $this->actingAs($admin)
            ->post(route('admin.storage-sources.store'), [
                'name' => 'Test Source',
                'driver' => 'local',
                'root_path' => $this->testRoot,
                'is_enabled' => '1',
                'is_writable' => '0',
                'is_managed' => '0',
                'scan_recursive' => '1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.storage-sources.index'));

        $this->assertDatabaseHas('media_storage_sources', [
            'name' => 'Test Source',
            'driver' => 'local',
            'root_path' => str_replace('\\', '/', realpath($this->testRoot)),
        ]);
    }

    public function test_non_admin_cannot_manage_storage_sources(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.storage-sources.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('admin.storage-sources.create'))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.storage-sources.store'), [
                'name' => 'Test',
                'driver' => 'local',
                'root_path' => $this->testRoot,
                'is_enabled' => '1',
                'is_writable' => '0',
                'is_managed' => '0',
                'scan_recursive' => '1',
            ])
            ->assertForbidden();
    }

    /* ---------------- 路径安全 ---------------- */

    public function test_path_outside_allowed_roots_is_rejected(): void
    {
        $admin = User::factory()->systemAdmin()->create();

        $outsideDir = sys_get_temp_dir().'/visionsy-outside-'.bin2hex(random_bytes(8));
        mkdir($outsideDir, 0775, true);

        try {
            $this->actingAs($admin)
                ->post(route('admin.storage-sources.store'), [
                    'name' => 'Blocked',
                    'driver' => 'local',
                    'root_path' => $outsideDir,
                    'is_enabled' => '1',
                    'is_writable' => '0',
                    'is_managed' => '0',
                    'scan_recursive' => '1',
                ])
                ->assertSessionHasErrors(['root_path']);
        } finally {
            @rmdir($outsideDir);
        }
    }

    public function test_path_traversal_is_rejected(): void
    {
        $admin = User::factory()->systemAdmin()->create();

        $this->actingAs($admin)
            ->post(route('admin.storage-sources.store'), [
                'name' => 'Traversal',
                'driver' => 'local',
                'root_path' => $this->testRoot.'/../outside',
                'is_enabled' => '1',
                'is_writable' => '0',
                'is_managed' => '0',
                'scan_recursive' => '1',
            ])
            ->assertSessionHasErrors();

        $this->assertDatabaseMissing('media_storage_sources', ['name' => 'Traversal']);
    }

    public function test_system_roots_are_rejected(): void
    {
        $admin = User::factory()->systemAdmin()->create();

        $this->actingAs($admin)
            ->post(route('admin.storage-sources.store'), [
                'name' => 'System',
                'driver' => 'local',
                'root_path' => '/etc',
                'is_enabled' => '1',
                'is_writable' => '0',
                'is_managed' => '0',
                'scan_recursive' => '1',
            ])
            ->assertSessionHasErrors(['root_path']);
    }

    /* ---------------- 列表和编辑 ---------------- */

    public function test_admin_can_list_and_edit_storage_sources(): void
    {
        $admin = User::factory()->systemAdmin()->create();

        $this->actingAs($admin)
            ->post(route('admin.storage-sources.store'), [
                'name' => 'Edit Test',
                'driver' => 'local',
                'root_path' => $this->testRoot,
                'is_enabled' => '1',
                'is_writable' => '0',
                'is_managed' => '0',
                'scan_recursive' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->get(route('admin.storage-sources.index'))
            ->assertOk()
            ->assertSee('Edit Test');
    }

    /* ---------------- 验证命令 ---------------- */

    public function test_admin_can_verify_storage_source_path(): void
    {
        $admin = User::factory()->systemAdmin()->create();

        $this->actingAs($admin)
            ->post(route('admin.storage-sources.store'), [
                'name' => 'Verify Test',
                'driver' => 'local',
                'root_path' => $this->testRoot,
                'is_enabled' => '1',
                'is_writable' => '1',
                'is_managed' => '0',
                'scan_recursive' => '1',
            ])
            ->assertSessionHasNoErrors();

        $source = \App\Models\MediaStorageSource::where('name', 'Verify Test')->first();

        $this->actingAs($admin)
            ->post(route('admin.storage-sources.verify', $source))
            ->assertRedirect();
    }
}
