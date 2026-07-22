<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_storage_sources', function (Blueprint $table) {
            $table->string('smb_server', 255)->nullable()->after('is_managed');
            $table->string('smb_share', 255)->nullable()->after('smb_server');
            $table->string('smb_username', 120)->nullable()->after('smb_share');
            $table->text('smb_password')->nullable()->after('smb_username');
            $table->string('smb_domain', 120)->nullable()->after('smb_password');
            $table->string('smb_version', 10)->nullable()->after('smb_domain');
            $table->string('nfs_server', 255)->nullable()->after('smb_version');
            $table->string('nfs_export', 512)->nullable()->after('nfs_server');
            $table->string('nfs_version', 10)->nullable()->after('nfs_export');
            $table->boolean('auto_mount')->default(false)->after('nfs_version');
            $table->string('mount_status', 20)->nullable()->after('auto_mount');
            $table->text('mount_error')->nullable()->after('mount_status');
        });
    }

    public function down(): void
    {
        Schema::table('media_storage_sources', function (Blueprint $table) {
            $table->dropColumn([
                'smb_server', 'smb_share', 'smb_username', 'smb_password',
                'smb_domain', 'smb_version',
                'nfs_server', 'nfs_export', 'nfs_version',
                'auto_mount', 'mount_status', 'mount_error',
            ]);
        });
    }
};
