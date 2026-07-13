<?php

namespace App\Enums;

enum MediaType: string
{
    case Image = 'image';
    case Video = 'video';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Image => '图片',
            self::Video => '视频',
            self::Other => '其他',
        };
    }

    public static function fromMime(string $mime): self
    {
        return match (true) {
            str_starts_with($mime, 'image/') => self::Image,
            str_starts_with($mime, 'video/') => self::Video,
            default => self::Other,
        };
    }
}
