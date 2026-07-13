<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OAuthRefreshToken extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'oauth_refresh_tokens';

    protected $fillable = ['token_hash', 'access_token_id', 'client_id', 'user_id', 'scope', 'expires_at', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
