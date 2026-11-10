<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagementApiKey extends Model
{
    protected $guarded = [];
    protected $hidden = ['secret_hash'];
    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    public static function issue(User $user, string $name): array
    {
        $prefix = bin2hex(random_bytes(6));
        $secret = bin2hex(random_bytes(32));
        $key = static::create(['user_id' => $user->id, 'name' => $name,
            'prefix' => $prefix, 'secret_hash' => hash('sha256', $secret)]);
        return ['key' => $key, 'token' => 'vsy_'.$prefix.'.'.$secret];
    }
}
