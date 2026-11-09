<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaResource;
use App\Models\User;
use App\Services\Media\DerivativeVariant;
use App\Services\Media\PathResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaLibraryTest extends TestCase
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

    private function uploadAs(User $user, string $title = '校运会开幕式'): MediaResource
    {
        $this->actingAs($user)->post(route('media.store'), [
            'file' => UploadedFile::fake()->image('photo.jpg', 1200, 800),
            'title' => $title,
            'description' => '测试素材',
            'tags' => '校运会, 测试',
        ])->assertSessionHasNoErrors();

        return MediaResource::where('title', $title)->firstOrFail();
    }

    /* ---------------- 上传 ---------------- */

    public function test_propaganda_member_can_upload_and_derivatives_are_generated(): void
    {
        $member = User::factory()->role(UserRole::PropagandaMember, '李拍摄')->create();

        $resource = $this->uploadAs($member);

        $this->assertTrue($resource->has_thumb);
        $this->assertTrue($resource->has_preview);
        $resolver = app(PathResolverService::class);
        $this->assertFileExists($resolver->resolveOriginalAbsolute($resource));
        $this->assertFileExists($resolver->resolveDerivativeAbsolute($resource, DerivativeVariant::Thumb));
        $this->assertFileExists($resolver->resolveDerivativeAbsolute($resource, DerivativeVariant::Large));
        $this->assertDatabaseHas('audit_logs', ['action' => 'media.uploaded']);
    }

    public function test_normal_user_cannot_upload(): void
    {
        $user = User::factory()->create(); // 已激活普通用户

        $this->actingAs($user)->get(route('media.create'))->assertForbidden();

        $this->actingAs($user)->post(route('media.store'), [
            'file' => UploadedFile::fake()->image('photo.jpg'),
            'title' => '越权上传',
        ])->assertForbidden();
    }

    public function test_duplicate_content_is_rejected(): void
    {
        $member = User::factory()->role(UserRole::PropagandaMember, '李拍摄')->create();

        // 固定像素内容，保证两次上传 sha256 相同
        $img = imagecreatetruecolor(50, 50);
        imagefilledrectangle($img, 0, 0, 50, 50, imagecolorallocate($img, 10, 20, 30));
        $path = tempnam(sys_get_temp_dir(), 'dup').'.png';
        imagepng($img, $path);
        imagedestroy($img);

        $make = fn () => new UploadedFile($path, 'same.png', 'image/png', null, true);

        $this->actingAs($member)->post(route('media.store'), [
            'file' => $make(), 'title' => '第一次',
        ])->assertSessionHasNoErrors();

        $this->actingAs($member)->post(route('media.store'), [
            'file' => $make(), 'title' => '第二次',
        ])->assertSessionHasErrors('file');

        @unlink($path);
    }

    /* ---------------- 分级下载 ---------------- */

    public function test_normal_user_gets_watermarked_but_not_original(): void
    {
        $member = User::factory()->role(UserRole::PropagandaMember, '李拍摄')->create();
        $resource = $this->uploadAs($member);

        $student = User::factory()->create();

        $this->actingAs($student)
            ->get(route('media.download.watermarked', $resource))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        $this->actingAs($student)
            ->get(route('media.download.original', $resource))
            ->assertForbidden();
    }

    public function test_teacher_and_member_can_download_original(): void
    {
        $member = User::factory()->role(UserRole::PropagandaMember, '李拍摄')->create();
        $teacher = User::factory()->role(UserRole::WechatEditorTeacher, '张老师')->create();
        $resource = $this->uploadAs($member);

        foreach ([$member, $teacher] as $user) {
            $this->actingAs($user)
                ->get(route('media.download.original', $resource))
                ->assertOk();
        }

        $this->assertSame(2, $resource->fresh()->download_count_original);
    }

    public function test_pending_activation_user_cannot_download(): void
    {
        $member = User::factory()->role(UserRole::PropagandaMember, '李拍摄')->create();
        $resource = $this->uploadAs($member);

        $pending = User::factory()->pendingActivation()->create();

        $this->actingAs($pending)
            ->get(route('media.download.watermarked', $resource))
            ->assertForbidden();
    }

    /* ---------------- 浏览 ---------------- */

    public function test_guest_can_browse_index_and_thumbs_but_not_preview(): void
    {
        $member = User::factory()->role(UserRole::PropagandaMember, '李拍摄')->create();
        $resource = $this->uploadAs($member);

        auth()->logout();

        $this->get(route('media.index', ['view' => 'photos']))->assertOk()->assertSee('校运会开幕式');
        $this->get(route('media.thumb', $resource))->assertOk();
        $this->get(route('media.preview', $resource))->assertRedirect(route('login'));
    }

    public function test_media_detail_back_link_has_index_fallback_and_history_hook(): void
    {
        $member = User::factory()->role(UserRole::PropagandaMember, '李拍摄')->create();
        $resource = $this->uploadAs($member);

        $this->actingAs($member)
            ->get(route('media.show', $resource))
            ->assertOk()
            ->assertSee('返回媒体库')
            ->assertSee('href="'.route('media.index').'"', false)
            ->assertSee('data-media-library-back', false)
            ->assertSee('window.history.back()', false);
    }

    public function test_archived_resource_hidden_from_normal_users(): void
    {
        $member = User::factory()->role(UserRole::PropagandaMember, '李拍摄')->create();
        $resource = $this->uploadAs($member);

        $this->actingAs($member)
            ->post(route('media.toggle-archive', $resource))
            ->assertSessionHasNoErrors();

        $student = User::factory()->create();
        $this->actingAs($student)->get(route('media.show', $resource))->assertForbidden();
        $this->actingAs($student)->get(route('media.index', ['view' => 'photos']))->assertDontSee('校运会开幕式');
    }

    /* ---------------- 管理 ---------------- */

    public function test_uploader_can_edit_and_delete_own_resource(): void
    {
        $member = User::factory()->role(UserRole::PropagandaMember, '李拍摄')->create();
        $resource = $this->uploadAs($member);

        $this->actingAs($member)->put(route('media.update', $resource), [
            'title' => '改名后的素材',
            'tags' => '新标签',
        ])->assertRedirect(route('media.show', $resource));

        $this->assertSame('改名后的素材', $resource->fresh()->title);

        $original = app(PathResolverService::class)->resolveOriginalAbsolute($resource);
        $this->actingAs($member)->delete(route('media.destroy', $resource))
            ->assertRedirect(route('media.index'));

        $this->assertDatabaseMissing('media_resources', ['id' => $resource->id]);
        $this->assertFileDoesNotExist($original);
    }

    public function test_other_member_cannot_manage_someone_elses_resource(): void
    {
        $owner = User::factory()->role(UserRole::PropagandaMember, '李拍摄')->create();
        $other = User::factory()->role(UserRole::PropagandaMember, '王拍摄')->create();
        $resource = $this->uploadAs($owner);

        $this->actingAs($other)->get(route('media.edit', $resource))->assertForbidden();
        $this->actingAs($other)->delete(route('media.destroy', $resource))->assertForbidden();
    }
}
