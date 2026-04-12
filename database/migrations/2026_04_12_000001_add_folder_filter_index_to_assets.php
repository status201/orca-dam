<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add composite index for folder-filtered asset listing queries.
     *
     * Optimizes: WHERE deleted_at IS NULL AND s3_key LIKE 'folder/%' ORDER BY updated_at DESC
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->index(['deleted_at', 's3_key', 'updated_at'], 'assets_folder_filter_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex('assets_folder_filter_index');
        });
    }
};
