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
            if (!Schema::hasColumn('assets', 'license_type')) {
                $table->string('license_type')->nullable()->after('caption');
            }
            if (!Schema::hasColumn('assets', 'copyright')) {
                $table->string('copyright')->nullable()->after('license_type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['license_type', 'copyright']);
        });
    }
};
