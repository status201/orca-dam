# CSV export & import

```yaml
id: csv-export-import
status: implemented
version: 2
owner: core
related:
  - architecture
  - asset-model
  - tag-input
  - authorization-policies
source:
  - app/Services/CsvExportService.php
  - app/Services/CsvImportService.php
  - app/Http/Controllers/ExportController.php
  - app/Http/Controllers/ImportController.php
```

## Background / Why

Export gives admins a full-fidelity spreadsheet snapshot of the library
(every field, all three tag categories separated, computed URLs) for
reporting or bulk external editing. Import is the return path: a pasted/uploaded
CSV (typically a round-tripped export) is diffed against the current DB state
in a **preview** step before anything is written, so an admin can catch
mistakes before an `import` mutates anything.

## Requirements

- **REQ-1** — Export is admin-only (`AssetPolicy::export`); import's `preview`
  and `import` endpoints gate on `SystemController` access
  (`$this->authorize('access', SystemController::class)`).
- **REQ-2** — Every CSV cell is passed through `sanitizeCell()`, which
  prefixes a leading `=`, `+`, `-`, `@`, tab, or carriage-return with a single
  quote — neutralizing spreadsheet formula injection in exported data (a
  string cell only; non-string values pass through unchanged).
- **REQ-3** — Import matches existing assets by **either** `s3_key` or
  `filename`, selected per-request via `match_field`; unmatched rows are
  reported, never silently dropped.
- **REQ-4** — Import never *removes* existing tags — `user_tags`/
  `reference_tags` columns are additive (`syncTagsWithAttribution`'s
  `syncWithoutDetaching` semantics), and only non-empty CSV cells overwrite a
  field (`UPDATABLE_FIELDS`) — an empty cell leaves the current value alone.
- **REQ-5** — A row failing `validateRow()` (invalid `license_type`, malformed
  `license_expiry_date`, or a cell longer than its target column) is skipped
  entirely during `import` (no partial field updates for that row), and reported
  in the response's `errors[]`. The length check compares `mb_strlen` against
  `ColumnLimits` for every bounded `UPDATABLE_FIELDS` cell — CSV is the one write
  path with no `FormRequest`, so without it an over-long cell reached the driver
  and turned the whole import into a 500 that named no row
  ([`input-validation.md`](input-validation.md) REQ-6). `alt_text`/`caption` are
  `TEXT` and are not bounded here.
- **REQ-6** — `preview` and `import` use the *same* `CsvImportService` methods
  (`parseCsv`, `calculateChanges`/`validateRow`), so the preview a user
  approves is guaranteed to match what `import` actually does.
- **REQ-7** — The export page's **File Type** options are the canonical
  `Asset::typeCategories()` values, narrowed to the categories the library
  actually contains, and `export` rejects any other value (422). Previously the
  options were built from raw mime prefixes (`explode('/', $mime)[0]`), so
  picking "Application" sent `application` — a value `scopeOfType` does not
  recognise and therefore ignores ([`asset-model.md`](asset-model.md) REQ-6),
  which exported the *entire* library while appearing to filter it.

## Technical design

### Contract / public interface

Routes (`routes/web.php`, admin-gated): `GET export` / `POST export`
(`ExportController::index`/`export`); `GET import`, `POST import/preview`,
`POST import/import` (`ImportController`).

`CsvExportService::generateHeaders(): array` — 33 fixed columns (see Data
shapes). `CsvExportService::formatRow(Asset $asset): array` — one row per
asset, tag columns joined with `, `. `CsvExportService::sanitizeCell($value)`
— formula-injection guard (REQ-2), applied to every cell.

`CsvImportService::parseCsv(string $csvData): array` — CRLF/CR/LF-tolerant,
trims header whitespace, skips blank lines, pads missing trailing columns with
`''`. `CsvImportService::calculateChanges(Asset, array $row): array` — per
`UPDATABLE_FIELDS`, diffs the trimmed CSV value against the current asset
value (date fields compared as `Y-m-d` strings); also surfaces
`user_tags`/`reference_tags` as additive `{add: "..."}` entries.
`CsvImportService::validateRow(array $row): array` — returns human-readable
error strings for an invalid `license_type` (must be one of
`ALLOWED_LICENSE_TYPES`) or a malformed `license_expiry_date`.
`CsvImportService::UPDATABLE_FIELDS` — the exhaustive whitelist of columns
`import()` is allowed to write: `filename`, `alt_text`, `caption`,
`license_type`, `license_expiry_date`, `copyright`, `copyright_source`.

### Data shapes

