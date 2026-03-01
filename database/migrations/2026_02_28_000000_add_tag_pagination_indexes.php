<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add indexes to support paginated tag queries with sorting and filtering.
     */
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->index('type');
            $table->index('created_at');
        });

        Schema::table('asset_tag', function (Blueprint $table) {
            $table->index('tag_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('asset_tag', function (Blueprint $table) {
            $table->dropIndex(['tag_id']);
        });
    }
};
