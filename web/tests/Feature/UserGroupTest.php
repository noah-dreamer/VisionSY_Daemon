<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaResource;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 用户组：权限位覆盖角色矩阵 + 高级限制（每日限额 / IP 限制）+ 后台管理。
 */
class UserGroupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('gd')) {
            $this->markTestSkipped('PHP GD 扩展不可用。');
        }

        Storage::fake('local');
    }

    private function makeResource(): MediaResource
    {
        $member = User::factory()->role(UserRole::PropagandaMember, '李拍摄')->create();

        $this->actingAs($member)->post(route('media.store'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 800, 600),
            'title' => '组权限测试素材',
        ]);

        return MediaResource::firstOrFail();
    }

    /* ---------------- 权限位覆盖角色 ---------------- */

    public function test_group_can_grant_original_download_to_normal_user(): void
    {
        $resource = $this->makeResource();

        $group = UserGroup::create([
            'name' => '特批原图组',
            'can_download_original' => true,
        ]);
        $student = User::factory()->create(['user_group_id' => $group->id]);

        $this->actingAs($student)
            ->get(route('media.download.original', $resource))
            ->assertOk();
    }

    public function test_group_can_revoke_watermarked_download_from_normal_user(): void
    {
        $resource = $this->makeResource();

        $group = UserGroup::create([
            'name' => '仅浏览组',
            'can_preview' => false,
            'can_download_watermarked' => false,
        ]);
        $student = User::factory()->create(['user_group_id' => $group->id]);

        $this->actingAs($student)->get(route('media.thumb', $resource))->assertOk();
        $this->actingAs($student)->get(route('media.preview', $resource))->assertForbidden();
        $this->actingAs($student)->get(route('media.download.watermarked', $resource))->assertForbidden();
    }

    public function test_group_can_grant_upload_and_manage_to_teacher(): void
    {
        $resource = $this->makeResource();

        config(['visionsy.media.teacher_can_upload' => false]); // 角色默认关闭
        $group = UserGroup::create([
            'name' => '资源管理组',
            'can_upload' => true,
            'can_download_original' => true,
            'can_manage_media' => true,
        ]);
        $teacher = User::factory()->role(UserRole::WechatEditorTeacher, '张老师')->create(['user_group_id' => $group->id]);

        $this->actingAs($teacher)->get(route('media.create'))->assertOk();
        // 可管理他人上传的素材
        $this->actingAs($teacher)->get(route('media.edit', $resource))->assertOk();
    }

    /* ---------------- 高级限制 ---------------- */

    public function test_daily_download_limit_is_enforced(): void
    {
        $resource = $this->makeResource();

        $group = UserGroup::create([
            'name' => '限额组',
            'download_limit_per_day' => 1,
        ]);
        $student = User::factory()->create(['user_group_id' => $group->id]);

        $this->actingAs($student)->get(route('media.download.watermarked', $resource))->assertOk();
        $this->actingAs($student)->get(route('media.download.watermarked', $resource))->assertStatus(429);
    }

    public function test_ip_restriction_blocks_download_from_outside_range(): void
    {
        $resource = $this->makeResource();

        $group = UserGroup::create([
            'name' => 'IP 限制组',
            'ip_restriction' => '10.0.0.0/8',
        ]);
        $student = User::factory()->create(['user_group_id' => $group->id]);

        // 测试请求来自 127.0.0.1，不在 10.0.0.0/8 内
        $this->actingAs($student)
            ->get(route('media.download.watermarked', $resource))
            ->assertForbidden();
    }

    public function test_system_admin_is_not_limited_by_group(): void
    {
        $resource = $this->makeResource();

        $group = UserGroup::create([
            'name' => '空权限组',
            'can_browse' => false,
            'can_preview' => false,
            'can_download_watermarked' => false,
            'download_limit_per_day' => 1,
            'ip_restriction' => '10.0.0.0/8',
        ]);
        $admin = User::factory()->role(UserRole::SystemAdmin, '王运维')->create(['user_group_id' => $group->id]);

        $this->actingAs($admin)->get(route('media.download.original', $resource))->assertOk();
        $this->actingAs($admin)->get(route('media.download.original', $resource))->assertOk();
    }

    /* ---------------- 后台管理 ---------------- */

    public function test_admin_can_crud_user_groups(): void
    {
        $admin = User::factory()->role(UserRole::SystemAdmin, '王运维')->create();

        $this->actingAs($admin)->post(route('admin.user-groups.store'), [
            'name' => '校史馆合作方',
            'description' => '外部合作',
            'can_browse' => 1,
            'can_preview' => 1,
            'download_limit_per_day' => 50,
            'ip_restriction' => '',
        ])->assertRedirect(route('admin.user-groups.index'));

        $group = UserGroup::where('name', '校史馆合作方')->firstOrFail();
        $this->assertTrue($group->can_preview);
        $this->assertFalse($group->can_download_original);
        $this->assertSame(50, $group->download_limit_per_day);

        $this->actingAs($admin)->put(route('admin.user-groups.update', $group), [
            'name' => '校史馆合作方',
            'can_browse' => 1,
            'can_preview' => 1,
            'can_download_original' => 1,
            'download_limit_per_day' => 0,
            'ip_restriction' => '',
        ]);
        $this->assertTrue($group->fresh()->can_download_original);

        $member = User::factory()->create(['user_group_id' => $group->id]);
        $this->actingAs($admin)->delete(route('admin.user-groups.destroy', $group));

        $this->assertDatabaseMissing('user_groups', ['name' => '校史馆合作方']);
        $this->assertNull($member->fresh()->user_group_id); // 成员回退角色矩阵
    }

    public function test_admin_can_assign_group_when_editing_user(): void
    {
        $admin = User::factory()->role(UserRole::SystemAdmin, '王运维')->create();
        $group = UserGroup::create(['name' => '普通成员组']);
        $student = User::factory()->create();

        $this->actingAs($admin)->put(route('admin.users.update', $student), [
            'display_name' => $student->display_name,
            'role' => $student->role->value,
            'user_group_id' => $group->id,
        ])->assertRedirect(route('admin.users.edit', $student));

        $this->assertSame($group->id, $student->fresh()->user_group_id);
    }

    public function test_non_admin_cannot_manage_user_groups(): void
    {
        $student = User::factory()->create();

        $this->actingAs($student)->get(route('admin.user-groups.index'))->assertForbidden();
        $this->actingAs($student)->post(route('admin.user-groups.store'), ['name' => 'x', 'download_limit_per_day' => 0])->assertForbidden();
    }

    public function test_new_registration_joins_default_group(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $group = UserGroup::create(['name' => '注册默认组', 'is_registration_default' => true]);

        $this->post('/register', [
            'display_name' => '新同学',
            'email' => 'newbie@example.edu.cn',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertSame($group->id, User::where('email', 'newbie@example.edu.cn')->value('user_group_id'));
    }
}
