# Bulk operations

```yaml
id: bulk-operations
status: implemented
version: 1
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
```

## Background / Why

Bulk actions on the asset grid — tagging, permanent deletion, moving between
folders, and ZIP download — all operate on a caller-supplied `asset_ids[]`
list rather than a filter expression, so the exact selected set (which may
span multiple pages/filters in the UI) is what's acted on. `bulkMove` and
`bulkForceDelete` are gated by `maintenance_mode` in addition to the admin
role check, since they mutate/destroy data in bulk and the team wanted an
explicit "the system is in a safe window for this" switch (see
[`settings.md`](settings.md)).

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
  `S3Service::getConfiguredFolders()`, rejecting path traversal outside them.
- **REQ-4** — A move updates the asset's `s3_key` **and** all four derived
  keys (`thumbnail_s3_key`, `resize_{s,m,l}_s3_key`) that exist, via
  `S3Service::computeDerivedKeys()` + `moveObject()` — never regenerating the
  thumbnails/resizes, only relocating them.
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
POST   assets/bulk/download:      bulkDownload          # rate-limited (see CLAUDE.md)
```

(`bulkTrash`/`bulkRestore`/`bulkForceDeleteTrashed` live on
`AssetTrashController` — see [`asset-trash.md`](asset-trash.md).)

`bulkGetTags` returns tag usage counts across the given assets:
`{ tags: [{id, name, type, count}], total_assets }`, sorted by count desc then
name — used to populate the grid's bulk tag-removal picker.

### Data shapes

```yaml
# bulkMove response
message: string
moved: int
failed: int
moves: [{old: s3_key, new: s3_key}, ...]

# bulkForceDelete / bulkDownload responses follow the same
# {message, deleted|moved, failed, deleted_keys|moves} shape used across bulk endpoints
```

### Layer touchpoints & ordering

```
bulkMove(): authorize(move, Asset::class)
  → validate destination_folder against getConfiguredFolders()
  → per asset: skip if already in destination
    → S3Service::moveObject(original)
    → computeDerivedKeys() → moveObject() for each existing derived key
    → DB::transaction(asset->update([s3_key, ...derived keys]))

bulkForceDelete(): authorize(bulkForceDelete, Asset::class)
  → per asset: S3Service::deleteAssetFiles() → DB::transaction(forceDelete())

bulkDownload(): authorize(bulkDownload, Asset::class)
  → validate asset_ids (max 100)
  → reject if sum(size) > 500MB
  → per asset: S3Service::getObjectContent() → ZipArchive::addFromString() (skip on fetch failure)
  → reject with 422 if zero files were added
```

## Scenarios (BDD)

```gherkin
Scenario: Admin can bulk-move assets when maintenance mode is enabled
  Given maintenance_mode is true and an admin user
  When bulkMove is called with a valid destination_folder
  Then the assets' s3_key and derived keys are updated to the new folder
# pinned by: tests/Feature/BulkMoveTest.php

Scenario: Bulk move is denied when maintenance mode is disabled
  Given maintenance_mode is false, even for an admin
  When bulkMove is called
  Then the response is 403
# pinned by: tests/Feature/BulkMoveTest.php

Scenario: A non-admin (editor or api) cannot bulk-move assets
  Given a non-admin user
  When bulkMove is called
  Then the response is 403
# pinned by: tests/Feature/BulkMoveTest.php

Scenario: Path traversal in the destination folder is rejected
  Given a destination_folder outside any configured S3 folder
  Then the request is rejected with a validation error
# pinned by: tests/Feature/BulkMoveTest.php

Scenario: Assets already in the target folder are skipped
  Given an asset whose current folder equals the destination
  When bulkMove is called
  Then that asset is skipped (not counted as moved or failed)
# pinned by: tests/Feature/BulkMoveTest.php

Scenario: A partial S3 failure during bulk move is reported in the failed count
  Given one asset whose S3 move fails
  When bulkMove processes the batch
  Then moved/failed counts reflect the partial outcome
