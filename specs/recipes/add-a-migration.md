<!--
  Recipe: add a column to assets and ripple it through the model/factory/CSV/search/API.
-->

# Recipe — Add an asset field (the migration ripple)

```yaml
id: add-a-migration
status: implemented
version: 1
owner: core
related:
  - architecture
  - ../features/asset-model
  - ../features/csv-export-import
  - ../decisions/adr-006-immutable-s3-key
source:
  - database/migrations/
  - app/Models/Asset.php
  - database/factories/AssetFactory.php
  - app/Services/CsvExportService.php
  - app/Services/CsvImportService.php
```

A repeatable **playbook**, not a feature. A new column on `assets` is never a
one-file change — it ripples through the model's `$fillable`/casts, the
factory, both CSV services, and usually the search/API/UI surfaces, because
`Asset` is the central entity nearly every feature spec reads or writes
through (see [`asset-model.md`](../features/asset-model.md)). The concrete
worked instance is any of the additive columns already shipped —
`license_expiry_date`/`copyright_source`
(`2026_01_20_123427_add_license_expiry_and_copyright_source_to_assets_table.php`)
or `parent_id`
(`2026_04_24_000000_add_parent_id_to_assets.php`).

## Background / Why

Skipping a step in the ripple produces a column that silently doesn't round-trip:
a migration with no `$fillable` entry means `Asset::create()`/`update()` drops
it; a model without a factory default means every test touching the field
must set it manually; a CSV service that doesn't list it means export/import
silently ignores it forever. Doing all six steps in one PR (even for a small
field) keeps the model's contract — documented in
[`asset-model.md`](../features/asset-model.md) — matching what's actually
persisted.

## Steps

### 1. Migration — `database/migrations/{timestamp}_add_<field>_to_assets_table.php`

```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('new_field')->nullable()->after('copyright_source');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('new_field');
        });
    }
};
```

A self-referencing FK (like `parent_id`) instead uses `foreignId(...)->nullable()
->constrained('assets')->nullOnDelete()` — pick the `onDelete` behavior
deliberately (`nullOnDelete` for "derived-from" links that shouldn't cascade-
delete the child).

### 2. Model — `app/Models/Asset.php`

Add to `$fillable`; add a cast only if the column isn't a plain string (dates,
booleans, ints already have casts for `size`/`width`/`height`/
`license_expiry_date`/`s3_missing_at` as examples):

```php
protected $fillable = [
    // ...
    'new_field',
];

protected $casts = [
    // ... 'new_field' => 'date', // only if not a string
];
```

If the field should be visible on every serialized asset, add its accessor
name to `Asset::APPEND_FIELDS` (only for **computed** attributes — a plain
column is already visible without appending).

### 3. Factory — `database/factories/AssetFactory.php`

Add a sensible fake default in `definition()`, and a state method if tests
will want to opt into a specific value (see `withLicense()`/`withCopyright()`
for the existing pattern):

```php
'new_field' => fake()->optional()->word(),
```

```php
public function withNewField(string $value): static
{
    return $this->state(fn (array $attributes) => ['new_field' => $value]);
}
```

### 4. CSV export — `app/Services/CsvExportService.php`

Add the column name to `generateHeaders()` (order matters — it's a fixed
34-column contract other tooling may parse positionally) and the value to
`formatRow()`, passed through `sanitizeCell()` like every other cell:

```php
// generateHeaders(): add 'new_field' in the same position both places
// formatRow(): $asset->new_field, added at the matching position
```

### 5. CSV import — `app/Services/CsvImportService.php`

Only if the field should be user-editable via CSV round-trip: add it to
`UPDATABLE_FIELDS` (the exhaustive whitelist `import()` is allowed to write —
adding a column here is what makes it importable, nothing else is needed for
`calculateChanges()` to pick it up since that method iterates the whitelist):

```php
public const UPDATABLE_FIELDS = [
    'filename', 'alt_text', 'caption', 'license_type',
    'license_expiry_date', 'copyright', 'copyright_source',
    'new_field',
];
```

If the new field has its own validation rule (like `license_type`'s enum or
`license_expiry_date`'s date format), add a branch to `validateRow()` too.

### 6. Search / API surface (only if applicable)

- **Search**: if the field should be searchable, extend
  `AssetSearchParser`/`Asset::scopeSearch` — see
  [`asset-search.md`](../features/asset-search.md).
- **API**: if the field should be settable via `PATCH /api/assets/{id}`, add it
  to `UpdateAssetRequest`'s rules **and** to `AssetApiController::update`'s
  `$request->only([...])` list — the REST API spec's Open Questions section
  flags exactly this kind of validate/persist mismatch as a real gap to avoid
  repeating.

### 7. Verify

```bash
./vendor/bin/pint
php artisan config:clear && php artisan test
```

## Gotchas

- **Never rename or repurpose `s3_key`** as part of any migration — it's
  immutable by design (REQ-1 of `asset-model.md`,
  [ADR-006](../decisions/adr-006-immutable-s3-key.md)); a "cache busting"
  motivation for touching it is always wrong — use a Cloudflare purge instead.
  What ADR-006 fixes is the stored *value*, not the column's declaration: the
  1024-character widening in
  `2026_08_06_100001_widen_s3_key_columns_on_assets_table.php` changed no value
  and broke no URL, and is not an exception to this rule.
- **Widening an indexed column is a different job from adding one.** At utf8mb4
  a `varchar` costs 4 bytes per character in an index key, and InnoDB caps that
  key at 3072 bytes — so `varchar(769)` upward cannot be indexed whole, and any
  index containing the column must be **dropped before** the `->change()` and
  recreated after, in that order, in the same `Schema::table()` closure. SQLite
  enforces neither limit, so CI stays green and the failure (errno 1071) lands
  on the first real deploy. Blueprint cannot express an index prefix length
  either, so a prefixed replacement needs a driver-guarded raw `DB::statement()`.
  Worked instance:
  `2026_08_06_100000_add_s3_key_hash_to_assets_table.php` +
  `2026_08_06_100001_widen_s3_key_columns_on_assets_table.php`, with the full
  reasoning in [`input-validation.md`](../features/input-validation.md) REQ-10
  to REQ-12.
- A migration with no matching `$fillable` entry doesn't error — `Asset::create()`
  just silently drops the field. This is the single most common way this
  ripple goes wrong; there's no test that catches it generically.
- `CsvImportService::UPDATABLE_FIELDS` is a **whitelist**, not inferred from
  `$fillable` — a field present on the model but missing from this constant
  will export fine but never import, silently.
- MariaDB compatibility: prefer `string`/`text`/`boolean`/`date`/`datetime`/
  `foreignId` column types; avoid MySQL-8-only features not present in
  MariaDB's dialect. Tests run on SQLite (ADR-008) so a genuinely
  MariaDB-specific migration issue won't surface until a real deploy — verify
  the migration against MariaDB manually if it uses anything beyond a plain
  column type.
- Add a Dutch translation for any new user-facing label tied to the field —
  see [`add-a-translated-string`](add-a-translated-string.md).

## Tests & verification

- `tests/Unit/AssetTest.php` — model-level round-trip (fillable, casts,
  accessors).
- `tests/Feature/ExportTest.php`, `tests/Unit/CsvExportServiceTest.php` — the
  new column appears in the CSV header and row.
- `tests/Feature/ImportTest.php`, `tests/Unit/CsvImportServiceTest.php` — the
  new field round-trips through preview/import if added to
  `UPDATABLE_FIELDS`.
- `php artisan config:clear && php artisan test` (full suite — a fillable
  omission often only surfaces as an unrelated test's assertion failing).
