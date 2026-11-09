<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserGroup;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class ProductionInstallationTest extends TestCase
{
    use RefreshDatabase;

    private function initializeAdmin(string $password): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $password."\n");
        rewind($stream);
        $input = new ArrayInput([
            'email' => 'admin@example.com',
            '--password-stdin' => true,
            '--if-missing' => true,
            '--json' => true,
        ]);
        $input->setStream($stream);
        $output = new BufferedOutput;

        try {
            $code = Artisan::all()['visionsy:ensure-admin']->run($input, $output);

            return [$code, $output->fetch()];
        } finally {
            fclose($stream);
        }
    }

    public function test_installer_creates_a_hashed_admin_from_stdin_without_echoing_password(): void
    {
        $password = 'Installation $ password with spaces';
        [$code, $output] = $this->initializeAdmin($password);

        $this->assertSame(0, $code);
        $this->assertStringNotContainsString($password, $output);
        $this->assertTrue(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['changed']);
        $admin = User::where('email', 'admin@example.com')->sole();
        $this->assertSame(UserRole::SystemAdmin, $admin->role);
        $this->assertTrue(Hash::check($password, $admin->password_hash));
        $this->assertNotSame($password, $admin->password_hash);
    }

    public function test_repeated_install_does_not_rotate_an_existing_password_or_change_the_account(): void
    {
        $this->initializeAdmin('first-install-password');
        $admin = User::where('email', 'admin@example.com')->sole();
        $admin->update(['display_name' => '管理员修改后的名称']);
        $hash = $admin->password_hash;

        [$code, $output] = $this->initializeAdmin('different-second-password');

        $this->assertSame(0, $code);
        $this->assertFalse(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['changed']);
        $this->assertSame($hash, $admin->fresh()->password_hash);
        $this->assertSame('管理员修改后的名称', $admin->fresh()->display_name);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_invalid_stdin_password_does_not_create_an_account(): void
    {
        [$code] = $this->initializeAdmin('short');

        $this->assertSame(1, $code);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_production_defaults_create_no_demo_data_and_preserve_custom_permissions(): void
    {
        $this->seed(ProductionSeeder::class);
        $this->assertDatabaseCount('user_groups', 4);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('media_resources', 0);
        $group = UserGroup::where('name', '普通成员组')->sole();
        $group->update(['can_upload' => true, 'description' => '生产自定义权限']);

        $this->seed(ProductionSeeder::class);

        $this->assertDatabaseCount('user_groups', 4);
        $this->assertTrue($group->fresh()->can_upload);
        $this->assertSame('生产自定义权限', $group->fresh()->description);
    }
}
