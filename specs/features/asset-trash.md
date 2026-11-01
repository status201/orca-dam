# Asset trash

```yaml
id: asset-trash
status: implemented
version: 2
owner: core
related:
  - architecture
  - asset-model
  - s3-storage
  - authorization-policies
  - bulk-operations
  - discovery-import
source:
  - app/Http/Controllers/AssetTrashController.php
  - app/Policies/AssetPolicy.php
  - app/Support/BulkAssetIds.php
  - app/Http/Concerns/ForceDeletesAssets.php
```

## Background / Why

Deleting an asset is two distinct operations with very different blast radii:
a soft delete (trash) is reversible and leaves S3 untouched, while a force
(hard) delete is permanent and clears storage. Keeping them as separate,
separately-authorized actions — rather than one `destroy` with a `?force=1`
flag — makes the destructive path impossible to reach by accident (see
[ADR-003](../decisions/adr-003-soft-delete-keeps-s3.md)).

## Requirements

- **REQ-1** — Soft delete (`destroy`) never touches S3 — it only sets
  `deleted_at` via Eloquent's `SoftDeletes`.
- **REQ-2** — Force delete clears S3 (original + thumbnail + resize variants
  via `S3Service::deleteAssetFiles()`) and the DB row, in that order, and only
  ever operates on an already-trashed asset (routes use `withTrashed()`).
- **REQ-3** — `restore()` only reverses a soft delete; it does not attempt to
  recreate any S3 state (there is none to recreate — REQ-1).
- **REQ-4** — Bulk variants (`bulkTrash`, `bulkRestore`, `bulkForceDeleteTrashed`)
  process each asset independently, continuing past a per-asset failure and
  reporting `{trashed|restored|deleted, failed}` counts rather than failing
  the whole batch on one error.
- **REQ-5** — Editors and admins share trash/restore access; **only admins**
  can force-delete (see the role × ability matrix in `architecture.md`).
- **REQ-6** — `bulkForceDeleteTrashed` (operating on already-trashed assets)
  does **not** require `maintenance_mode` — only `AssetBulkController::bulkForceDelete`
  (operating on *live* assets, see [`bulk-operations.md`](bulk-operations.md))
  does. This is a deliberate asymmetry: trashed assets are already presumed
  gone from active use.

## Technical design

### Contract / public interface

Routes (`routes/web.php`, web-authenticated):

```yaml
DELETE assets/{asset}:                        AssetTrashController::destroy            # assets.destroy
GET    assets/trash/index:                    AssetTrashController::index              # assets.trash
POST   assets/{asset}/restore:                AssetTrashController::restore            # assets.restore (withTrashed)
POST   assets/bulk/trash:                     AssetTrashController::bulkTrash          # assets.bulk.trash
POST   assets/trash/bulk-restore:             AssetTrashController::bulkRestore        # assets.trash.bulk-restore
DELETE assets/{asset}/force-delete:           AssetTrashController::forceDelete        # assets.force-delete (withTrashed)
DELETE assets/trash/bulk-force-delete:        AssetTrashController::bulkForceDeleteTrashed  # assets.trash.bulk-force-delete
```

Policy abilities: `delete`, `bulkTrash`, `restore`, `bulkRestore` (admin+editor), `forceDelete`
(admin only) — [`authorization-policies.md`](authorization-policies.md).

### Layer touchpoints & ordering

```
destroy():   authorize(delete)      → asset->delete()                [S3 untouched]
restore():   authorize(restore)     → asset->restore()               [S3 untouched]
forceDelete(): authorize(forceDelete) → S3Service::deleteAssetFiles() → asset->forceDelete()
bulkForceDeleteTrashed(): authorize(forceDelete, Asset::class)
  → ForceDeletesAssets: per trashed asset deleteAssetFiles() → DB::transaction(forceDelete())
```

## Scenarios (BDD)

```gherkin
Scenario: A soft-deleted asset is excluded from the index but keeps its S3 objects
  Given an asset with existing S3 files
  When it is soft-deleted (destroy)
  Then it is excluded from default queries and its S3 objects still exist
# pinned by: tests/Feature/AssetTest.php

Scenario: Only admins can force-delete a single asset
  Given an editor user
  When they attempt to force-delete an asset
  Then the response is 403
# pinned by: tests/Feature/AssetTest.php

Scenario: Trash, restore and bulk trash
  Given an editor or admin
  Then viewing trash and restoring an asset succeed
  And bulkTrash soft-deletes every given asset and preserves S3 objects
  And bulkRestore restores only the ids that are actually trashed
  And bulkTrash is 403 for an api-role user, and a validation error for missing/malformed asset_ids
# pinned by: tests/Feature/BulkTrashTest.php

Scenario: Bulk force-delete of trashed assets (REQ-6)
  Given trashed assets with S3 files and maintenance_mode disabled
  When an admin calls bulkForceDeleteTrashed
  Then the DB rows and all associated S3 objects are removed
  And live ids in the same request are untouched
  And an editor or api-role user gets 403
# pinned by: tests/Feature/BulkTrashTest.php

# — browser-level (see e2e-testing.md for the harness) —

Scenario: Deleting an asset moves it to trash and restoring brings it back
  Given the list view of the grid
  When an asset is deleted from its row
  Then it disappears from the grid and appears in /assets/trash/index
  And restoring it returns it to the grid
# pinned by: tests/e2e/asset-trash.spec.js

Scenario: A soft-deleted asset shows in trash and not in the library
  Given a seeded soft-deleted asset
  Then it is listed in trash and absent from the asset grid
# pinned by: tests/e2e/asset-trash.spec.js

Scenario: An admin permanently deletes a trashed asset
  Given a soft-deleted asset in trash
  When the admin confirms permanent deletion
  Then the asset is gone from trash entirely
# pinned by: tests/e2e/asset-trash.spec.js
```

## Tests & verification

- Feature: `tests/Feature/AssetTest.php`, `tests/Feature/BulkTrashTest.php`
- Unit: `tests/Unit/Policies/AssetPolicyTest.php` (role matrix for delete/restore/forceDelete)
- E2E: `tests/e2e/asset-trash.spec.js`
- Run: `php artisan config:clear && php artisan test`
