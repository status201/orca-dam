<?php

use App\Support\ColumnLimits;
use App\Support\S3KeyHash;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen every S3-key column to 1024 characters and restructure the indexes that blocked it.
 *
 * An S3 object key is up to 1024 bytes; every one of these columns shipped as the implicit
 * varchar(255), so a key S3 would happily accept was unstorable — SQLSTATE 22001 under MariaDB
 * strict mode. It is reachable today: folder is capped at 100 and filename at 255, so an upload
 * with keep_original_filename composes a ~356-character key, and the derived thumbnail/resize
 * keys are longer still. See specs/features/input-validation.md REQ-10.
 *
 * utf8mb4 is kept, so a widened s3_key is 4096 bytes and InnoDB's 3072-byte index key limit rules
 * out indexing the whole column. Both indexes containing it are therefore restructured, not
 * restored:
 *
 *   assets_s3_key_unique        -> the invariant moves to assets_s3_key_hash_unique (previous
 *                                  migration). This index was ALSO the only one serving exact-match
 *                                  lookups on s3_key, so a non-unique assets_s3_key_index at prefix
 *                                  width replaces that half of its job.
 *   assets_folder_filter_index  -> rebuilt as (deleted_at, s3_key(255), updated_at).
 *
 * 255 characters is not a compromise, it is the status quo: the column is 255 today, so a
 * 255-character prefix has exactly the discriminating power every existing index already had, at
 * exactly the same byte cost. Budget, InnoDB DYNAMIC, 16K page, limit 3072:
 *
 *   deleted_at  TIMESTAMP NULL   4 + 1 =    5
 *   s3_key(255) utf8mb4        255 * 4 = 1020
 *   updated_at  TIMESTAMP NULL   4 + 1 =    5
 *                                        -----
 *                                          1030   (identical to the index being replaced)
 *
 * The ceiling for this shape is s3_key(765) = 3070; 766 would be 3074 and would fail. 255 also
 * survives an 8K innodb_page_size (limit 1536), where 512 would not. Nothing is gained by going
 * wider: the predicate the composite index exists for is `s3_key LIKE 'folder/%'`
 * (Asset::scopeInFolder), and folder is capped at 100, so any prefix past ~101 adds no selectivity.
 *
 * One thing is genuinely lost. ORDER BY s3_key (the s3key_asc / s3key_desc sorts in
 * Asset::scopeApplySort) becomes a filesort, because a prefix index cannot order by a value it
 * does not fully store. Acceptable for a paginated listing; if it ever hurts, sort by filename
 * (still varchar(255), fully indexable) rather than re-widening the index.
 *
 * Not lost: the composite index never served its trailing ORDER BY updated_at anyway. A LIKE range
 * on the middle column already disqualifies later columns from satisfying a sort.
 */