# pinned by: tests/Feature/BulkMoveTest.php

Scenario: All four derived key columns are updated after a move
  Given an asset with thumbnail and all three resize keys set
  When it is moved
  Then thumbnail_s3_key and resize_{s,m,l}_s3_key all point at the new folder
# pinned by: tests/Feature/BulkMoveTest.php

Scenario: Admin can bulk force-delete assets when maintenance mode is enabled
  Given maintenance_mode is true and an admin user
  When bulkForceDelete is called
  Then the assets and their S3 objects are permanently removed
# pinned by: tests/Feature/BulkForceDeleteTest.php

Scenario: Bulk force-delete is denied when maintenance mode is disabled
  Given maintenance_mode is false
  When an admin calls bulkForceDelete
  Then the response is 403
# pinned by: tests/Feature/BulkForceDeleteTest.php

Scenario: API users cannot bulk force-delete regardless of maintenance mode
  Given an api-role user
  Then bulkForceDelete is 403
# pinned by: tests/Feature/BulkForceDeleteTest.php

Scenario: Bulk force-delete reports failures when S3 deletion throws
  Given an asset whose S3 deletion throws
  When bulkForceDelete processes the batch
  Then that asset is counted as failed, not deleted
# pinned by: tests/Feature/BulkForceDeleteTest.php

Scenario: An authenticated user can bulk-download assets as a ZIP
  Given a set of asset ids
  When bulkDownload is called
  Then a ZIP containing all requested files is streamed back
# pinned by: tests/Feature/BulkDownloadTest.php

Scenario: Duplicate filenames are disambiguated in the ZIP
  Given two selected assets sharing the same filename
  When they are bulk-downloaded
  Then the ZIP contains both under disambiguated names (_1, _2, ...)
# pinned by: tests/Feature/BulkDownloadTest.php

Scenario: Bulk download rejects more than 100 assets
  Given more than 100 asset ids
  Then the request is rejected with a validation error
# pinned by: tests/Feature/BulkDownloadTest.php

Scenario: Bulk download rejects a batch exceeding 500MB total
  Given assets whose combined size exceeds 500MB
  Then the response is 422
# pinned by: tests/Feature/BulkDownloadTest.php

Scenario: Bulk download skips assets whose S3 fetch fails
  Given one asset that fails to fetch from S3
  When the ZIP is built
  Then that asset is skipped and the rest still download successfully
# pinned by: tests/Feature/BulkDownloadTest.php

Scenario: Bulk download returns 422 when every file fails to fetch
  Given every requested asset fails its S3 fetch
  Then the response is 422, not an empty ZIP
# pinned by: tests/Feature/BulkDownloadTest.php

Scenario: An api-role user can bulk-download assets
  Given an api-role user
  Then bulkDownload succeeds (bulkDownload is granted to all three roles)
# pinned by: tests/Feature/BulkDownloadTest.php
```

## Tests & verification

- Feature: `tests/Feature/BulkMoveTest.php`, `tests/Feature/BulkForceDeleteTest.php`,
  `tests/Feature/BulkDownloadTest.php`, `tests/Feature/BulkTrashTest.php`
- Run: `php artisan config:clear && php artisan test`
- E2E: `tests/e2e/bulk-operations.spec.js` — the floating bulk bar (select-all, add/remove tag, trash, download).

## Open questions / future

- `bulkAddTags`, `bulkRemoveTags`, and `bulkGetTags` have no dedicated feature
  test file in the current suite (unlike bulk move/force-delete/download/trash,
  each of which has one). Tag-attribution behavior for these three endpoints is
  covered indirectly by `tests/Feature/TagAttributionTest.php`
  (`bulkAddTags sets attached_by to user`), but authorization-per-asset (REQ-1)
  and `bulkGetTags`'s count/sort behavior are currently unpinned.
