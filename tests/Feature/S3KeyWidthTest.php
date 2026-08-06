<?php

use App\Models\Asset;
use App\Models\User;
use App\Support\ColumnLimits;
use App\Support\DatabaseError;
use App\Support\S3KeyHash;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * The s3_key widening and the index restructuring it forced.
 *
 * An S3 object key is up to 1024 bytes; every S3-key column shipped as the implicit varchar(255),
 * so a key S3 accepts was unstorable. Widening assets.s3_key to 1024 makes it 4096 bytes at
 * utf8mb4, past InnoDB's 3072-byte index key limit, so its UNIQUE constraint moved to a sha256
 * surrogate and both of its indexes were rebuilt at a 255-character prefix.
 *
 * What this file can and cannot prove is worth stating plainly. SQLite (ADR-008) enforces neither
 * varchar length nor index key length, so nothing here proves the columns are really 1024 wide or
 * that the prefix indexes really fit — those are the manual MariaDB checks in
 * specs/features/input-validation.md. What it does prove is everything that is real on both
 * drivers: that the surrogate tracks its column on every write path, that uniqueness still bites,
 * that a violation is reported against s3_key rather than the surrogate, and that the indexes
 * survived the SQLite table rebuild the widening migration performs.
 *
 * @see specs/features/input-validation.md REQ-10
 */
test('the schema carries the surrogate and all three restructured indexes', function () {
    // The SQLite path of the widening migration rebuilds the whole assets table (any ->change()
    // does), recreating its indexes from BlueprintState. An index dropped in the wrong order, or
    // one the rebuild quietly failed to restore, is invisible until a query goes slow in
    // production — so assert the end state rather than trusting the migration ran clean.
    expect(Schema::hasColumn('assets', 's3_key_hash'))->toBeTrue();

    $indexes = collect(Schema::getIndexes('assets'))->keyBy('name');

    expect($indexes)->toHaveKeys([
        'assets_s3_key_hash_unique',   // the uniqueness invariant, moved off the wide column
        'assets_s3_key_index',         // exact-match lookups, which the old unique index served
        'assets_folder_filter_index',  // scopeInFolder's LIKE range
    ]);

    expect($indexes['assets_s3_key_hash_unique']['unique'])->toBeTrue();

    // The old constraint must be gone, not merely superseded: leaving it would mean the widening
    // never actually applied on MySQL.
    expect($indexes)->not->toHaveKey('assets_s3_key_unique');
});

test('every write path keeps the surrogate in step with the key', function () {
    $user = User::factory()->create();

    // Factory, mass assignment and a later update are the three ways an s3_key is written; the
    // third is bulk move, the one operation ADR-006 allows to change a key.
    $fromFactory = Asset::factory()->create(['s3_key' => 'assets/hash-a.jpg', 'user_id' => $user->id]);
    expect($fromFactory->s3_key_hash)->toBe(S3KeyHash::of('assets/hash-a.jpg'));

    $created = Asset::create([
        's3_key' => 'assets/hash-b.jpg',
        'filename' => 'hash-b.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 1,
        'user_id' => $user->id,
    ]);
    expect($created->s3_key_hash)->toBe(S3KeyHash::of('assets/hash-b.jpg'));

    $created->update(['s3_key' => 'moved/hash-b.jpg']);
    expect($created->fresh()->s3_key_hash)->toBe(S3KeyHash::of('moved/hash-b.jpg'));

    // A write that does not touch s3_key must leave the surrogate alone rather than recompute it
    // from a stale attribute.
    $before = $created->fresh()->s3_key_hash;
    $created->update(['filename' => 'renamed.jpg']);
    expect($created->fresh()->s3_key_hash)->toBe($before);
});

test('a duplicate key is still refused, and is reported against s3_key not the surrogate', function () {
    $user = User::factory()->create();
    Asset::factory()->create(['s3_key' => 'assets/taken.jpg', 'user_id' => $user->id]);

    try {
        Asset::factory()->create(['s3_key' => 'assets/taken.jpg', 'user_id' => $user->id]);
        $this->fail('A duplicate s3_key was accepted — the uniqueness invariant did not survive the move to the surrogate.');
    } catch (QueryException $e) {
        $hint = DatabaseError::classify($e);
    }

    expect($hint)->not->toBeNull()
        ->and($hint->kind)->toBe('duplicate')
        // Not s3_key_hash: that column is real, so without COLUMN_ALIASES the error bag would key
        // on a field no form has and the user would see nothing at all.
        ->and($hint->column)->toBe('s3_key');
});

test('the surrogate stays out of serialized output', function () {
    $asset = Asset::factory()->create(['user_id' => User::factory()->create()->id]);

    // Asset had no $hidden before this column existed, so a new column lands in every toArray()
    // — including the public API payloads — as a meaningless 64-character digest.
    expect($asset->toArray())->not->toHaveKey('s3_key_hash')
        ->and($asset->s3_key_hash)->not->toBeNull();
});

test('a key at the full documented width round-trips', function () {
    $limit = ColumnLimits::for('assets', 's3_key');
    expect($limit)->toBe(1024);

    // Worthless as a width check on SQLite, which stores any length in a `varchar` — that is what
    // the manual MariaDB check in the spec is for. Kept because it documents the intent and is the
    // assertion that fires first if the suite is ever pointed at MySQL.
    $prefix = 'assets/';
    $suffix = '.jpeg';
    $key = $prefix.str_repeat('a', $limit - mb_strlen($prefix) - mb_strlen($suffix)).$suffix;
    $asset = Asset::factory()->create(['s3_key' => $key, 'user_id' => User::factory()->create()->id]);

    expect(mb_strlen($asset->fresh()->s3_key))->toBe($limit)
        ->and($asset->fresh()->s3_key_hash)->toBe(S3KeyHash::of($key));
});
