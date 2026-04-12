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
        // FULLTEXT indexes are only supported on MySQL/MariaDB
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'])) {
            Schema::table('assets', function (Blueprint $table) {
                $table->fullText(['alt_text', 'caption'], 'assets_fulltext');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'])) {
            Schema::table('assets', function (Blueprint $table) {
                $table->dropFullText('assets_fulltext');
            });
        }
    }
};
