<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OAuthClient extends Model
{
    protected $table = 'oauth_clients';

    protected $fillable = ['client_id', 'client_secret_hash', 'name', 'redirect_uris', 'scopes'];

    protected $hidden = ['client_secret_hash'];

    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
            'scopes' => 'array',
        ];
    }

    public function verifySecret(string $plainSecret): bool
    {
        return password_verify($plainSecret, $this->client_secret_hash);
    }

    public function allowsRedirectUri(string $uri): bool
    {
        return in_array($uri, $this->redirect_uris ?? [], true);
    }
}
