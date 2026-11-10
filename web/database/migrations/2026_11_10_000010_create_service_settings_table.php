<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('service_settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->longText('value');
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('service_settings');
    }
};
