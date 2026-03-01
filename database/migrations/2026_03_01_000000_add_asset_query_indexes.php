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
        Schema::table('assets', function (Blueprint $table) {
            // Covers the default asset listing: WHERE deleted_at IS NULL ORDER BY updated_at DESC
            $table->index(['deleted_at', 'updated_at']);

            // Covers Asset::missing()->count() on every asset index page load
            $table->index('s3_missing_at');

            // Covers size_asc / size_desc sort options
            $table->index('size');

            // Covers name_asc / name_desc sort options
            $table->index('filename');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['deleted_at', 'updated_at']);
            $table->dropIndex(['s3_missing_at']);
            $table->dropIndex(['size']);
            $table->dropIndex(['filename']);
        });
    }
};
