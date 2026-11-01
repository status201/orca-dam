# CSV export & import

```yaml
id: csv-export-import
status: implemented
version: 4
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

Export gives admins a full-fidelity spreadsheet snapshot of the library (every field, the three
tag categories separated, computed URLs) for reporting or bulk external editing. Import is the
return path: a pasted CSV (typically a round-tripped export) is diffed against the DB in a
**preview** step before anything is written.

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
- **REQ-5** — A row failing `validateRow()` (invalid `license_type`, a malformed date in any
  `DATE_FIELDS` cell, or a cell longer than its target column) is skipped entirely during
  `import` (no partial update) and reported in `errors[]`. The length check compares `mb_strlen`
  against `ColumnLimits` for every bounded `UPDATABLE_FIELDS` cell — CSV is the one write path
  with no `FormRequest` ([`input-validation.md`](input-validation.md) REQ-6). `alt_text`/`caption`
  are `TEXT` and not bounded here.
- **REQ-6** — `preview` and `import` use the *same* `CsvImportService` methods
  (`parseCsv`, `calculateChanges`/`validateRow`), so the preview a user
  approves is guaranteed to match what `import` actually does.
- **REQ-7** — The export page's **File Type** options are the canonical `Asset::typeCategories()`
  values, narrowed to the categories the library contains, and `export` rejects any other value
  (422). Why: `scopeOfType` ignores unknown values ([`asset-model.md`](asset-model.md) REQ-6), so
  a raw mime prefix would export the entire library while appearing to filter it.

## Technical design

### Contract / public interface

Routes (`routes/web.php`, admin-gated): `GET export` / `POST export`
(`ExportController::index`/`export`); `GET import`, `POST import/preview`,
`POST import/import` (`ImportController`).

```yaml
CsvExportService::generateHeaders(): array      # the 34 columns below
CsvExportService::formatRow(Asset $asset): array # tag columns joined with ', '
CsvExportService::sanitizeCell($value)           # REQ-2, applied to every cell
CsvImportService::parseCsv(string $csvData): array
  # CRLF/CR/LF-tolerant, trims header whitespace, skips blank lines, pads missing trailing columns with ''
CsvImportService::calculateChanges(Asset, array $row): array
  # per UPDATABLE_FIELDS: trimmed CSV value vs current (dates as Y-m-d); user_tags/reference_tags as {add: "..."}
CsvImportService::validateRow(array $row): array   # error strings (REQ-5); license_type ∈ ALLOWED_LICENSE_TYPES
CsvImportService::UPDATABLE_FIELDS: [filename, alt_text, caption, license_type, license_expiry_date,
                                     date_obtained, copyright, copyright_source]   # the only columns import writes
CsvImportService::DATE_FIELDS: [license_expiry_date, date_obtained]   # drives format check + diff
```

### Data shapes

```yaml
# CsvExportService::generateHeaders() — 34 columns, in order.
# A new column is APPENDED, never inserted: the export is parsed positionally downstream.
id, s3_key, filename, mime_type, size, etag, width, height,
thumbnail_s3_key, resize_s_s3_key, resize_m_s3_key, resize_l_s3_key,
alt_text, caption, license_type, license_expiry_date, copyright, copyright_source,
user_id, user_name, user_email, last_modified_by_id, last_modified_by_name,
user_tags, ai_tags, reference_tags,
url, thumbnail_url, resize_s_url, resize_m_url, resize_l_url,
created_at, updated_at, date_obtained

# ImportController::preview response
matched / unmatched / skipped / total: int
results:
  - row: int                 # 1-indexed CSV line (header = row 1)
    match_value: string
    status: matched|not_found
    asset: {id, filename, thumbnail_url, s3_key}   # when matched
    changes: object            # calculateChanges() shape
    errors: string[]           # validateRow() shape

# ImportController::import response
updated / skipped: int
errors: [{row, match_value, errors: string[]}]
```

### Layer touchpoints & ordering

```
export(): Asset query (+user/tags/modifier, filtered by folder/type/tags) → streamed CSV
preview(): parseCsv() → per row match → [unmatched] not_found | [matched] calculateChanges() + validateRow()
import(): parseCsv() → per row match → [unmatched] skip → validateRow() → [errors] skip + errors[]
  → DB::transaction: non-empty UPDATABLE_FIELDS + last_modified_by
    → TagInputParser::parse(user_tags|reference_tags) → resolve*TagIds() → syncTagsWithAttribution(.., 'user'|'reference')
