<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            UPDATE users u1
            JOIN (
                SELECT id, display_name,
                    ROW_NUMBER() OVER (PARTITION BY display_name ORDER BY id) AS rn
                FROM users
            ) u2 ON u1.id = u2.id
            SET u1.display_name = CONCAT(u2.display_name, "_", u2.rn)
            WHERE u2.rn > 1
        ');

        Schema::table('users', function (Blueprint $table) {
            $table->unique('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['display_name']);
        });
    }
};
