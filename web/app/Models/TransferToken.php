<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferToken extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'token_hash', 'user_id', 'resource_id', 'file_path_hash',
        'action', 'original_method', 'allowed_host', 'client_ip',
        'one_time', 'nonce', 'expires_at', 'used_at',
    ];

    protected function casts(): array
    {
        return [
            'one_time' => 'boolean',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
