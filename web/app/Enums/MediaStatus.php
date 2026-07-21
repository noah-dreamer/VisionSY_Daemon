<?php

namespace App\Enums;

enum MediaStatus: string
{
    case Active = 'active';
    case Missing = 'missing';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => '正常',
            self::Missing => '文件丢失',
            self::Archived => '已归档',
        };
    }
}