return new class extends Migration
{
    /**
     * Characters of s3_key covered by the MySQL/MariaDB prefix indexes.
     */
    private const PREFIX = 255;

    public function up(): void
    {
        // Rows written between the previous migration's backfill and this one. Asset's saving hook
        // fills them, but only once the new code is live, and nothing forces `artisan migrate` to
        // run after the deploy. Cheap to re-run, fatal to skip: the NOT NULL change below aborts on
        // a single NULL and, on MySQL, would do so AFTER both indexes had already been dropped.
        $this->topUpHashes();

        // ONE closure, and the index drops belong inside it.
        //
        // MySQL/MariaDB: the drops are mandatory and must precede the widening. Each command is a
        // separate statement executed in declaration order, so `drop index` runs before
        // `modify s3_key varchar(1024)`; without them the widen fails with errno 1071, "Specified
        // key was too long", for both indexes alike.
        //
        // SQLite: any ->change() makes Blueprint insert an `alter` command, which SQLiteGrammar
        // expands into create __temp__ / insert-select / drop / rename — a full table copy. Two
        // closures containing ->change() would copy every row twice, which is why the copyright
        // widening used one closure and why this does too. Keeping the drops here is also the
        // correct place for them rather than merely a safe one: the rebuild recreates indexes from
        // BlueprintState, which only forgets an index once it has seen its drop command, and
        // commands are processed in declaration order.
        Schema::table('assets', function (Blueprint $table) {
            $table->dropUnique('assets_s3_key_unique');
            $table->dropIndex('assets_folder_filter_index');

            // ->change() re-emits the COMPLETE column definition on MySQL/MariaDB; it is not a
            // diff. Dropping ->nullable() from the four derived keys would silently make them
            // NOT NULL and break every asset without a thumbnail. s3_key is genuinely NOT NULL and
            // correctly has none.
            $table->string('s3_key', ColumnLimits::for('assets', 's3_key'))->change();
            $table->string('thumbnail_s3_key', ColumnLimits::for('assets', 'thumbnail_s3_key'))->nullable()->change();
            $table->string('resize_s_s3_key', ColumnLimits::for('assets', 'resize_s_s3_key'))->nullable()->change();
            $table->string('resize_m_s3_key', ColumnLimits::for('assets', 'resize_m_s3_key'))->nullable()->change();
            $table->string('resize_l_s3_key', ColumnLimits::for('assets', 'resize_l_s3_key'))->nullable()->change();

            // Every row is filled, so the surrogate can finally be made mandatory. Folded into this
            // closure so SQLite rebuilds the table once rather than twice. assets_s3_key_hash_unique
            // is not dropped here, so the rebuild carries it across from BlueprintState.
            $table->char('s3_key_hash', 64)->nullable(false)->change();
        });

        $this->createS3KeyIndexes();

        // upload_sessions.s3_key carries no index, so it needs none of the choreography above.
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->string('s3_key', ColumnLimits::for('upload_sessions', 's3_key'))->change();
        });
    }

    /**
     * The two indexes containing s3_key, at a width MySQL will accept.
     *
     * Blueprint cannot express an index prefix length, so MySQL/MariaDB need raw DDL. SQLite has no
     * 3072-byte limit and no prefix syntax, so it indexes the whole column — semantically identical
     * (a prefix index is a superset scan plus a recheck), which is what keeps the SQLite suite a
     * meaningful test of these query paths.
     */
    private function createS3KeyIndexes(): void
    {
        if ($this->onMySql()) {
            // Replaces the exact-match half of assets_s3_key_unique, which six call sites rely on
            // (AssetApiController, AssetController's dedup, ChunkedUploadService, DiscoverController);
            // assets_folder_filter_index cannot serve them because it starts with deleted_at.
            //
            // The prefix does not affect correctness. For `WHERE s3_key = ?` MySQL seeks the range
            // of entries whose first 255 characters match, then re-reads the full column from the
            // row and re-applies the predicate. A false positive is impossible by construction; the
            // only cost is extra row lookups for two keys sharing a 255-character prefix.
            DB::statement('alter table `assets` add index `assets_s3_key_index` (`s3_key`('.self::PREFIX.'))');
            DB::statement('alter table `assets` add index `assets_folder_filter_index` (`deleted_at`, `s3_key`('.self::PREFIX.'), `updated_at`)');

            return;
        }

        // No ->change() in this closure, so no table rebuild — two plain CREATE INDEX statements.
        Schema::table('assets', function (Blueprint $table) {
            $table->index('s3_key', 'assets_s3_key_index');
            $table->index(['deleted_at', 's3_key', 'updated_at'], 'assets_folder_filter_index');
        });
    }

    /**
     * Idempotent: fills only rows the previous migration's backfill could not have seen.
     *
     * Same DB::table / chunkById reasoning as that backfill — see the docblock there.
     */
    private function topUpHashes(int $chunkSize = 1000): void
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

    private function onMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * Reversible only while no key exceeds 255 characters. Once a wider one exists MariaDB refuses
     * this under strict mode rather than truncating it — the correct failure, but it means down()
     * is not a safe production rollback. Same caveat as the copyright widening.
     */
    public function down(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->string('s3_key', 255)->change();
        });

        // dropIndex by name is a plain statement on both drivers, so no guard is needed to remove
        // the prefix indexes. They must go before the narrowing, in the same closure, for the same
        // BlueprintState reason as up().
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex('assets_s3_key_index');
            $table->dropIndex('assets_folder_filter_index');

            $table->string('s3_key', 255)->change();
            $table->string('thumbnail_s3_key', 255)->nullable()->change();
            $table->string('resize_s_s3_key', 255)->nullable()->change();
            $table->string('resize_m_s3_key', 255)->nullable()->change();
            $table->string('resize_l_s3_key', 255)->nullable()->change();
        });

        // Only once the column is back to 255 (1020 bytes) can it carry a whole-column index again.
        Schema::table('assets', function (Blueprint $table) {
            $table->unique('s3_key', 'assets_s3_key_unique');
            $table->index(['deleted_at', 's3_key', 'updated_at'], 'assets_folder_filter_index');
        });
    }
};
