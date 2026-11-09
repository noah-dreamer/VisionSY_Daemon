<?php

namespace Database\Seeders;

use App\Models\UserGroup;
use Illuminate\Database\Seeder;

/** Production defaults only: no demo accounts/media or changes to existing permissions. */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (UserGroupSeeder::defaults() as $attributes) {
            UserGroup::firstOrCreate(['name' => $attributes['name']], $attributes);
        }

        $this->command?->info('生产默认用户组已就绪，保留已有权限配置。');
    }
}
