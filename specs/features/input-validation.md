# Input validation limits

```yaml
id: input-validation
status: implemented
version: 6
owner: core
related:
  - architecture
  - error-handling
  - asset-model
  - asset-upload
  - chunked-upload
  - csv-export-import
  - rest-api
  - tags
source:
  - app/Support/ColumnLimits.php
  - app/Support/S3KeyHash.php
  - app/Models/Asset.php
  - app/Http/Requests/UpdateAssetRequest.php
  - app/Http/Requests/StoreAssetRequest.php
  - app/Rules/BoundedFilename.php
  - app/Http/Requests/Concerns/HasUploadMetadataRules.php
  - app/Http/Requests/Tools/ToolUploadRequest.php
  - app/Http/Controllers/ChunkedUploadController.php
  - app/Http/Controllers/TagController.php
  - app/Http/Controllers/Api/AssetApiController.php
  - app/Services/CsvImportService.php
  - resources/views/assets/edit.blade.php
  - resources/views/partials/upload-metadata.blade.php
  - resources/views/components/char-counter.blade.php
  - resources/js/char-counter.js
```

## Background / Why

A validation rule looser than its column passes validation and then fails in MariaDB
(`strict => true`) with SQLSTATE 22001 — a bare 500. The suite runs in-memory SQLite (ADR-008),
which does not enforce `varchar` length, so the guard cannot be a database assertion: it compares
the **rules** against the **declared** widths. This spec owns that comparison, the single place
widths are declared, and the client-side half that makes a limit visible. What happens when a value
nonetheless reaches the driver is [`error-handling.md`](error-handling.md).

## Requirements

- **REQ-1** — `App\Support\ColumnLimits` is the single source of truth for the declared width of
  every `varchar` column the app validates into. `ColumnLimits::CHARS[$table][$column]` is read by
  the declaring migration, every rule writing into it, and the view rendering its input.
  `ColumnLimits::for()` throws on an unknown column rather than defaulting.
- **REQ-2** — **A validation rule may never permit more characters than its column accepts.** It
  may be deliberately tighter, never looser. The audit asserts `rule max <= column limit`; for
  `TEXT` columns it compares worst-case utf8mb4 bytes (`ColumnLimits::fitsText()`, 4 bytes/char).
- **REQ-3** — The audit is **complete by construction**: every character-capped rule in every
  concrete `FormRequest` is mapped to a column or listed in `unboundStringFields()` *with a reason*.
  A new capped field that is neither fails. A stale allowlist entry fails too.
