<?php

namespace App\Models;

use App\Services\CampusIpService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 媒体库用户组（对齐 ResourceSpace usergroup）。
 * 权限位覆盖角色默认矩阵；用户未入组时按角色回退。
 */
class UserGroup extends Model
{
    protected $fillable = [
        'name', 'description',
        'can_browse', 'can_preview', 'can_download_watermarked',
        'can_download_original', 'can_upload', 'can_manage_media',
        'download_limit_per_day', 'ip_restriction', 'is_registration_default',
    ];

    protected function casts(): array
    {
        return [
            'can_browse' => 'boolean',
            'can_preview' => 'boolean',
            'can_download_watermarked' => 'boolean',
            'can_download_original' => 'boolean',
            'can_upload' => 'boolean',
            'can_manage_media' => 'boolean',
            'download_limit_per_day' => 'integer',
            'is_registration_default' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** 权限位清单（用于后台表单与展示），label => 字段。 */
    public static function permissionFlags(): array
    {
        return [
            'can_browse' => ['浏览 / 检索素材', '对应 RS 权限 s：可进入媒体库检索与查看缩略图'],
            'can_preview' => ['查看带水印预览', '对应 RS 权限 w：可打开高清水印预览'],
            'can_download_watermarked' => ['下载水印压缩图', '可下载带平铺水印的压缩 JPG'],
            'can_download_original' => ['下载无水印原图', '对应 RS 去除 T 限制：可获取原始文件'],
            'can_upload' => ['上传素材', '对应 RS 权限 c：可创建资源'],
            'can_manage_media' => ['管理全部素材', '对应 RS e0/资源管理：编辑、归档、删除任意素材'],
        ];
    }

    /** 今日剩余下载次数（null = 不限额）。 */
    public function remainingDownloadsToday(User $user): ?int
    {
        if ($this->download_limit_per_day <= 0) {
            return null;
        }

        $used = AuditLog::where('actor_id', $user->id)
            ->where('action', 'like', 'media.download_%')
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        return max(0, $this->download_limit_per_day - $used);
    }

    /** IP 限制校验（对齐 RS 的 IP address restriction，支持逗号分隔多 CIDR）。 */
    public function ipAllowed(?string $ip): bool
    {
        $restriction = trim((string) $this->ip_restriction);
        if ($restriction === '' || $ip === null) {
            return true;
        }

        foreach (array_filter(array_map('trim', explode(',', $restriction))) as $cidr) {
            if (CampusIpService::cidrMatch($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }
}
