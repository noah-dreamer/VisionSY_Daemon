<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OAuthAuthorizationCode extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'oauth_authorization_codes';

    protected $fillable = ['code_hash', 'client_id', 'user_id', 'redirect_uri', 'scope', 'expires_at', 'used_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function client()
    {
        return $this->belongsTo(OAuthClient::class, 'client_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
