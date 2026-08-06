# Input validation limits

```yaml
id: input-validation
status: implemented
version: 4
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

A user typed a 300-character copyright into the asset edit form and got a bare HTTP 500. The
column `assets.copyright` was `varchar(255)`, but every rule that guards it allowed 500 and the
form's `maxlength=` promised 500 — so validation passed and MariaDB rejected the write with
SQLSTATE 22001 (`strict => true`, `config/database.php`). Four independent rule sites carried the
same wrong number, one of them a hand-copied duplicate of the shared trait.

Nothing could have caught it: the suite runs in-memory SQLite (ADR-008) and SQLite does not enforce
`varchar` length, so the offending write succeeds in every test and in CI. The guard therefore
cannot be a database assertion — it has to compare the **rules** against the **declared** column
widths. This spec owns that comparison, the single place the widths are declared, and the
client-side half that makes a limit visible instead of silent.

The complementary half — what happens when a value nonetheless reaches the driver and is rejected —
is [`error-handling.md`](error-handling.md).

## Requirements

- **REQ-1** — `App\Support\ColumnLimits` is the single source of truth for the declared character
  width of every `varchar` column the application validates into. `ColumnLimits::CHARS[$table][$column]`
  is read by the migration that declares the column, by every validation rule that writes into it,
  and by the view that renders its input. `ColumnLimits::for()` throws on an unknown column rather
  than returning a default — an unnamed column is a bug, not a 255.
- **REQ-2** — **A validation rule may never permit more characters than its column accepts.** A rule
  may be deliberately *tighter* than its column (a 50-character cap on a `varchar(255)` is a product
  decision); it may never be looser. The audit asserts `rule max <= column limit`, and for `TEXT`
  columns compares worst-case utf8mb4 bytes (`ColumnLimits::fitsText()`, 4 bytes per character)
  rather than characters.
- **REQ-3** — The audit is **complete by construction**: every character-capped rule in every
  concrete `FormRequest` is either mapped to a column or listed in the audit's `unboundStringFields()`
  allowlist *with a stated reason*. A new capped string field that is neither fails the suite. The
  allowlist is itself checked for staleness — an entry that no longer matches a live field fails,
  because otherwise it silently pre-approves whatever takes that name next.
- **REQ-4** — An inline `$request->validate()` array in a controller may not cap a column-backed
  field with a hand-written number; it must read `ColumnLimits` (or live in a `FormRequest` /
  the shared rules trait). Inline arrays are invisible to REQ-3's reflection, so they are covered
  by a source scan instead. The scan targets the *literal*, not the inline array as such — a
  derived cap cannot drift, and `ChunkedUploadController::initiate` legitimately validates
  `filename` this way.
- **REQ-5** — `assets.copyright` and `assets.copyright_source` are `varchar(500)`. 500 is the width
  the UI, the API contract and four tests already promised; `copyright_source` holds a URL or
  reference, where 255 is genuinely too tight.
- **REQ-6** — `CsvImportService::validateRow()` rejects a cell longer than its target column for
  every bounded field in `UPDATABLE_FIELDS`, comparing `mb_strlen` (MySQL counts characters; `©`
  costs two bytes). The row is reported and skipped by the existing preview/import error path —
  it never reaches the driver. `alt_text` / `caption` are `TEXT` and are not bounded here.
- **REQ-7** — A `maxlength=` that **is a column width** is rendered from `ColumnLimits`, never a
  literal, so the browser, the rule and the column cannot disagree. A `maxlength` that is a
  deliberately *tighter* product cap stays a literal and must match its validation rule instead —
  `alt_text` (500) and `caption` (1000) are `TEXT` columns whose caps are product decisions, and a
  passkey `name` is capped at 100 against a `varchar(255)` column. Both are legal under REQ-2; what
  is forbidden is a literal that is *claiming* to be the column width. A `maxlength` alone is not
  feedback — it silently stops accepting keystrokes — so a field carrying one also carries a live
  character counter (`<x-char-counter>`) showing the count against the limit and warning as it
  approaches.
- **REQ-8** — Client-side limits are a **usability** guarantee, not a security or integrity one.
  Any HTTP client bypasses the browser, so the server-side rule remains the authority and no
  server-side check may be relaxed on the grounds that the browser also enforces it.
- **REQ-9** — Where a shared cap already exists as a constant it is used everywhere: tag names are
  capped at `Tag::MAX_NAME_LENGTH` on creation *and* rename. A tag creatable at 100 characters that
  a 50-character rename rule then refuses to rename is a trap, not a tighter cap.
- **REQ-10** — Every column holding an S3 object key is **1024 characters** — `assets.s3_key`, the
  four derived keys (`thumbnail_s3_key`, `resize_{s,m,l}_s3_key`) and `upload_sessions.s3_key`. S3's
  own limit is 1024 *bytes*, and a UTF-8 key of 1024 bytes is at most 1024 characters, so the column
  can hold every key S3 will accept. The derived keys are **longer** than the key they come from
  (`thumbnails/L/{folder}/{basename}.{ext}`), so they carry the same width — narrowing them would
  only move the ceiling one step downstream.
- **REQ-11** — Because a 1024-character `varchar` is 4096 bytes at utf8mb4 and InnoDB caps an index
  key at 3072, `assets.s3_key` **cannot carry its own `UNIQUE` constraint**. The invariant lives on
  `assets.s3_key_hash` (`char(64)`, sha256 of the key via `App\Support\S3KeyHash`, `NOT NULL`,
  `assets_s3_key_hash_unique`), maintained by a `saving` hook on `Asset` — never a MySQL generated
  column, because SQLite has no `SHA2()` and the suite would then test a schema production does not
  have. A duplicate is reported against **`s3_key`**, not the surrogate: `s3_key_hash` is a real
  column, so an unaliased error would key the bag on a field no form has and the user would see
  nothing. Any write bypassing Eloquent events must set the hash itself.
- **REQ-12** — The two indexes containing `s3_key` are declared at a **255-character prefix** on
  MySQL/MariaDB (`assets_s3_key_index`, and `assets_folder_filter_index` as
  `(deleted_at, s3_key(255), updated_at)`); SQLite indexes the whole column, which is semantically
  identical because a prefix index is a superset scan plus a recheck. `assets_s3_key_index` is not
  optional — the dropped `UNIQUE` was also the only index serving exact-match lookups on `s3_key`,
  and the composite cannot serve them because it starts with `deleted_at`.
- **REQ-13** — `assets.filename` and `upload_sessions.filename` are **500 characters**, and every
  upload path validates the incoming name against that width (`App\Rules\BoundedFilename` on the
  direct and replace paths, a `max:` from `ColumnLimits` on the chunked and tools paths). The column
  stores `getClientOriginalName()` **verbatim** — sanitising applies to the S3 key, not to this
  column — and the cap is independent of `keep_original_filename`, which only decides whether the
  *key* reuses the name. 500 is chosen to be far past any real filename **and** to bound the derived
  keys: with `folder` at its deepest legal nesting (255 + 1 + 100 = 356), the longest key the app
  constructs is `thumbnails/L/{folder}/{basename}.{ext}` at roughly 871 characters, inside
  `s3_key`'s 1024. Raising either cap without redoing that arithmetic reopens the overflow.
  `assets.filename` is indexed (`assets_filename_index`), but 500 utf8mb4 characters is 2000 bytes,
  inside the 3072 of REQ-12 — so unlike `s3_key` this widening needs no index restructuring.

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

The audit's own helpers live in the test, not in `app/` — they are a check on production code, not
part of it: `ruleFacts()` (type-aware rule classifier), `formRequestClasses()` (reflection over
`app/Http/Requests/**`), `schemaBackedFields()` (field → `[table, column]`), `unboundStringFields()`
(the reasoned allowlist).

### Data shapes

```yaml
# ColumnLimits::CHARS — declared varchar widths (see the class for the live values)
assets.filename: 500           # widened from 255 — REQ-13; indexed, 2000 bytes, no restructuring
assets.license_type: 255
assets.copyright: 500          # widened from 255 — REQ-5
assets.copyright_source: 500   # widened from 255 — REQ-5
assets.s3_key: 1024            # widened from 255 — REQ-10; uniqueness on s3_key_hash — REQ-11
assets.thumbnail_s3_key: 1024  # widened from 255 — REQ-10
assets.resize_s_s3_key: 1024   # newly declared — ColumnLimits::for() used to throw for these three
assets.resize_m_s3_key: 1024
assets.resize_l_s3_key: 1024
upload_sessions.s3_key: 1024   # widened from 255 — REQ-10; unindexed, so no restructuring
upload_sessions.filename: 500  # widened from 255 — REQ-13, so initiate() survives to complete()
tags.name: 255                 # rules cap tighter, at Tag::MAX_NAME_LENGTH — legal under REQ-2
users.name: 255
users.email: 255

# ColumnLimits::TEXT_BYTES — byte capacity, compared via fitsText()
assets.alt_text: 65535
assets.caption: 65535

# ruleFacts() — what the audit extracts from one rule set
charCapped: bool   # a `string` rule with a `max:` and no array/file/integer/numeric rule
max: int|null      # the `max:` argument, whatever its unit
```

`max:` is unit-dependent in Laravel — characters with `string`, element count with `array`,
kilobytes with `file`, a numeric ceiling with `integer`. A blanket `max:\d+` scan is therefore
meaningless; `charCapped` is only true for a genuine character cap.

### Layer touchpoints & ordering

```
migration (reads ColumnLimits)  ─┐
FormRequest / rules trait       ─┼─ all three read the same number
Blade maxlength= + char-counter ─┘

request → FormRequest::rules() → 422 with a keyed message   ← the authority (REQ-8)
       ↳ browser maxlength + counter                        ← earlier, advisory only
       ↳ driver rejection                                   ← error-handling.md, should now be unreachable
```

CSV import is the one path with no `FormRequest`: `ImportController::preview` and
`ImportController::import` both call `CsvImportService::validateRow()`, which is why REQ-6 lives
there and not in the controller — `csv-export-import.md` REQ-6 requires both to validate identically.

`ChunkedUploadController::complete` uses the `HasUploadMetadataRules` trait rather than a
`FormRequest`, deliberately: a `FormRequest` validates *before* the controller body, which would
move its `authorize('create', Asset::class)` after validation and change which failure a caller
sees first.

### Persistence

The two copyright columns (REQ-5), by
`database/migrations/2026_08_05_*_widen_copyright_columns_on_assets_table.php`. `->change()` is
native in Laravel 13; both statements sit in one `Schema::table()` closure so SQLite rebuilds the
table once. `->change()` re-emits the whole column definition on MySQL, so `->nullable()` must be
restated or the columns silently become `NOT NULL`. Neither column is indexed
(`assets_fulltext` covers `alt_text`/`caption` only, and is itself driver-guarded), so MySQL applies
this without a table copy.

The S3-key columns (REQ-10 to REQ-12) need **two** migrations, and that "neither column is indexed"
reasoning is exactly what does not carry over:

```
2026_08_06_100000_add_s3_key_hash_to_assets_table.php
    add s3_key_hash nullable  ->  backfill (chunkById, DB::table)  ->  add the unique index
2026_08_06_100001_widen_s3_key_columns_on_assets_table.php
    top-up  ->  drop both s3_key indexes + ->change() all five columns + s3_key_hash NOT NULL
             ->  recreate the two indexes at prefix width  ->  widen upload_sessions.s3_key
```

Four orderings are load-bearing. The hash's unique index is created **before** the old
`assets_s3_key_unique` is dropped, so there is never an instant without an enforced invariant. The
column is added nullable and only made `NOT NULL` after the backfill, because neither a defaultless
`NOT NULL` column nor a `UNIQUE` over a half-filled one is possible on a populated table. The index
drops sit in the **same closure** as the `->change()` calls and are declared **first** — on MySQL
the widen fails with errno 1071 otherwise, and on SQLite `BlueprintState` only omits an index from
the table rebuild once it has seen its drop command. And the backfill uses `DB::table` with
`chunkById`, not `Asset` with `chunk`: the `SoftDeletes` scope would skip trashed rows (whose
`s3_key` is still occupied and still matched by the `withTrashed()` dedup check), and offset paging
would skip rows because the loop writes the column the `WHERE` filters on.

Splitting into two files is not cosmetic: MySQL DDL is not transactional, so a failure in the second
migration must not cost a second full pass over the table.

Nothing else here persists state: `ColumnLimits` is a constant array, and the audit is a test.

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

Scenario: A new capped string field must be mapped or explained
  Given a character-capped rule that is in neither the column map nor the allowlist
  When the audit runs
  Then it fails naming that field
# pinned by: tests/Feature/ValidationLimitsTest.php

Scenario: An allowlist entry that no longer matches a field fails
  Given an unboundStringFields() entry naming a field that no longer exists
  When the audit runs
  Then it fails, because a stale entry pre-approves whatever takes that name next
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

Scenario: An upload whose filename exceeds the column is a 422, never a driver error
  Given a file whose name is one character longer than assets.filename accepts
  When it is posted to POST /assets
  Then the response is 422 with a validation error on that file
# pinned by: tests/Feature/AssetTest.php

Scenario: A filename at exactly the column limit is stored in full
  Given a file whose name is exactly assets.filename characters long
  When it is uploaded
  Then the response is successful and the stored filename is that many characters
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
  Then the response is successful, because the rename cap equals the creation cap
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

- Feature: `tests/Feature/ValidationLimitsTest.php` — the four audit legs and the canary.
  `php artisan config:clear && php artisan test tests/Feature/ValidationLimitsTest.php`
- Feature: `tests/Feature/AssetTest.php`, `tests/Feature/ApiTest.php` — the boundary pairs
  (limit persists in full; limit+1 is a 422) on the web and API update paths.
- Feature: `tests/Feature/ImportTest.php`, Unit: `tests/Unit/CsvImportServiceTest.php` — REQ-6.
- Feature: `tests/Feature/TagTest.php` — REQ-9's rename regression.
- Feature: `tests/Feature/S3KeyWidthTest.php` — REQ-10 to REQ-12: the surrogate on every write path,
  uniqueness still biting, the aliased error column, and the index set after a rebuild.
  `php artisan config:clear && php artisan test tests/Feature/S3KeyWidthTest.php`
- E2E: `tests/e2e/asset-detail.spec.js` — `npm run test:e2e -- tests/e2e/asset-detail.spec.js`.
  Note `database/e2e.sqlite` is **as blind to `varchar` length as the unit DB**: this scenario
  proves the promise end-to-end, it cannot prove the column is wide enough.
- E2E: `tests/e2e/upload-metadata.spec.js` — REQ-7 on the upload page's batch metadata panel,
  the other half of the two views that render a `ColumnLimits`-backed `maxlength`.
- Style: `./vendor/bin/pint --test`
- **Against a real MariaDB: `php artisan db:verify-schema`.** CI provisions no MariaDB service and
  SQLite reports a bare `varchar` with no length *and* enforces no index key limit, so neither the
  declared widths nor the prefix-index arithmetic in REQ-12 is verifiable from the suite. These were
  a hand-run checklist in this section; they are now that checklist as code
  ([`maintenance-commands.md`](maintenance-commands.md) REQ-6), so run it after migrating a real
  instance. It compares every `ColumnLimits` width and TEXT capacity against `information_schema`,
  asserts the row format is `DYNAMIC` — nothing pins it, `config/database.php` has
  `'engine' => null`, and five `varchar(1024)` columns under `COMPACT` fail with errno 1118 — and
  measures **every** index against the 3072-byte limit rather than only the ones this spec knows
  about. Exit `0` verified, `1` failed, `2` the driver could not answer; `2` is not `0`, so a run
  that checked nothing can never read as a pass.
- **Still genuinely manual**, because it is a property of the deploy rather than of the schema: the
  widening migration is **not transactional on MySQL**. If it dies between the index drops and the
  recreations, the table is left wide and unindexed — `db:verify-schema` will say so on the next run
  — and recovery is re-running the two `DB::statement()` index creations. Run it in a window.

## Open questions / future

- **`ColumnLimits` is not derived from the schema.** It cannot be under SQLite —
  `SQLiteGrammar::typeString()` emits a bare `varchar` with no length, so an introspection-based
  audit would go *vacuously green*, the exact failure mode `security-invariants.md` exists to
  catch. Closing this for real means a MariaDB service in CI and one test comparing
  `Schema::getColumns('assets')` against `ColumnLimits::CHARS`. Deliberately deferred; do **not**
  land a driver-skipping version, because a permanently-skipped test reads as coverage.
- Until then, a migration that declares a width with a literal instead of `ColumnLimits::for()`
  drifts undetected. `recipes/add-a-migration.md` names the step; a source scan over
  `database/migrations/**` for `->string('x', <literal>)` would enforce it.
- ~~**The derived-key budget is unbounded.**~~ **Resolved** by REQ-10: every key column is now 1024,
  the width S3 itself allows, so `folder` (≤100) plus `filename` (≤255) plus the longest derived
  prefix (`thumbnails/L/`) cannot reach the ceiling. The `folder` cap stays, but its justification
  has changed — it is now what keeps `scopeInFolder`'s `LIKE 'folder/%'` range inside REQ-12's
  255-character index prefix, not an overflow guard.
- **The API advertises characters where S3 counts bytes.** `max:1024` counts characters, so a
  multibyte key passing validation could still exceed S3's 1024-*byte* limit. Harmless in practice —
  `S3Service::sanitizeFilename()` strips to `[a-zA-Z0-9\-.]` and `FolderController` to
  `[a-zA-Z0-9_\-]`, so every key the app generates is ASCII — but `DiscoverController` imports keys
  straight from a bucket listing, where another tool's UTF-8 key could arrive. A byte-counting rule
  would close it; the failure today is an S3 error with a message, not a silent truncation.
- **`ORDER BY s3_key` is a filesort now.** REQ-12's prefix index cannot satisfy an ordering over a
  value it does not fully store, so the `s3key_asc`/`s3key_desc` sorts in `Asset::scopeApplySort`
  lost their index. Acceptable on a paginated listing; if it ever hurts, sort by `filename` (still
  `varchar(255)`, fully indexable) rather than re-widening the index.
- **Two capped inputs still have no counter**, so REQ-7's second half is not yet true everywhere:
  the passkey `name` field (`resources/views/profile/partials/passkeys-form.blade.php`, two
  occurrences at `maxlength="100"`) and the batch tag input on the upload page, which has a server
  cap of `Tag::MAX_NAME_LENGTH` and no `maxlength` at all. Both truncate or reject in silence, which
  is the failure REQ-7 exists to remove. Recorded rather than fixed because neither is column-backed
  and neither was in the reported bug's path — but the requirement is written as universal, and it
  is not.
- **Nothing enforces REQ-7 mechanically.** `ValidationLimitsTest` reflects over rules and scans
  controller source; it reads no Blade. A view that renders a `maxlength` with no counter, or a
  literal claiming to be a column width, is caught only by review. A source scan over
  `resources/views/**` pairing every `maxlength=` with a sibling `<x-char-counter>` would close it —
  and would have caught the upload page's missing counters at the time they were missed.
- **Uniqueness now depends on Eloquent events.** REQ-11's hash is set by a `saving` hook, so a raw
  `DB::table('assets')->insert()`, a `saveQuietly()` or a `withoutEvents()` block could write a
  duplicate `s3_key`. There are no such call sites in `app/` today, but nothing enforces that — a
  source scan in `ValidationLimitsTest`'s style would.
- `TagInputParser` and `tag-input-core.js` still *drop* an over-length tag name rather than
  refusing it; the client now toasts the rejection, but the server-side parser remains silent.