```yaml
# CsvExportService::generateHeaders() — 34 columns, in order
id, s3_key, filename, mime_type, size, etag, width, height,
thumbnail_s3_key, resize_s_s3_key, resize_m_s3_key, resize_l_s3_key,
alt_text, caption, license_type, license_expiry_date, copyright, copyright_source,
user_id, user_name, user_email, last_modified_by_id, last_modified_by_name,
user_tags, ai_tags, reference_tags,
url, thumbnail_url, resize_s_url, resize_m_url, resize_l_url,
created_at, updated_at

# ImportController::preview response
matched / unmatched / skipped / total: int
results:
  - row: int                 # 1-indexed CSV line (header = row 1)
    match_value: string
    status: matched|not_found
    asset: {id, filename, thumbnail_url, s3_key}   # when matched
    changes: object            # CsvImportService::calculateChanges() shape
    errors: string[]           # CsvImportService::validateRow() shape

# ImportController::import response
updated / skipped: int
errors: [{row, match_value, errors: string[]}]
```

### Layer touchpoints & ordering

```
export(): Asset query (+with user/tags/modifier, filtered by folder/type/tags)
  → streamed CSV response: generateHeaders() header row, formatRow() per asset

preview(): parseCsv() → per row: match by s3_key|filename
  → [unmatched] record not_found
  → [matched] calculateChanges() + validateRow() → record with changes/errors

import(): parseCsv() → per row: match by s3_key|filename
  → [unmatched] skip
  → validateRow() → [errors] skip + record in errors[]
  → DB::transaction: update UPDATABLE_FIELDS (non-empty cells only) + last_modified_by
    → TagInputParser::parse(user_tags) → Tag::resolveUserTagIds() → syncTagsWithAttribution(..., 'user')
    → TagInputParser::parse(reference_tags) → Tag::resolveReferenceTagIds() → syncTagsWithAttribution(..., 'reference')
```

## Scenarios (BDD)

