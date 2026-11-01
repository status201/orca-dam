# Bulk operations

```yaml
id: bulk-operations
status: implemented
version: 5
owner: core
related:
  - architecture
  - asset-model
  - asset-trash
  - s3-storage
  - authorization-policies
  - tag-input
source:
  - app/Http/Controllers/AssetBulkController.php
  - app/Policies/AssetPolicy.php
  - app/Support/BulkAssetIds.php
  - app/Http/Concerns/ForceDeletesAssets.php
```

## Background / Why

Bulk actions on the asset grid — tagging, permanent deletion, moving between folders, ZIP
download — operate on a caller-supplied `asset_ids[]` list rather than a filter expression, so
the exact selected set is what's acted on. `bulkMove` and `bulkForceDelete` additionally require
`maintenance_mode` ([`settings.md`](settings.md)) as an explicit "safe window" switch for bulk
mutation.

## Requirements

- **REQ-1** — `bulkAddTags`/`bulkRemoveTags` authorize **per asset**
  (`AssetPolicy::update`) inside the loop, not just once against `Asset::class`
  — a per-asset ownership model could later restrict this without a
  controller change.
- **REQ-2** — `bulkMove` and `bulkForceDelete` require **both** the admin role
  and `maintenance_mode` enabled (`AssetPolicy::move` /
  `AssetPolicy::bulkForceDelete`) — see the role × ability matrix in
  `architecture.md`.
- **REQ-3** — `bulkMove`'s destination folder must be one of the configured S3
  folders (or a subfolder of one) — validated via a closure comparing against
  `FolderRegistry::forPicker()`, rejecting path traversal outside them.
- **REQ-4** — A move updates `s3_key` **and** every derived key that exists by relocating the
  objects, never regenerating them — the relocation rule is [`s3-storage.md`](s3-storage.md)
  REQ-6. Two guards: an asset whose destination key already belongs to another asset (trashed
  included) is skipped and counted `failed` before anything is copied; and a derived column
  changes only when its own copy succeeded.
- **REQ-5** — `bulkDownload` caps at 100 assets and 500MB total size; a
  per-asset S3 fetch failure is skipped (not fatal to the whole ZIP); a ZIP
  with zero successfully-added files returns 422 instead of an empty
  download.
- **REQ-6** — Duplicate filenames within a single ZIP are disambiguated with a
  `_1`, `_2`, … suffix before the extension.
- **REQ-7** — `bulkForceDelete` operates on **live** (non-trashed) assets —
  distinct from `AssetTrashController::bulkForceDeleteTrashed` (see
  [`asset-trash.md`](asset-trash.md)), which has no `maintenance_mode`
  requirement since its inputs are already trashed.

## Technical design

### Contract / public interface

Routes (`routes/web.php`, web-authenticated):

```yaml
POST   assets/bulk/tags:          bulkAddTags         # assets.bulk.tags.add
POST   assets/bulk/tags/remove:   bulkRemoveTags       # assets.bulk.tags.remove
POST   assets/bulk/tags/list:     bulkGetTags          # assets.bulk.tags.list
POST   assets/bulk/move:          bulkMove             # assets.bulk.move — admin + maintenance_mode
DELETE assets/bulk/force-delete:  bulkForceDelete       # assets.bulk.force-delete — admin + maintenance_mode
POST   assets/bulk/download:      bulkDownload          # throttle:bulk-download — upload-policy.md REQ-7
```

`bulkTrash`/`bulkRestore`/`bulkForceDeleteTrashed` live on `AssetTrashController`
([`asset-trash.md`](asset-trash.md)). `bulkGetTags` returns
`{ tags: [{id, name, type, count}], total_assets }`, sorted by count desc then name — the grid's
bulk tag-removal picker. Tag endpoint details: [`tags.md`](tags.md).

### Data shapes

```yaml
# bulkMove response
message: string
moved: int
failed: int
moves: [{old: s3_key, new: s3_key}, ...]

# bulkForceDelete: {message, deleted, failed, deleted_keys}; bulkDownload streams a ZIP
```

### Layer touchpoints & ordering

```
bulkMove(): authorize(move) → destination ∈ FolderRegistry::forPicker()
  → per asset: skip if already there → skip (failed++) if another asset holds the new key
    → moveObject(original) → relocatedDerivedKeys() + moveObject() per derived key
    → DB::transaction(update s3_key + the derived columns whose copy succeeded)
bulkForceDelete(): authorize(bulkForceDelete) → ForceDeletesAssets::forceDeleteAssets()
  (per asset deleteAssetFiles() + forceDelete() in a transaction; shared with the trash page's purge)
bulkDownload(): authorize(bulkDownload) → max 100 ids → reject sum(size) > 500MB
  → per asset getObjectContent() → ZipArchive (skip fetch failures) → 422 if zero added
```

