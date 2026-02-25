<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Allow 'reference' as a tag type value alongside 'user' and 'ai'.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE tags MODIFY COLUMN type ENUM('user','ai','reference') NOT NULL DEFAULT 'user'");
        } elseif (DB::getDriverName() === 'sqlite') {
            // SQLite enum() creates a CHECK constraint — rebuild the column
            Schema::table('tags', function (Blueprint $table) {
                $table->enum('type', ['user', 'ai', 'reference'])->default('user')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('tags')->where('type', 'reference')->update(['type' => 'user']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE tags MODIFY COLUMN type ENUM('user','ai') NOT NULL DEFAULT 'user'");
        } elseif (DB::getDriverName() === 'sqlite') {
            Schema::table('tags', function (Blueprint $table) {
                $table->enum('type', ['user', 'ai'])->default('user')->change();
            });
        }
    }
};
