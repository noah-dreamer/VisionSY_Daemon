<?php
namespace Tests\Feature;

use App\Jobs\Management\CreateDatabaseBackup;
use App\Jobs\Media\DiscoverMediaGalleries;
use App\Models\DatabaseBackup;
use App\Models\ManagementApiKey;
use App\Models\MediaGallery;
use App\Models\MediaResource;
use App\Jobs\Media\ProcessNewUpload;
use App\Models\MediaStorageSource;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ManagementApiTest extends TestCase
{
    use RefreshDatabase;
    private User $owner;
    private ManagementApiKey $key;
    private string $token;
    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->systemAdmin()->create();
        $issued = ManagementApiKey::issue($this->owner, 'Test dashboard');
        $this->key = $issued['key']; $this->token = $issued['token'];
    }
    private function callApi(string $method, string $path, array $data = [])
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)->json($method, '/api/management/v1/'.$path, $data);
    }
    public function test_management_api_requires_its_own_key(): void
    {
        $this->getJson('/api/management/v1/info')->assertUnauthorized();
        $this->withHeader('X-Internal-Secret', config('visionsy.internal_api_secret'))->getJson('/api/management/v1/info')->assertUnauthorized();
        $result = $this->callApi('GET', 'info')->assertOk()->assertJsonPath('product', 'visionsy-daemon')->assertJsonPath('api_version', 1);
        $this->assertStringNotContainsString($this->token, $result->getContent());
        $this->assertStringNotContainsString('secret_hash', $result->getContent());
    }
    public function test_management_cors_allows_only_configured_panel_origins(): void
    {
        config(['management.allowed_origins' => 'https://panel.example.com,http://127.0.0.1:8090']);
        $this->withHeaders(['Origin' => 'https://panel.example.com', 'Access-Control-Request-Method' => 'PUT',
            'Access-Control-Request-Headers' => 'authorization,content-type', 'Access-Control-Request-Private-Network' => 'true'])
            ->options('/api/management/v1/users/1')->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://panel.example.com')
            ->assertHeader('Access-Control-Allow-Private-Network', 'true')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
        $this->withHeaders(['Origin' => 'https://panel.example.com', 'Authorization' => 'Bearer invalid'])
            ->getJson('/api/management/v1/info')->assertUnauthorized()
            ->assertHeader('Access-Control-Allow-Origin', 'https://panel.example.com');
        $this->withHeaders(['Origin' => 'https://panel.example.com', 'Authorization' => 'Bearer '.$this->token])
            ->getJson('/api/management/v1/info')->assertOk()
            ->assertHeader('Access-Control-Expose-Headers', 'Content-Disposition');
        foreach (['https://evil.example.com', 'https://panel.example.com.evil.test', 'null'] as $origin) {
            $this->withHeaders(['Origin' => $origin])->getJson('/api/management/v1/info')
                ->assertForbidden()->assertHeaderMissing('Access-Control-Allow-Origin');
        }
    }
    public function test_management_cors_rejects_unknown_headers_and_does_not_apply_to_media_site(): void
    {
        config(['management.allowed_origins' => 'https://panel.example.com']);
        $this->withHeaders(['Origin' => 'https://panel.example.com', 'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'x-internal-secret'])->options('/api/management/v1/info')->assertForbidden();
        $this->get('/up')->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
    }
    public function test_revoked_key_is_rejected(): void
    {
        $this->key->update(['revoked_at' => now()]);
        $this->callApi('GET', 'info')->assertUnauthorized();
    }
    public function test_disabled_or_demoted_owner_loses_api_access(): void
    {
        $this->owner->update(['status' => 'disabled']);
        $this->callApi('GET', 'info')->assertForbidden();
        $this->owner->update(['status' => 'active', 'role' => 'normal_user']);
        $this->callApi('GET', 'info')->assertForbidden();
    }
    public function test_account_crud_hides_passwords_and_validates_unique_display_names(): void
    {
        $data = ['display_name' => '测试用户', 'email' => 'api-user@example.com', 'role' => 'normal_user', 'status' => 'active', 'password' => 'test-only-password'];
        $created = $this->callApi('POST', 'users', $data)->assertOk()->assertJsonMissingPath('data.password_hash');
        $id = $created->json('data.id');
        $this->callApi('POST', 'users', array_replace($data, ['email' => 'another@example.com']))->assertUnprocessable()->assertJsonValidationErrors('display_name');
        $this->callApi('GET', 'users?q=api-user')->assertOk()->assertJsonCount(1, 'data');
        $this->callApi('PUT', 'users/'.$id, array_replace($data, ['display_name' => '已更新']))->assertOk();
        $this->callApi('DELETE', 'users/'.$id)->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $id]);
    }
    public function test_current_administrator_cannot_be_disabled_demoted_or_deleted(): void
    {
        $data = ['display_name' => $this->owner->display_name, 'role' => 'system_admin', 'status' => 'disabled'];
        $this->callApi('PUT', 'users/'.$this->owner->id, $data)->assertUnprocessable();
        $this->callApi('PUT', 'users/'.$this->owner->id, array_replace($data, ['role' => 'normal_user', 'status' => 'active']))->assertUnprocessable();
        $this->callApi('DELETE', 'users/'.$this->owner->id)->assertUnprocessable();
    }
    public function test_default_group_is_unique_and_deletion_preserves_members(): void
    {
        $base = ['name' => '第一组', 'download_limit_per_day' => 10, 'is_registration_default' => true, 'can_browse' => true];
        $first = $this->callApi('POST', 'groups', $base)->assertOk()->json('data.id');
        $second = $this->callApi('POST', 'groups', array_replace($base, ['name' => '第二组']))->assertOk()->json('data.id');
        $this->assertFalse(UserGroup::find($first)->is_registration_default);
        $user = User::factory()->create(['user_group_id' => $second]);
        $this->callApi('DELETE', 'groups/'.$second)->assertOk();
        $this->assertNull($user->fresh()->user_group_id);
    }
    public function test_source_scan_dispatches_discovery(): void
    {
        Queue::fake(); $source = $this->source();
        $this->callApi('POST', 'sources/'.$source->id.'/scan')->assertOk();
        Queue::assertPushed(DiscoverMediaGalleries::class);
    }
    public function test_source_validation_and_nonempty_deletion_are_preserved(): void
    {
        $this->callApi('POST', 'sources', ['name' => '非法目录', 'root_path' => '/etc'])->assertUnprocessable();
        $source = $this->source();
        MediaGallery::create(['storage_source_id' => $source->id, 'name' => '保留图库', 'relative_path' => 'gallery']);
        $this->callApi('DELETE', 'sources/'.$source->id)->assertUnprocessable();
        $this->assertDatabaseHas('media_storage_sources', ['id' => $source->id]);
    }
    public function test_source_output_hides_smb_password(): void
    {
        $source = $this->source(); $source->smb_password = 'test-smb-secret'; $source->save();
        $response = $this->callApi('GET', 'sources')->assertOk();
        $this->assertStringNotContainsString('smb_password', $response->getContent());
        $this->assertStringNotContainsString('test-smb-secret', $response->getContent());
    }
    public function test_reserved_gallery_directories_are_rejected(): void
    {
        $source = $this->source();
        $this->callApi('POST', 'galleries', ['storage_source_id' => $source->id, 'name' => '非法图库', 'folder_name' => 'preview'])->assertUnprocessable();
    }
    public function test_backups_are_async_and_only_completed_files_can_be_downloaded(): void
    {
        Queue::fake(); $response = $this->callApi('POST', 'backups')->assertAccepted();
        Queue::assertPushed(CreateDatabaseBackup::class); $id = $response->json('data.id');
        $this->callApi('POST', 'backups')->assertConflict();
        $this->callApi('GET', 'backups/'.$id.'/download')->assertNotFound();
        $this->callApi('DELETE', 'backups/'.$id)->assertConflict();
        $backup = DatabaseBackup::find($id);
        if (! is_dir(dirname($backup->path()))) mkdir(dirname($backup->path()), 0700, true);
        file_put_contents($backup->path(), 'test-backup');
        $backup->update(['status' => 'completed', 'size_bytes' => 11]);
        $this->callApi('GET', 'backups/'.$id.'/download')->assertOk()->assertHeader('content-disposition');
        $this->callApi('DELETE', 'backups/'.$id)->assertOk();
        $this->assertFileDoesNotExist($backup->path());
    }
    public function test_dashboard_cannot_list_create_or_revoke_connection_keys(): void
    {
        $this->callApi('GET', 'keys')->assertNotFound();
        $this->callApi('POST', 'keys', ['name' => 'unwanted key'])->assertNotFound();
        $this->callApi('DELETE', 'keys/'.$this->key->id)->assertNotFound();
        $this->assertNull($this->key->fresh()->revoked_at);
        $this->callApi('GET', 'info')->assertOk();
    }
    public function test_mutations_audit_the_bound_administrator(): void
    {
        $this->callApi('POST', 'groups', ['name' => '审计组', 'download_limit_per_day' => 0, 'actor_id' => 99999])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'user_group.saved', 'actor_id' => $this->owner->id]);
    }
    public function test_daemon_disables_legacy_admin_pages_without_disabling_management_api(): void
    {
        config(['management.legacy_admin_enabled' => false]);
        $this->actingAs($this->owner)->get('/admin')->assertStatus(410);
        $this->callApi('GET', 'info')->assertOk();
    }
    public function test_multibyte_passwords_over_bcrypt_limit_are_rejected(): void
    {
        $this->callApi('POST', 'users', ['email' => 'long-password@example.com', 'display_name' => '密码边界测试',
            'role' => 'normal_user', 'status' => 'active', 'password' => str_repeat('汉', 30)])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }
    public function test_authentication_runs_before_resource_binding(): void
    {
        $this->deleteJson('/api/management/v1/users/99999999')->assertUnauthorized();
    }
    public function test_video_retry_uses_the_processing_pipeline_and_titles_match_the_column_limit(): void
    {
        Queue::fake();
        $source = $this->source();
        $gallery = MediaGallery::create(['name' => '视频图库', 'storage_source_id' => $source->id, 'relative_path' => 'videos']);
        $resource = MediaResource::create(['title' => '视频', 'original_name' => 'test.mp4', 'original_ext' => 'mp4',
            'type' => 'video', 'mime' => 'video/mp4', 'size_bytes' => 1, 'checksum_sha256' => str_repeat('a', 64),
            'storage_dir' => 'management-video-test', 'storage_source_id' => $source->id, 'gallery_id' => $gallery->id,
            'relative_path' => 'test.mp4', 'derivative_status' => 'failed']);
        $this->callApi('POST', 'resources/'.$resource->id.'/retry-derivatives')->assertOk();
        Queue::assertPushed(ProcessNewUpload::class);
        $this->callApi('PUT', 'resources/'.$resource->id, ['title' => str_repeat('x', 121)])
            ->assertUnprocessable()->assertJsonValidationErrors('title');
    }
    public function test_failed_database_dump_does_not_produce_a_completed_backup_or_expose_credentials(): void
    {
        $backup = DatabaseBackup::create(['created_by' => $this->owner->id]);
        $original = getenv('MARIADB_ROOT_PASSWORD');
        putenv('MARIADB_ROOT_PASSWORD=invalid-backup-test-credential');
        try {
            (new CreateDatabaseBackup($backup->id))->handle();
        } finally {
            $original === false ? putenv('MARIADB_ROOT_PASSWORD') : putenv('MARIADB_ROOT_PASSWORD='.$original);
        }
        $this->assertSame('failed', $backup->fresh()->status);
        $this->assertFileDoesNotExist($backup->path());
        $this->assertFileDoesNotExist($backup->path().'.part');
        $this->assertStringNotContainsString('invalid-backup-test-credential', $backup->fresh()->error);
    }
    private function source(): MediaStorageSource
    {
        $path = config('visionsy.media.managed_root');
        if (! is_dir($path)) mkdir($path, 0775, true);
        return MediaStorageSource::create(['name' => '测试目录', 'driver' => 'local', 'root_path' => $path,
            'is_enabled' => true, 'is_read_only' => false, 'created_by' => $this->owner->id]);
    }
}
