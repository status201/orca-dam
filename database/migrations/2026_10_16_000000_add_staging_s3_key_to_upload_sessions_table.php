<?php

use App\Support\ColumnLimits;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds upload_sessions.staging_s3_key — where a chunked SVG's multipart assembles.
 *
 * A chunked SVG used to assemble straight onto its asset key and be sanitized there, so a
 * sanitization failure in keep_original_filename overwrite mode had to delete an existing asset's
 * original. It now assembles at chunked-staging/{session_token}.svg and only sanitized content is
 * written to the asset key (specs/features/chunked-upload.md REQ-4). Nullable: non-SVG sessions
 * have no staging key, and sessions already in flight at deploy keep assembling on s3_key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->string('staging_s3_key', ColumnLimits::for('upload_sessions', 'staging_s3_key'))
                ->nullable()
                ->after('s3_key');
        });
    }

    public function down(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->dropColumn('staging_s3_key');
        });
    }
};
