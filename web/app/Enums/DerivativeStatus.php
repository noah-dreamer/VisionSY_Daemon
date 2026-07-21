<?php

namespace App\Enums;

enum DerivativeStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '待处理',
            self::Processing => '处理中',
            self::Completed => '已完成',
            self::Failed => '失败',
        };
    }
}
