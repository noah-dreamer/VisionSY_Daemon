<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'email',
        'password_hash',
        'display_name',
        'real_name',
        'role',
        'user_group_id',
        'status',
        'email_verified_at',
        'activated_at',
        'activation_ip',
        'activation_user_agent',
        'last_login_at',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'activated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_hash' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
        ];
    }

    /** 邮箱验证统一走 6 位验证码（覆盖框架默认的签名链接邮件）。 */
    public function sendEmailVerificationNotification(): void
    {
        app(\App\Services\EmailVerificationCodeService::class)->issue($this);
    }

    /** 告诉 Laravel 认证系统密码列名为 password_hash。 */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    /** 媒体库用户组（可空；未入组时按角色默认矩阵）。 */
    public function userGroup()
    {
        return $this->belongsTo(UserGroup::class);
    }

    /**
     * 查询某个媒体权限位：入组则以组为准（覆盖角色），未入组返回 null 由策略回退角色矩阵。
     */
    public function mediaGroupFlag(string $flag): ?bool
    {
        return $this->userGroup?->{$flag};
    }

    public function isSystemAdmin(): bool
    {
        return $this->role === UserRole::SystemAdmin;
    }

    public function isDisabled(): bool
    {
        return $this->status === UserStatus::Disabled;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }
}
