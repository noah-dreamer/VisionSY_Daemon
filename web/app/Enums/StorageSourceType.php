<?php

namespace App\Enums;

enum StorageSourceType: string
{
    case Local = 'local';
    case Smb = 'smb';
    case Nfs = 'nfs';

    public function label(): string
    {
        return match ($this) {
            self::Local => '本地目录',
            self::Smb => 'SMB/CIFS 共享（已挂载）',
            self::Nfs => 'NFS 共享（已挂载）',
        };
    }

    public function isNetworkMount(): bool
    {
        return in_array($this, [self::Smb, self::Nfs], true);
    }

    public static function fromString(string $value): self
    {
        return match ($value) {
            'smb' => self::Smb,
            'nfs' => self::Nfs,
            default => self::Local,
        };
    }
}
