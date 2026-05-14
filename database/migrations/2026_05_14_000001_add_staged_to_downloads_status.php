<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE downloads MODIFY COLUMN status ENUM('pending','processing','staged','completed','failed') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE downloads MODIFY COLUMN status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending'");
    }
};