```gherkin
Scenario: Only admins can access export
  Given an editor or guest
  When they access the export page
  Then access is denied (redirect to login, or 403)
# pinned by: tests/Feature/ExportTest.php

Scenario: Export includes all required columns
  Given assets exist
  When export is downloaded
  Then the CSV header contains exactly the documented 34 columns
# pinned by: tests/Feature/ExportTest.php, tests/Unit/CsvExportServiceTest.php

Scenario: Export separates user, AI, and reference tags into distinct columns
  Given an asset with tags of all three types
  Then user_tags, ai_tags, and reference_tags each contain only their own type
# pinned by: tests/Feature/ExportTest.php, tests/Unit/CsvExportServiceTest.php

Scenario: Export can filter by file type, folder, and tags
  Given assets of mixed types/folders/tags
  When export is called with file_type/folder/tags filters
  Then only matching assets appear in the CSV
# pinned by: tests/Feature/ExportTest.php

Scenario: The File Type options are categories the filter actually understands
  Given a library holding a PDF, an image and a video
  When the export page is opened
  Then the options offered are document, image and video
  And no option is a raw mime prefix such as "application"
# pinned by: tests/Feature/ExportTest.php

Scenario: A document-filtered export excludes every non-document
  Given a library holding a PDF, an image and a video
  When export is called with file_type=document
  Then only the PDF appears in the CSV
# pinned by: tests/Feature/ExportTest.php

Scenario: A file type outside the known categories is rejected
  Given a request with file_type=application
  When export is called
  Then it fails validation with 422
  And nothing is exported, rather than everything (REQ-7)
# pinned by: tests/Feature/ExportTest.php

Scenario: Export with no matching assets produces only the header row
  Given a filter matching zero assets
  Then the CSV contains only the header row
# pinned by: tests/Feature/ExportTest.php

Scenario: Export neutralizes spreadsheet formula injection
  Given a cell value starting with =, +, -, @, tab, or carriage return
  Then sanitizeCell() prefixes it with a single quote
# pinned by: tests/Unit/CsvExportServiceTest.php

Scenario: Preview matches assets by s3_key or by filename
  Given a CSV and match_field of either "s3_key" or "filename"
  Then rows are matched against the corresponding asset column
# pinned by: tests/Feature/ImportTest.php

Scenario: Preview reports unmatched rows without erroring
  Given a CSV row whose match value has no corresponding asset
  Then it is reported with status "not_found", not treated as fatal
# pinned by: tests/Feature/ImportTest.php

Scenario: Preview flags an invalid license_type or license_expiry_date
  Given a row with an unrecognized license_type or a malformed date
  Then validateRow() reports the corresponding error message
# pinned by: tests/Feature/ImportTest.php, tests/Unit/CsvImportServiceTest.php

Scenario: Preview detects only actually-changed fields
  Given a row whose values match the current asset exactly
  Then calculateChanges() reports no changes for that field
# pinned by: tests/Feature/ImportTest.php, tests/Unit/CsvImportServiceTest.php

Scenario: Import updates asset fields from non-empty CSV cells only
  Given a row with some empty and some populated updatable fields
  When import runs
  Then only the populated fields are written; empty ones preserve the existing value
# pinned by: tests/Feature/ImportTest.php

Scenario: Import never removes existing tags
  Given an asset with existing user tags not mentioned in the CSV row
  When import adds new user_tags
  Then the pre-existing tags remain attached
# pinned by: tests/Feature/ImportTest.php

Scenario: Import creates and lowercases new user tags, reusing existing ones by name
  Given a CSV row with new and existing tag names in mixed case
  When import runs
  Then tags are created lowercased and existing tags are reused rather than duplicated
# pinned by: tests/Feature/ImportTest.php

Scenario: Import creates reference tags from the CSV and preserves existing ones
  Given a reference_tags column
  When import runs
  Then reference tags are created/attached with attached_by "reference", and prior reference tags survive
# pinned by: tests/Feature/ImportTest.php

Scenario: Import skips rows failing validation
  Given a row with an invalid license_type or date format
  When import runs
  Then that row is skipped and reported in errors[], with no partial update applied
# pinned by: tests/Feature/ImportTest.php

Scenario: Import handles a batch with mixed matched/unmatched/invalid rows correctly
  Given a CSV mixing valid, unmatched, and invalid rows
  When import runs
  Then updated/skipped counts and errors[] correctly reflect each row's outcome
# pinned by: tests/Feature/ImportTest.php

# — browser-level (see e2e-testing.md for the harness) —

Scenario: The preview step reports the diff before anything is written
  Given a CSV naming one existing s3_key and one that does not exist
  When Preview Import is clicked
  Then the totals show one matched and one not found
  And the matched row names the asset it resolved to, the missing key is listed
    separately, and no asset has changed yet
# pinned by: tests/e2e/csv-import-export.spec.js

Scenario: Importing writes the previewed metadata onto the asset
  Given a previewed CSV with one matched row
  When Import is clicked
  Then the result step reports one updated and one skipped, with no errors
  And the asset's detail page shows the imported value
# pinned by: tests/e2e/csv-import-export.spec.js

Scenario: Imported tags are added to an asset without removing its existing tags
  Given an asset that already carries a tag
  When a CSV imports a different user_tag onto it
  Then the asset carries both
# pinned by: tests/e2e/csv-import-export.spec.js

Scenario: A CSV missing the match column is refused rather than importing nothing
  Given a CSV whose header lacks the selected match field
  When Preview Import is clicked
  Then an error is shown and the wizard stays on the paste step
# pinned by: tests/e2e/csv-import-export.spec.js

Scenario: Start Over resets the wizard
  Given a completed import
  When Start Over is clicked
  Then the pasted CSV is cleared and the paste step is shown again
# pinned by: tests/e2e/csv-import-export.spec.js

Scenario: A tag-filtered export downloads only the matching assets
  Given the export page with its tag list loaded from the API
  When a tag carried by exactly one asset is selected and the export submitted
  Then a CSV named orca-assets-export-<timestamp>.csv downloads
  And it contains that asset and not an asset without the tag
# pinned by: tests/e2e/csv-import-export.spec.js

Scenario: A document-filtered export downloads only the documents
  Given a library holding a PDF, images and a video
  When Document is chosen in File Type and the export submitted
  Then the downloaded CSV contains the PDF and neither the images nor the video
# pinned by: tests/e2e/csv-import-export.spec.js

Scenario: Reset Filters returns the export to exporting everything
  Given a folder filter chosen on the export page
  When Reset Filters is clicked
  Then the filter is cleared
# pinned by: tests/e2e/csv-import-export.spec.js
```

## Tests & verification

- Feature: `tests/Feature/ExportTest.php`, `tests/Feature/ImportTest.php`
- Unit: `tests/Unit/CsvExportServiceTest.php`, `tests/Unit/CsvImportServiceTest.php`
- E2E: `tests/e2e/csv-import-export.spec.js` — the import wizard's three steps and
  the export download, which is the only place the streamed CSV is read back.
- Run: `php artisan config:clear && php artisan test`; `npm run test:e2e`
