<?php

namespace App\Policies;

use App\Enums\MediaStatus;
use App\Enums\UserRole;
use App\Models\MediaResource;
use App\Models\User;

/**
 * 媒体库权限。判定顺序对齐 ResourceSpace：
 * 用户所属**用户组**的权限位优先（后台"用户组"页管理，语义对应 RS 的
 * s / w / T / c / e0 权限串）；用户未入组时回退到角色默认矩阵：
 *
 * | 角色            | 浏览缩略图 | 带水印预览/下载 | 无水印原图 | 上传 | 管理 |
 * | 系统管理员       |    ✓     |       ✓       |     ✓     |  ✓  |  ✓  |
 * | 宣传部成员       |    ✓     |       ✓       |     ✓     |  ✓  | 自己上传的 |
 * | 官微编辑（老师）  |    ✓     |       ✓       |     ✓     | 视配置 |  ✗  |
 * | 普通用户（已激活）|    ✓     |       ✓       |     ✗     |  ✗  |  ✗  |
 * | 游客            | 视配置    |       ✗       |     ✗     |  ✗  |  ✗  |
 *
 * 系统管理员不受组限制（对应 RS 的 a 权限拥有全部能力）。
 */
class MediaResourcePolicy
{
    /** 浏览列表 / 缩略图（RS: s）。 */
    public function viewAny(?User $user): bool
    {
        if ($user === null) {
            return (bool) config('visionsy.media.guest_browse', true);
        }

        if ($user->isDisabled()) {
            return false;
        }

        return $user->isSystemAdmin() || ($user->mediaGroupFlag('can_browse') ?? true);
    }

    /** 查看详情页。 */
    public function view(?User $user, MediaResource $resource): bool
    {
        if ($resource->status === MediaStatus::Archived) {
            return $user !== null && $this->manage($user, $resource);
        }

        return $this->viewAny($user);
    }

    /** 查看带水印预览大图（RS: w）。 */
    public function preview(?User $user, MediaResource $resource): bool
    {
        if ($user === null || $user->isDisabled() || ! $this->view($user, $resource)) {
            return false;
        }

        return $user->isSystemAdmin() || ($user->mediaGroupFlag('can_preview') ?? true);
    }

    /** 上传素材（RS: c）。 */
    public function create(User $user): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        if ($user->isSystemAdmin()) {
            return true;
        }

        $flag = $user->mediaGroupFlag('can_upload');
        if ($flag !== null) {
            return $flag;
        }

        return match ($user->role) {
            UserRole::PropagandaMember => true,
            UserRole::WechatEditorTeacher => (bool) config('visionsy.media.teacher_can_upload', true),
            default => false,
        };
    }

    /** 下载带水印压缩图。 */
    public function downloadWatermarked(User $user, MediaResource $resource): bool
    {
        if (! $user->isActive() || ! $this->view($user, $resource)) {
            return false;
        }

        return $user->isSystemAdmin() || ($user->mediaGroupFlag('can_download_watermarked') ?? true);
    }

    /** 下载无水印原图（RS: 无 T 限制）。 */
    public function downloadOriginal(User $user, MediaResource $resource): bool
    {
        if (! $user->isActive() || ! $this->view($user, $resource)) {
            return false;
        }

        if ($user->isSystemAdmin()) {
            return true;
        }

        $flag = $user->mediaGroupFlag('can_download_original');
        if ($flag !== null) {
            return $flag;
        }

        return in_array($user->role, [
            UserRole::PropagandaMember,
            UserRole::WechatEditorTeacher,
        ], true);
    }

    /** 编辑元数据 / 归档 / 删除（RS: e0 / 资源管理）。 */
    public function manage(User $user, MediaResource $resource): bool
    {
        if ($user->isSystemAdmin()) {
            return true;
        }

        if ($user->isActive() && ($user->mediaGroupFlag('can_manage_media') ?? false)) {
            return true;
        }

        return $user->role === UserRole::PropagandaMember
            && $user->isActive()
            && $resource->uploaded_by === $user->id;
    }
}
