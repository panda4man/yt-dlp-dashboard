<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE downloads MODIFY COLUMN status ENUM('pending','processing','staged','completed','failed','exporting','export_failed') NOT NULL DEFAULT 'pending'");

        Schema::table('downloads', function (Blueprint $table) {
            $table->text('export_error')->nullable()->after('exported_at');
            $table->timestamp('plex_refreshed_at')->nullable()->after('export_error');
            $table->text('plex_error')->nullable()->after('plex_refreshed_at');
        });
    }

    public function down(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->dropColumn(['export_error', 'plex_refreshed_at', 'plex_error']);
        });

        DB::statement("ALTER TABLE downloads MODIFY COLUMN status ENUM('pending','processing','staged','completed','failed') NOT NULL DEFAULT 'pending'");
    }
};
