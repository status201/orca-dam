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
            if (!Schema::hasColumn('assets', 'license_expiry_date')) {
                $table->date('license_expiry_date')->nullable()->after('license_type');
            }
            if (!Schema::hasColumn('assets', 'copyright_source')) {
                $table->string('copyright_source')->nullable()->after('copyright');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['license_expiry_date', 'copyright_source']);
        });
    }
};
