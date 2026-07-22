<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_resources', function (Blueprint $table) {
            $table->timestamp('derivative_thumb_at')->nullable()->after('derivative_error');
            $table->timestamp('derivative_large_at')->nullable()->after('derivative_thumb_at');
            $table->timestamp('derivative_wm_jpeg_at')->nullable()->after('derivative_large_at');
            $table->timestamp('derivative_wm_png_at')->nullable()->after('derivative_wm_jpeg_at');
            $table->unsignedSmallInteger('derivative_attempts')->default(0)->after('derivative_wm_png_at');
            $table->string('derivative_converter', 64)->nullable()->after('derivative_attempts');
            $table->string('derivative_converter_version', 32)->nullable()->after('derivative_converter');
        });
    }

    public function down(): void
    {
        Schema::table('media_resources', function (Blueprint $table) {
            $table->dropColumn([
                'derivative_thumb_at', 'derivative_large_at',
                'derivative_wm_jpeg_at', 'derivative_wm_png_at',
                'derivative_attempts',
                'derivative_converter', 'derivative_converter_version',
            ]);
        });
    }
};
