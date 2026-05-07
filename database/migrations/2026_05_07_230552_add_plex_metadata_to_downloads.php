<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->string('youtube_video_id')->nullable()->after('thumbnail_url');
            $table->date('uploaded_at')->nullable()->after('youtube_video_id');
            $table->text('description')->nullable()->after('uploaded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->dropColumn(['youtube_video_id', 'uploaded_at', 'description']);
        });
    }
};
