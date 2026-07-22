<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_resources', function (Blueprint $table) {
            $table->string('storage_dir', 128)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('media_resources', function (Blueprint $table) {
            $table->string('storage_dir', 128)->nullable(false)->change();
        });
    }
};
