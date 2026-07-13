<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('resource_id', 120)->nullable();
            $table->string('file_path_hash', 64)->nullable();
            $table->string('action', 16);                 // upload / download
            $table->string('original_method', 10);        // GET / POST
            $table->string('allowed_host', 120);          // files.example.com / files-internal.example.com
            $table->string('client_ip', 45)->nullable();
            $table->boolean('one_time')->default(true);
            $table->string('nonce', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_tokens');
    }
};
