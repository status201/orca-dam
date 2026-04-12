<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the redundant (name, type) composite index on tags.
     *
     * The `name` column already has a UNIQUE index, so the composite
     * (name, type) index provides no additional query benefit — `name`
     * is unique, and adding `type` to the filter never narrows results.
     */
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->dropIndex(['name', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->index(['name', 'type']);
        });
    }
};
