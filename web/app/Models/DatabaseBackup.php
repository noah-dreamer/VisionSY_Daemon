<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DatabaseBackup extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $attributes = ['status' => 'pending', 'size_bytes' => 0];
    protected function casts(): array
    {
        return ['finished_at' => 'datetime', 'size_bytes' => 'integer'];
    }
    public function path(): string
    {
        return storage_path('app/private/database-backups/'.$this->id.'.sql.gz');
    }
}