```

## Scenarios (BDD)

```gherkin
Scenario: Only admins can access export
  Given an editor or guest
  When they access the export page
  Then access is denied (redirect to login, or 403)
# pinned by: tests/Feature/ExportTest.php

Scenario: Export columns and tag separation
  Given assets with tags of all three types
  When export is downloaded
  Then the CSV header contains exactly the documented 34 columns
  And user_tags, ai_tags and reference_tags each contain only their own type
# pinned by: tests/Feature/ExportTest.php, tests/Unit/CsvExportServiceTest.php

Scenario: Export filters by file type, folder and tags
  Given assets of mixed types/folders/tags
  When export is called with file_type/folder/tags filters
  Then only matching assets appear (file_type=document gives only the PDF)
  And a filter matching nothing yields only the header row
# pinned by: tests/Feature/ExportTest.php

Scenario: The File Type filter only accepts real categories (REQ-7)
  Given a library holding a PDF, an image and a video
  Then the export page offers document, image and video, never a raw mime prefix
  And export with file_type=application is a 422 and exports nothing
# pinned by: tests/Feature/ExportTest.php

Scenario: Export neutralizes spreadsheet formula injection
  Given a cell value starting with =, +, -, @, tab, or carriage return
  Then sanitizeCell() prefixes it with a single quote
# pinned by: tests/Unit/CsvExportServiceTest.php

Scenario: Preview matches by s3_key or filename and reports unmatched rows
  Given a CSV and match_field "s3_key" or "filename"
  Then rows are matched against that column
  And a row with no matching asset is reported as "not_found", not treated as fatal
# pinned by: tests/Feature/ImportTest.php

Scenario: Preview validates rows and reports only real changes
  Given a row with an unrecognized license_type or a malformed license_expiry_date/date_obtained
  Then validateRow() reports the error
  And a row whose values equal the current asset reports no changes
# pinned by: tests/Feature/ImportTest.php, tests/Unit/CsvImportServiceTest.php

Scenario: Import writes only non-empty cells and never removes tags
  Given a row with some empty and some populated updatable fields, and new user_tags
  When import runs
  Then only populated fields are written, and pre-existing tags remain attached
  And new tags are created lowercased while existing ones are reused by name
  And reference_tags are attached with attached_by "reference", prior reference tags surviving
# pinned by: tests/Feature/ImportTest.php

Scenario: Import skips invalid rows and counts a mixed batch correctly
  Given a CSV mixing valid, unmatched, and invalid rows
  When import runs
  Then invalid rows get no partial update and appear in errors[]
  And updated/skipped counts reflect each row's outcome
# pinned by: tests/Feature/ImportTest.php

# — browser-level (see e2e-testing.md for the harness) —

Scenario: The preview step reports the diff before anything is written
  Given a CSV naming one existing s3_key and one that does not exist
  When Preview Import is clicked
  Then the totals show one matched and one not found
  And the matched row names its asset, the missing key is listed separately, and nothing changed
# pinned by: tests/e2e/csv-import-export.spec.js

Scenario: Importing writes the previewed metadata and adds tags
  Given a previewed CSV with one matched row, onto an asset that already carries a tag
  When Import is clicked
  Then the result reports one updated and one skipped, with no errors
  And the detail page shows the imported value and both tags
# pinned by: tests/e2e/csv-import-export.spec.js

Scenario: The wizard refuses a CSV missing the match column and can start over
  Given a CSV whose header lacks the selected match field
  When Preview Import is clicked
  Then an error is shown and the wizard stays on the paste step
  And after a completed import, Start Over clears the CSV and returns to the paste step
# pinned by: tests/e2e/csv-import-export.spec.js

Scenario: Filtered exports download only matching assets
  Given the export page with its tag list loaded from the API
  When a tag carried by exactly one asset is selected and the export submitted
  Then orca-assets-export-<timestamp>.csv downloads with that asset only
  And choosing Document in File Type downloads only the PDF
  And Reset Filters clears a chosen folder filter
# pinned by: tests/e2e/csv-import-export.spec.js
```

## Tests & verification

- Feature: `tests/Feature/ExportTest.php`, `tests/Feature/ImportTest.php`
- Unit: `tests/Unit/CsvExportServiceTest.php`, `tests/Unit/CsvImportServiceTest.php`
- E2E: `tests/e2e/csv-import-export.spec.js` — the only place the streamed CSV is read back.
- Run: `php artisan config:clear && php artisan test`; `npm run test:e2e`
