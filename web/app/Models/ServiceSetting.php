<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ServiceSetting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['value'];
    protected function casts(): array
    {
        return ['value' => 'encrypted:array'];
    }
}