- **REQ-4** — An inline `$request->validate()` array in a controller may not cap a column-backed
  field with a hand-written number; it reads `ColumnLimits` (or moves to a `FormRequest` / the shared
  trait). Covered by a source scan targeting the *literal* — a derived cap (e.g.
  `ChunkedUploadController::initiate`'s `filename`) is fine.
- **REQ-5** — `assets.copyright` and `assets.copyright_source` are `varchar(500)` (the width the UI
  and API contract promise; `copyright_source` holds a URL/reference).
- **REQ-6** — `CsvImportService::validateRow()` rejects a cell longer than its target column for
  every bounded field in `UPDATABLE_FIELDS`, comparing `mb_strlen` (MySQL counts characters). The
  row is reported and skipped via the existing preview/import error path. `alt_text` / `caption`
  (`TEXT`) are not bounded here.
- **REQ-7** — A `maxlength=` that **is a column width** is rendered from `ColumnLimits`, never a
  literal. A deliberately tighter product cap stays a literal and must match its rule (`alt_text`
  500, `caption` 1000, passkey `name` 100). A field with a `maxlength` also carries a live
  `<x-char-counter>` showing the count against the limit. Why: `maxlength` alone silently stops
  accepting keystrokes.
- **REQ-8** — Client-side limits are a **usability** guarantee only. The server-side rule remains
  the authority; no server check may be relaxed because the browser also enforces it.
- **REQ-9** — Where a shared cap constant exists it is used everywhere: tag names are capped at
  `Tag::MAX_NAME_LENGTH` on creation *and* rename.
- **REQ-10** — Every column holding an S3 object key is **1024 characters**: `assets.s3_key`,
  `thumbnail_s3_key`, `resize_{s,m,l}_s3_key` and `upload_sessions.s3_key` (S3's limit is 1024
  bytes ≤ 1024 chars). Derived keys are longer than their source, so they carry the same width.
- **REQ-11** — A 1024-char utf8mb4 `varchar` exceeds InnoDB's 3072-byte index key, so
  `assets.s3_key` has **no `UNIQUE` of its own**. Uniqueness lives on `assets.s3_key_hash`
  (`char(64)`, sha256 via `App\Support\S3KeyHash`, `NOT NULL`, `assets_s3_key_hash_unique`),
  maintained by a `saving` hook on `Asset` — never a MySQL generated column (SQLite has no `SHA2()`).
  A duplicate is reported against **`s3_key`**, not the surrogate. Any write bypassing Eloquent
  events must set the hash itself.
- **REQ-12** — The two indexes containing `s3_key` use a **255-character prefix** on MySQL/MariaDB
  (`assets_s3_key_index`; `assets_folder_filter_index` as `(deleted_at, s3_key(255), updated_at)`);
  SQLite indexes the whole column. `assets_s3_key_index` is required: it is the only index serving
  exact-match `s3_key` lookups.
- **REQ-13** — `assets.filename` and `upload_sessions.filename` are **500 characters**, and every
  upload path validates the incoming name against that width (`App\Rules\BoundedFilename` on direct
  and replace; a `ColumnLimits` `max:` on chunked and tools). The column stores
  `getClientOriginalName()` verbatim, independent of `keep_original_filename`. 500 also bounds the
  derived keys: deepest legal `folder` (356) + filename gives `thumbnails/L/{folder}/{basename}.{ext}`
  ≈ 871 chars, inside 1024 — raising either cap requires redoing that arithmetic.

## Technical design

### Contract / public interface

```
App\Support\ColumnLimits::CHARS               // array<string, array<string,int>>  table => column => chars
App\Support\ColumnLimits::TEXT_BYTES          // array<string, array<string,int>>  TEXT-family byte capacity
App\Support\ColumnLimits::for(string $table, string $column): int        // throws on unknown
App\Support\ColumnLimits::fitsText(string $table, string $column, int $chars): bool
App\Support\S3KeyHash::of(string $s3Key): string                        // sha256 hex, 64 chars — REQ-11
App\Services\CsvImportService::validateRow(array $row): array           // += over-length row errors
resources/views/components/char-counter.blade.php                       // <x-char-counter for="copyright" :max="…">
resources/js/char-counter.js                                            // wires [data-char-counter]; side-effect import in app.js
```

The audit's helpers live in the test, not `app/`: `ruleFacts()`, `formRequestClasses()`,
`schemaBackedFields()`, `unboundStringFields()`. `charCapped` is true only for a `string` rule with
a `max:` and no `array`/`file`/`integer`/`numeric` rule (`max:` is unit-dependent in Laravel).

### Data shapes

```yaml
# ColumnLimits::CHARS — declared varchar widths (see the class for the live values)
assets.filename: 500           # REQ-13
assets.license_type: 255
assets.copyright: 500          # REQ-5
assets.copyright_source: 500   # REQ-5
assets.s3_key: 1024            # REQ-10; uniqueness on s3_key_hash — REQ-11
assets.thumbnail_s3_key: 1024
assets.resize_s_s3_key: 1024
assets.resize_m_s3_key: 1024
assets.resize_l_s3_key: 1024
upload_sessions.s3_key: 1024
upload_sessions.staging_s3_key: 1024  # chunked-upload REQ-4
upload_sessions.filename: 500  # REQ-13
tags.name: 255                 # rules cap tighter, at Tag::MAX_NAME_LENGTH
users.name: 255
users.email: 255

# ColumnLimits::TEXT_BYTES — compared via fitsText()
assets.alt_text: 65535
assets.caption: 65535
```

### Layer touchpoints & ordering

```
migration / FormRequest or rules trait / Blade maxlength + char-counter — all read ColumnLimits

request → FormRequest::rules() → 422 with a keyed message   ← the authority (REQ-8)
       ↳ browser maxlength + counter                        ← advisory only
       ↳ driver rejection                                   ← error-handling.md
```

CSV import has no `FormRequest`: `ImportController::preview` and `::import` both call
`CsvImportService::validateRow()` (csv-export-import.md REQ-6 requires identical validation).
`ChunkedUploadController::complete` uses the `HasUploadMetadataRules` trait rather than a
`FormRequest` so its `authorize('create', Asset::class)` still runs before validation.

### Persistence

- Copyright widening: `database/migrations/2026_08_05_*_widen_copyright_columns_on_assets_table.php`.
  `->change()` must restate `->nullable()` on MySQL.
- S3-key widening, two migrations (MySQL DDL is not transactional):
  `2026_08_06_100000_add_s3_key_hash_to_assets_table.php` (add nullable → backfill → unique index)
  and `2026_08_06_100001_widen_s3_key_columns_on_assets_table.php` (top-up → drop both `s3_key`
  indexes + `->change()` five columns + hash `NOT NULL` → recreate indexes at prefix width → widen
  `upload_sessions.s3_key`). Load-bearing orderings: the hash unique exists **before** the old
  `assets_s3_key_unique` is dropped; the hash is nullable until backfilled; index drops are declared
  **first in the same closure** as `->change()` (MySQL errno 1071; SQLite rebuild); the backfill
  uses `DB::table` + `chunkById` (covers trashed rows; no offset skipping).
- Filename widening: `database/migrations/2026_08_06_110000_widen_filename_columns.php`
  (`assets_filename_index` at 2000 bytes needs no restructuring).

## Scenarios (BDD)

```gherkin
Scenario: A copyright at the column limit is saved in full
  Given an asset and a copyright of exactly ColumnLimits::for('assets', 'copyright') characters
  When it is submitted to PATCH /assets/{id}
  Then the response is a redirect and the stored value is that many characters long
# pinned by: tests/Feature/AssetTest.php

Scenario: A copyright one character over the limit is a keyed 422, never a 500
  Given an asset and a copyright one character longer than the column accepts
  When it is submitted to PATCH /assets/{id} as JSON
  Then the response status is 422 with a validation error on "copyright"
# pinned by: tests/Feature/AssetTest.php, tests/Feature/ValidationLimitsTest.php

Scenario: No validation rule permits more characters than its column accepts
  Given every concrete FormRequest under app/Http/Requests
  When each character-capped rule is compared with its column's declared width
  Then no rule's max exceeds that width
# pinned by: tests/Feature/ValidationLimitsTest.php

Scenario: An unmapped capped field, or a stale allowlist entry, fails the audit
  Given a character-capped rule in neither the column map nor the allowlist,
    or an unboundStringFields() entry naming a field that no longer exists
  When the audit runs
  Then it fails naming that field
# pinned by: tests/Feature/ValidationLimitsTest.php

Scenario: A controller may not inline-validate a column-backed field
  Given a $request->validate() array in a controller naming copyright or license_type
  When the audit runs
  Then it fails, directing the rule into a FormRequest or the shared trait
# pinned by: tests/Feature/ValidationLimitsTest.php

Scenario: The audit distinguishes a character cap from an array, file or integer cap
  Given the rule sets "array|max:500", "file|max:512000" and "integer|max:524288000"
  When ruleFacts() classifies them
  Then none is reported as a character cap
# pinned by: tests/Feature/ValidationLimitsTest.php

Scenario: A CSV cell longer than its column is reported and the row is skipped
  Given an import CSV whose copyright cell exceeds the column width
  When the preview and the import run
  Then that row carries a length error, no update is applied, and no driver error occurs
# pinned by: tests/Unit/CsvImportServiceTest.php, tests/Feature/ImportTest.php

Scenario: An upload filename is bounded by its column
  Given a file whose name is exactly assets.filename characters long, and one a character longer
  When each is posted to POST /assets
  Then the first is stored in full and the second is a 422 on that file
# pinned by: tests/Feature/AssetTest.php

Scenario: The filename cap keeps every derived key inside the s3_key column
  Given the deepest legal folder nesting and a filename at its cap
  When the longest derived key is computed
  Then it fits within assets.resize_l_s3_key
# pinned by: tests/Feature/AssetTest.php

Scenario: The s3_key surrogate tracks its column on every write path
  Given an asset created through the factory, through Asset::create() and then moved
  When each write completes
  Then s3_key_hash equals S3KeyHash::of the asset's current s3_key
# pinned by: tests/Feature/S3KeyWidthTest.php

Scenario: A duplicate s3_key is refused and reported against s3_key, not the surrogate
  Given an asset whose s3_key is already taken
  When a second asset is created with the same key
  Then the driver rejects it and the classified error names "s3_key"
# pinned by: tests/Feature/S3KeyWidthTest.php, tests/Unit/DatabaseErrorTest.php

Scenario: The restructured indexes survive the table rebuild
  Given a freshly migrated database
  When the indexes on assets are read back
  Then assets_s3_key_hash_unique, assets_s3_key_index and assets_folder_filter_index all exist
  And assets_s3_key_unique does not
# pinned by: tests/Feature/S3KeyWidthTest.php

Scenario: A tag created at the full name length can still be renamed
  Given a tag whose name is Tag::MAX_NAME_LENGTH characters long
  When it is renamed via PATCH /tags/{tag}
  Then the response is successful
# pinned by: tests/Feature/TagTest.php

# — browser-level (see e2e-testing.md for the harness) —

Scenario: The copyright field accepts and persists its full documented length
  Given the edit page of an asset
  When a copyright of the full column width is entered and saved
  Then the detail page shows the whole value and no error is shown
# pinned by: tests/e2e/asset-detail.spec.js

Scenario: The upload page's copyright fields show their limit as it is approached
  Given the batch metadata panel on the upload page
  When a copyright of the full column width is typed into it
  Then a counter reports that count against the column width
# pinned by: tests/e2e/upload-metadata.spec.js
```

## Tests & verification

- Feature: `tests/Feature/ValidationLimitsTest.php` (audit + canary), `tests/Feature/AssetTest.php`,
  `tests/Feature/ApiTest.php` (limit / limit+1 on web and API), `tests/Feature/ImportTest.php` +
  `tests/Unit/CsvImportServiceTest.php` (REQ-6), `tests/Feature/TagTest.php` (REQ-9),
  `tests/Feature/S3KeyWidthTest.php` (REQ-10–12) — `php artisan config:clear && php artisan test`.
- E2E: `tests/e2e/asset-detail.spec.js`, `tests/e2e/upload-metadata.spec.js` — `npm run test:e2e`.
  `database/e2e.sqlite` is equally blind to `varchar` length.
- Against a real MariaDB/MySQL: `php artisan db:verify-schema --strict` — the only check of declared
  widths, row format and index-key limits ([`maintenance-commands.md`](maintenance-commands.md) REQ-6).
- Manual: the widening migration is not transactional on MySQL; if it dies between index drop and
  recreate, re-run the two `DB::statement()` index creations. Run it in a window.
- Style: `./vendor/bin/pint --test`

## Open questions / future

- **`ColumnLimits` is not derived from the schema** (SQLite emits bare `varchar`). Closing it needs
  a MariaDB service in CI comparing `Schema::getColumns()` to `ColumnLimits::CHARS`; do not land a
  driver-skipping version. Until then a migration width literal drifts undetected.
- **Characters vs bytes for S3 keys:** `max:1024` counts characters; keys imported by
  `DiscoverController` could be multibyte and exceed S3's byte limit (fails with an S3 error).
- **`ORDER BY s3_key` is a filesort** under the REQ-12 prefix index; if it hurts, sort by `filename`.
- **Two capped inputs lack a counter** (REQ-7 gap): passkey `name`
  (`resources/views/profile/partials/passkeys-form.blade.php`) and the upload page's batch tag input.
- **Nothing enforces REQ-7 mechanically** — no scan of `resources/views/**` pairs `maxlength=` with
  `<x-char-counter>`.
- **Uniqueness depends on Eloquent events** (REQ-11): raw inserts, `saveQuietly()` or
  `withoutEvents()` could write a duplicate `s3_key`; none exist in `app/`, nothing enforces it.
- `TagInputParser` / `tag-input-core.js` drop an over-length tag name rather than refusing it; the
  server-side parser stays silent.
