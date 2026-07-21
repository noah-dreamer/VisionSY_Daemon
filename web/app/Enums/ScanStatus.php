<?php

namespace App\Enums;

enum ScanStatus: string
{
    case Pending = 'pending';
    case Scanning = 'scanning';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '等待扫描',
            self::Scanning => '扫描中',
            self::Completed => '已完成',
            self::Failed => '扫描失败',
        };
    }
}
