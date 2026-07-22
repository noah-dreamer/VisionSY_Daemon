<?php

namespace App\Enums;

enum DerivativeStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Partial = 'partial';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '待处理',
            self::Processing => '处理中',
            self::Ready => '就绪',
            self::Partial => '部分就绪',
            self::Failed => '失败',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Ready, self::Failed], true);
    }
}
