<?php

use App\Support\ColumnLimits;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen assets.filename and upload_sessions.filename from 255 to 500 characters.
 *
 * `S3Service::uploadFile()` writes `getClientOriginalName()` into `assets.filename` verbatim, and
 * nothing capped it: StoreAssetRequest validated `files.*` as a file (kilobytes) and never looked
 * at the name. A 300-character filename therefore passed validation and MariaDB rejected the
 * insert as SQLSTATE 22001 — the over-length copyright bug again, on an unguarded path.
 *
 * 500 rather than 255 because the cap is also what bounds the derived keys. `S3Service` builds
 * `thumbnails/L/{folder}/{basename}.{ext}` into resize_l_s3_key: at folder ≤ 356 (the deepest
 * legal nesting) and filename ≤ 500 the longest derived key is ~871 characters, comfortably inside
 * s3_key's 1024. See specs/features/input-validation.md REQ-13.
 *
 * upload_sessions.filename goes with it: ChunkedUploadController::initiate validates the incoming
 * filename against assets.filename and parks it there until complete() creates the asset, so a
 * name that column accepts has to survive the round trip.
 *
 * Unlike the s3_key widening, this needs no index choreography. assets.filename carries
 * `assets_filename_index` (2026_03_01_000000_add_asset_query_indexes.php:25), but 500 utf8mb4
 * characters is 2000 bytes — inside InnoDB's 3072-byte key limit, so the index survives the change
 * untouched. `php artisan db:verify-schema` re-checks that arithmetic against the live server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            // NOT NULL, so no ->nullable() to restate — but note ->change() re-emits the whole
            // column definition on MySQL, so anything else this column carried would need
            // repeating here.
            $table->string('filename', ColumnLimits::for('assets', 'filename'))->change();
        });

        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->string('filename', ColumnLimits::for('upload_sessions', 'filename'))->change();
        });
    }

    /**
     * Reversible only while no filename exceeds 255 characters. Past that MariaDB refuses under
     * strict mode rather than truncating — the correct failure, but it means down() is not a safe
     * production rollback. Same caveat as the copyright and s3_key widenings.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('filename', 255)->change();
        });

        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->string('filename', 255)->change();
        });
    }
};
