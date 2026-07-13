<?php

namespace App\Enums;

enum MediaStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => '正常',
            self::Archived => '已归档',
        };
    }
}