## Scenarios (BDD)

```gherkin
Scenario: Admin can bulk-move assets when maintenance mode is enabled
  Given maintenance_mode is true and an admin user
  When bulkMove is called with a valid destination_folder
  Then s3_key and all four derived key columns point at the new folder
# pinned by: tests/Feature/BulkMoveTest.php

Scenario Outline: Bulk move and bulk force-delete are refused without admin + maintenance_mode
  Given <user> and maintenance_mode <mode>
  When <endpoint> is called
  Then the response is 403
  Examples:
    | user             | mode  | endpoint        |
    | an admin         | false | bulkMove        |
    | an editor or api | true  | bulkMove        |
    | an admin         | false | bulkForceDelete |
    | an api user      | true  | bulkForceDelete |
# pinned by: tests/Feature/BulkMoveTest.php, tests/Feature/BulkForceDeleteTest.php

Scenario: Path traversal in the destination folder is rejected
  Given a destination_folder outside any configured S3 folder
  Then the request is rejected with a validation error
# pinned by: tests/Feature/BulkMoveTest.php

Scenario: Bulk move skips, guards and counts
  Then an asset already in the destination folder is skipped (neither moved nor failed)
  And an asset whose S3 move fails is counted in failed
  And an asset whose destination key another asset holds is not copied, counted failed, both rows unchanged
  And a failed thumbnail copy leaves thumbnail_s3_key on the key whose object still exists
# pinned by: tests/Feature/BulkMoveTest.php

Scenario: Admin can bulk force-delete assets when maintenance mode is enabled
  Given maintenance_mode is true and an admin user
  When bulkForceDelete is called
  Then the assets and their S3 objects are permanently removed
  And an asset whose S3 deletion throws is counted as failed, not deleted
# pinned by: tests/Feature/BulkForceDeleteTest.php

Scenario: Bulk download streams a ZIP for every role
  Given a set of asset ids and any role, api included
  When bulkDownload is called
  Then a ZIP with all requested files is streamed
  And two assets sharing a filename appear as name_1, name_2, ...
# pinned by: tests/Feature/BulkDownloadTest.php

Scenario: Bulk download limits and failures
  Then more than 100 ids is a validation error, and more than 500MB total is 422
  And an asset whose S3 fetch fails is skipped while the rest download
  And when every fetch fails the response is 422, not an empty ZIP
# pinned by: tests/Feature/BulkDownloadTest.php

# — browser-level (see e2e-testing.md for the harness) —

Scenario: Select-all and clear drive the floating bulk bar
  Given the asset grid
  When select-all is used and then cleared
  Then the bulk bar appears with the full count and then disappears
# pinned by: tests/e2e/bulk-operations.spec.js

Scenario: Bulk-adding a tag applies it to every selected asset
  Given several selected assets in the grid
  When a tag is added from the bulk bar
  Then the bar reports success and each asset carries the tag
  And bulk-removing it takes it off each of them again
# pinned by: tests/e2e/bulk-operations.spec.js

Scenario: Bulk move to trash empties the selection and the assets leave the grid
  Given a selection of assets
  When "move to trash" is used from the bulk bar
  Then the selection clears and the assets are in trash
# pinned by: tests/e2e/bulk-operations.spec.js

Scenario: Bulk download streams a zip to the browser
  Given a selection of assets
  When bulk download runs
  Then a zip download is delivered
# pinned by: tests/e2e/bulk-operations.spec.js

Scenario: Bulk restore reports the filenames it restored before reloading
  Given a selected asset in trash
  When bulk restore runs
  Then a summary lists the restored filename, and dismissing it returns the asset
# pinned by: tests/e2e/asset-trash.spec.js
```

## Tests & verification

- Feature: `tests/Feature/BulkMoveTest.php`, `tests/Feature/BulkForceDeleteTest.php`,
  `tests/Feature/BulkDownloadTest.php`, `tests/Feature/BulkTrashTest.php` —
  `php artisan config:clear && php artisan test`
- E2E: `tests/e2e/bulk-operations.spec.js`

## Open questions / future

- Coverage gap: per-asset authorization on bulk tag add/remove (REQ-1) and `bulkGetTags`'s
  count/sort are unpinned (attribution is covered by `tests/Feature/TagAttributionTest.php`).
