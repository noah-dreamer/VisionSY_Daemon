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

    /** 告诉 Laravel 认证系统密码列名为 password_hash。 */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
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

    /** 是否允许通过 SSO 进入 ResourceSpace。 */
    public function canEnterResourceSpace(): bool
    {
        return $this->isActive();
    }

    /** userinfo 端点用的 ResourceSpace 用户组列表。 */
    public function resourceSpaceGroups(): array
    {
        return [$this->role->resourceSpaceGroup()];
    }
}
