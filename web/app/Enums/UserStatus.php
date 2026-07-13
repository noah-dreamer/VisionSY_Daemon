<?php

namespace App\Enums;

enum UserStatus: string
{
    case PendingEmailVerification = 'pending_email_verification';
    case PendingActivation = 'pending_activation';
    case Active = 'active';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::PendingEmailVerification => '待验证邮箱',
            self::PendingActivation => '待校园网激活',
            self::Active => '已激活',
            self::Disabled => '已禁用',
        };
    }
}
