<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CampusIpRange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->role(UserRole::SystemAdmin, '系统管理员')->create();
    }

    public function test_non_admin_cannot_access_admin_panel(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin')->assertStatus(403);
        auth()->logout();
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_admin_can_access_admin_panel(): void
    {
        $this->actingAs($this->admin())->get('/admin')->assertOk()->assertSee('概览');
    }

    public function test_admin_can_create_real_name_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/users', [
            'display_name' => '王老师',
            'real_name' => '王官微',
            'email' => 'teacher@example.com',
            'role' => 'wechat_editor_teacher',
            'password' => 'initial-password-123',
        ])->assertRedirect(route('admin.users.index'));

        $user = User::where('email', 'teacher@example.com')->firstOrFail();
        $this->assertSame(UserRole::WechatEditorTeacher, $user->role);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertSame('王官微', $user->real_name);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.user_created',
            'actor_id' => $admin->id,
            'target_id' => (string) $user->id,
        ]);
    }

    public function test_admin_role_change_is_audited(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($admin)->put("/admin/users/{$user->id}", [
            'display_name' => $user->display_name,
            'real_name' => '张宣传',
            'role' => 'propaganda_member',
        ])->assertRedirect();

        $this->assertSame(UserRole::PropagandaMember, $user->fresh()->role);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.user_updated', 'actor_id' => $admin->id]);
    }

    public function test_admin_disable_account_is_audited(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($admin)->post("/admin/users/{$user->id}/toggle-status")->assertRedirect();

        $this->assertTrue($user->fresh()->isDisabled());
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.user_disabled', 'actor_id' => $admin->id]);
    }

    public function test_admin_cannot_disable_self(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->from('/admin/users')
            ->post("/admin/users/{$admin->id}/toggle-status")
            ->assertSessionHasErrors('status');

        $this->assertFalse($admin->fresh()->isDisabled());
    }

    public function test_admin_password_reset_is_audited(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $oldHash = $user->password_hash;

        $this->actingAs($admin)->post("/admin/users/{$user->id}/reset-password")->assertRedirect();

        $this->assertNotSame($oldHash, $user->fresh()->password_hash);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.user_password_reset']);
    }

    public function test_campus_cidr_management_is_audited(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/campus-ips', [
            'cidr' => '10.99.0.0/16',
            'description' => '宿舍区',
        ])->assertRedirect();

        $range = CampusIpRange::where('cidr', '10.99.0.0/16')->firstOrFail();
        $this->assertTrue($range->enabled);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.campus_ip_created']);

        $this->actingAs($admin)->post("/admin/campus-ips/{$range->id}/toggle")->assertRedirect();
        $this->assertFalse($range->fresh()->enabled);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.campus_ip_disabled']);

        $this->actingAs($admin)->delete("/admin/campus-ips/{$range->id}")->assertRedirect();
        $this->assertDatabaseMissing('campus_ip_ranges', ['id' => $range->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.campus_ip_deleted']);
    }

    public function test_invalid_cidr_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->from('/admin/campus-ips')
            ->post('/admin/campus-ips', ['cidr' => 'not-a-cidr/99'])
            ->assertSessionHasErrors('cidr');
    }

    public function test_oauth_client_creation_is_audited(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post('/admin/oauth-clients', [
            'name' => 'ResourceSpace',
            'client_id' => 'resourcespace',
            'redirect_uris' => "https://media.example.com/plugins/visionsy_sso/pages/callback.php",
        ]);

        $response->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('oauth_clients', ['client_id' => 'resourcespace']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.oauth_client_created']);
    }

    public function test_audit_log_page_lists_entries(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/campus-ips', ['cidr' => '10.88.0.0/16']);

        $this->actingAs($admin)
            ->get('/admin/audit-logs?action=campus_ip')
            ->assertOk()
            ->assertSee('admin.campus_ip_created');
    }
}
