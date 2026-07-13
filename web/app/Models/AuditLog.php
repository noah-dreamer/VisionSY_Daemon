<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id', 'actor_email', 'action',
        'target_type', 'target_id', 'ip', 'user_agent', 'context',
    ];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
