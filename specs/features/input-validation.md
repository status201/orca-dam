# Input validation limits

```yaml
id: input-validation
status: implemented
version: 1
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
  - app/Http/Requests/UpdateAssetRequest.php
  - app/Http/Requests/StoreAssetRequest.php
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
- **REQ-7** — Every `maxlength=` on a column-backed input is rendered from `ColumnLimits`, never a
  literal, so the browser, the rule and the column cannot disagree. A `maxlength` alone is not
  feedback — it silently stops accepting keystrokes — so each such field carries a live character
  counter (`<x-char-counter>`) that shows the count against the limit and warns as it approaches.
- **REQ-8** — Client-side limits are a **usability** guarantee, not a security or integrity one.
  Any HTTP client bypasses the browser, so the server-side rule remains the authority and no
  server-side check may be relaxed on the grounds that the browser also enforces it.
- **REQ-9** — Where a shared cap already exists as a constant it is used everywhere: tag names are
  capped at `Tag::MAX_NAME_LENGTH` on creation *and* rename. A tag creatable at 100 characters that
  a 50-character rename rule then refuses to rename is a trap, not a tighter cap.

## Technical design

### Contract / public interface

```
App\Support\ColumnLimits::CHARS               // array<string, array<string,int>>  table => column => chars
App\Support\ColumnLimits::TEXT_BYTES          // array<string, array<string,int>>  TEXT-family byte capacity
App\Support\ColumnLimits::for(string $table, string $column): int        // throws on unknown
App\Support\ColumnLimits::fitsText(string $table, string $column, int $chars): bool
App\Services\CsvImportService::validateRow(array $row): array           // += over-length row errors
resources/views/components/char-counter.blade.php                       // <x-char-counter for="copyright" :max="…">
resources/js/char-counter.js                                            // wires [data-char-counter]; not imported by app.js
```

The audit's own helpers live in the test, not in `app/` — they are a check on production code, not
part of it: `ruleFacts()` (type-aware rule classifier), `formRequestClasses()` (reflection over
`app/Http/Requests/**`), `schemaBackedFields()` (field → `[table, column]`), `unboundStringFields()`
(the reasoned allowlist).

### Data shapes

```yaml
# ColumnLimits::CHARS — declared varchar widths (see the class for the live values)
assets.filename: 255
assets.license_type: 255
assets.copyright: 500          # widened from 255 — REQ-5
assets.copyright_source: 500   # widened from 255 — REQ-5
assets.s3_key: 255
assets.thumbnail_s3_key: 255
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

Only the two widened columns (REQ-5), by
`database/migrations/2026_08_05_*_widen_copyright_columns_on_assets_table.php`. `->change()` is
native in Laravel 13; both statements sit in one `Schema::table()` closure so SQLite rebuilds the
table once. `->change()` re-emits the whole column definition on MySQL, so `->nullable()` must be
restated or the columns silently become `NOT NULL`. Neither column is indexed
(`assets_fulltext` covers `alt_text`/`caption` only, and is itself driver-guarded), so MySQL applies
this without a table copy.

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
```

## Tests & verification

- Feature: `tests/Feature/ValidationLimitsTest.php` — the four audit legs and the canary.
  `php artisan config:clear && php artisan test tests/Feature/ValidationLimitsTest.php`
- Feature: `tests/Feature/AssetTest.php`, `tests/Feature/ApiTest.php` — the boundary pairs
  (limit persists in full; limit+1 is a 422) on the web and API update paths.
- Feature: `tests/Feature/ImportTest.php`, Unit: `tests/Unit/CsvImportServiceTest.php` — REQ-6.
- Feature: `tests/Feature/TagTest.php` — REQ-9's rename regression.
- E2E: `tests/e2e/asset-detail.spec.js` — `npm run test:e2e -- tests/e2e/asset-detail.spec.js`.
  Note `database/e2e.sqlite` is **as blind to `varchar` length as the unit DB**: this scenario
  proves the promise end-to-end, it cannot prove the column is wide enough.
- Style: `./vendor/bin/pint --test`
- **Manual check (no test in CI can make it).** CI provisions no MariaDB service and SQLite reports
  a bare `varchar` with no length, so the declared widths in `ColumnLimits` are unverifiable from
  the suite. After migrating a real MariaDB instance, confirm the columns agree:
  `SHOW CREATE TABLE assets` → `copyright varchar(500)`, `copyright_source varchar(500)`.

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
- **The derived-key budget is unbounded.** `folder` and `filename` both feed `assets.s3_key`
  (`varchar(255)`), and the derived keys are longer still — `S3Service` builds
  `thumbnails/L/{folder}/{uuid}.{ext}` into `resize_l_s3_key`, also `varchar(255)`, leaving roughly
  195 characters for the folder path. Capping `folder` at 100 (matching `FolderController`'s
  creation rule) removes the reachable half. The remainder — `keep_original_filename` with a
  ~200-character original name — needs the *computed* key length asserted in
  `S3Service::uploadFile()`, which is a behaviour change with its own scenarios.
- `TagInputParser` and `tag-input-core.js` still *drop* an over-length tag name rather than
  refusing it; the client now toasts the rejection, but the server-side parser remains silent.
