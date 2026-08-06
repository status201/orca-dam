<?php

use App\Support\S3KeyHash;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Introduce assets.s3_key_hash and move the s3_key uniqueness invariant onto it.
 *
 * assets.s3_key is about to become varchar(1024). At utf8mb4 that is 4096 bytes, well past
 * InnoDB's 3072-byte index key limit, so the column can no longer carry its own UNIQUE
 * constraint. A sha256 surrogate can. See specs/features/input-validation.md REQ-10.
 *
 * Split from the widening migration on purpose, for two reasons:
 *
 *   1. The unique index on the hash exists BEFORE the old unique index on s3_key is dropped, so
 *      there is no instant at which a duplicate upload could slip through unnoticed. Dropping
 *      first would also make the subsequent `add unique` fail on the duplicate it just let in,
 *      leaving the table with no uniqueness at all.
 *   2. MySQL/MariaDB DDL is not transactional, so a failure in the widening step does not roll
 *      this one back. Committing the backfill — the only slow part — as its own migration means
 *      a retry never pays for a second full pass over the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Nullable and unindexed at first: a NOT NULL column with no default cannot be added to a
        // populated table, and a UNIQUE index over a half-filled column enforces nothing, since
        // multiple NULLs are legal on both MySQL and SQLite.
        //
        // No ->change() in this closure, so SQLite emits a plain `alter table add column` rather
        // than rebuilding the table. Plain char(64) with no ->charset()/->collation(): `Collate`
        // is one of SQLiteGrammar's supported modifiers, so an ascii_bin collation would be
        // written into the SQLite DDL too and fail the moment the index is used. 64 hex
        // characters is 256 bytes of index key, which is not worth a driver divergence to shrink.
        Schema::table('assets', function (Blueprint $table) {
            $table->char('s3_key_hash', 64)->nullable()->after('s3_key');
        });

        self::backfill();

        // Only now, with every row filled, is this a real constraint.
        Schema::table('assets', function (Blueprint $table) {
            $table->unique('s3_key_hash', 'assets_s3_key_hash_unique');
        });
    }

    /**
     * Fill s3_key_hash for every row that has none.
     *
     * Query builder rather than Eloquent, for two reasons. A migration is a historical record and
     * must not depend on App\Models\Asset, whose $fillable and casts can change under it. And
     * Asset applies the SoftDeletes global scope: a trashed row still occupies its s3_key — that
     * is exactly what AssetController's withTrashed() dedup check relies on — so skipping it would
     * leave a NULL that the NOT NULL step in the next migration aborts on.
     *
     * chunkById, not chunk: the loop writes the very column the WHERE filters on, so chunk()'s
     * offset/limit paging would skip a row for every row it fixed. chunkById pages on `id > last`,
     * which is immune. The whereNull filter also makes the pass resumable and re-runnable.
     */
    public static function backfill(int $chunkSize = 1000): void
    {
        DB::table('assets')
            ->select('id', 's3_key')
            ->whereNull('s3_key_hash')
            ->chunkById($chunkSize, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('assets')
                        ->where('id', $row->id)
                        ->update(['s3_key_hash' => S3KeyHash::of((string) $row->s3_key)]);
                }
            }, 'id');
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropUnique('assets_s3_key_hash_unique');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('s3_key_hash');
        });
    }
};
