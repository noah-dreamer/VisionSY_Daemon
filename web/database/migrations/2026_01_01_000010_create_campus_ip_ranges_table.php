<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campus_ip_ranges', function (Blueprint $table) {
            $table->id();
            $table->string('cidr', 64)->unique();
            $table->string('description')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campus_ip_ranges');
    }
};
