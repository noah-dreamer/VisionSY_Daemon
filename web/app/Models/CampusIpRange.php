<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampusIpRange extends Model
{
    protected $fillable = ['cidr', 'description', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
