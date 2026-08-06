<?php

use App\Support\ColumnLimits;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen assets.copyright and assets.copyright_source to their documented width.
 *
 * Both shipped as varchar(255) while every validation rule, both `maxlength=` attributes
 * and the specs allowed 500. In MariaDB strict mode a 256-500 character value therefore
 * passed validation and was rejected by the driver as SQLSTATE 22001, which surfaced as a
 * bare HTTP 500. See specs/features/input-validation.md REQ-5.
 *
 * The width is read from ColumnLimits so the column and the rules cannot drift apart again.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One closure, not two: ->change() on SQLite rebuilds the whole table, and doing
        // that twice would copy every row twice for no reason.
        Schema::table('assets', function (Blueprint $table) {
            // ->change() re-emits the COMPLETE column definition on MySQL/MariaDB — it is
            // not a diff. Dropping ->nullable() here would silently make both columns
            // NOT NULL and break every asset that has no copyright set.
            $table->string('copyright', ColumnLimits::for('assets', 'copyright'))->nullable()->change();
            $table->string('copyright_source', ColumnLimits::for('assets', 'copyright_source'))->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reversible only while no row exceeds 255 characters. Once values wider than that
        // exist, MariaDB will refuse this (strict mode) rather than truncate them — which
        // is the correct failure, but it means down() is not a safe production rollback.
        Schema::table('assets', function (Blueprint $table) {
            $table->string('copyright', 255)->nullable()->change();
            $table->string('copyright_source', 255)->nullable()->change();
        });
    }
};
