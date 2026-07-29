# Asset model

```yaml
id: asset-model
status: implemented
version: 1
owner: core
related:
  - architecture
  - s3-storage
  - asset-search
  - tags
  - duplicate-detection
  - s3-integrity
  - asset-cycle-navigation
source:
  - app/Models/Asset.php
  - app/Services/AssetSearchParser.php
  - database/migrations/
```

## Background / Why

`Asset` is the central entity of ORCA DAM: every uploaded/discovered file, its S3
location, its derived thumbnail/resize keys, its licensing metadata, and its tags
hang off this one Eloquent model. Nearly every other feature spec in this folder
(`asset-upload`, `asset-search`, `asset-trash`, `bulk-operations`, `csv-export-import`,
…) reads or writes through this model's relations, scopes, and computed attributes,
so its contract is documented once here rather than re-derived per feature.

## Requirements

- **REQ-1** — `s3_key` is immutable once written; `filename` is the editable display
  name. See [ADR-006](../decisions/adr-006-immutable-s3-key.md).
- **REQ-2** — Soft delete (`SoftDeletes`) never touches S3; only a hard
  (force) delete does. See [ADR-003](../decisions/adr-003-soft-delete-keeps-s3.md).
- **REQ-3** — Tag attachment records *who* attached a tag (`user`/`ai`/`reference`)
  via the `asset_tag.attached_by` pivot column, with "last attacher wins" semantics.
- **REQ-4** — `parent_id` is a nullable self-FK (`nullOnDelete`) linking a derived
  asset (e.g. a TikZ-rendered SVG) back to its source (e.g. the `.tex` template).
- **REQ-5** — All computed URLs (`url`, `thumbnail_url`, `resize_*_url`) resolve
  through `S3Service::getPublicBaseUrl()` so a `custom_domain` setting change
  applies everywhere without touching stored data.

## Technical design

### Contract / public interface

Relations: `user(): BelongsTo`, `modifier(): BelongsTo` (`last_modified_by`),
`parent(): BelongsTo` (self-FK), `children(): HasMany` (self-FK, ordered by
`created_at`), `tags(): BelongsToMany` (pivot `attached_by`, ordered
user → reference → ai), `userTags()`/`aiTags()`/`referenceTags()` (filtered
`tags()` by `type`).

Methods: `syncTagsWithAttribution(array $tagIds, string $attachedBy): void`,
`wasModified(): bool`, `isImage()`/`isVideo()`/`isPdf()`/`isSvg()`/`isEps()`/
`isMathMl()`/`isTex()`, `getFileIcon()`/`getIconColorClass()`,
`licenseTypes(): array` (static), `getLicenseLabel(): string`.

Scopes: `scopeSearch($query, ?string $search)` (delegates to
`AssetSearchParser::apply`), `scopeWithTags($query, array $tagIds)`,
`scopeOfType($query, ?string $type)` (accepts `images`/`videos`/`documents`
aliases in addition to raw mime-prefix categories), `scopeByUser($query, ?int $userId)`,
`scopeInFolder($query, ?string $folder)`, `scopeApplySort($query, string $sort)`,
`scopeMissing($query)`.

Computed attributes (accessors): `url`, `thumbnail_url` (falls back to `url` for
GIF/SVG when no `thumbnail_s3_key`), `resize_s_url`/`resize_m_url`/`resize_l_url`
(null when the corresponding key is unset), `formatted_size`, `folder` (derived
from `s3_key`, falls back to `S3Service::getRootFolder()` for a root-level key),
`is_missing` (`s3_missing_at !== null`). `Asset::APPEND_FIELDS` is the canonical
list of computed fields to `append()` before serializing an asset to JSON.

### Data shapes

```yaml
Asset:
  id: int
  s3_key: string          # unique, IMMUTABLE (REQ-1)
  etag: string            # S3 etag — dedup key, see duplicate-detection.md
  filename: string        # editable display name
  mime_type: string
  size: int
  width / height: int|null
  thumbnail_s3_key / resize_{s,m,l}_s3_key: string|null
  alt_text / caption: string|null
  license_type: enum      # see Asset::licenseTypes()
  license_expiry_date: date|null   # cast 'date'
  copyright / copyright_source: string|null
  user_id: int            # belongsTo User (uploader)
  last_modified_by: int|null   # belongsTo User (modifier())
  parent_id: int|null     # nullable self-FK, nullOnDelete
  s3_missing_at: datetime|null   # cast 'datetime' — set by VerifyAssetIntegrity
  deleted_at: datetime|null      # soft delete
```

### Layer touchpoints & ordering

`AssetSearchParser::apply()` builds the WHERE clauses for `scopeSearch`; the
model's scope is a thin delegator so search-parsing logic stays independently
testable (see [`asset-search.md`](asset-search.md)). `syncTagsWithAttribution()`
does a `syncWithoutDetaching` followed by a single bulk `UPDATE` over
`asset_tag` (not N per-tag updates) to set `attached_by` — callers in
`AssetController`, `AssetBulkController`, and `CsvImportService` all funnel
through this one method rather than touching the pivot directly.

## Scenarios (BDD)

```gherkin
Scenario: An asset belongs to its uploading user
  Given an asset created with a user_id
  Then asset.user resolves to that User
# pinned by: tests/Unit/AssetTest.php

Scenario: A derived asset links back to its source via parent/children
  Given a .tex asset and a rendered asset with parent_id pointing at it
  Then the render's parent() resolves to the .tex asset
  And the .tex asset's children() includes the render
# pinned by: tests/Unit/AssetTest.php

Scenario: Deleting a parent nulls parent_id on its children
  Given a parent asset with a child asset
  When the parent asset is force-deleted
  Then the child's parent_id becomes null (nullOnDelete)
# pinned by: tests/Unit/AssetTest.php

Scenario: Tags attach with attribution
  Given an asset
  When a tag is synced via syncTagsWithAttribution($ids, 'user')
  Then the asset_tag pivot's attached_by is 'user'
# pinned by: tests/Feature/TagAttributionTest.php

Scenario: Last attacher wins on re-attachment
  Given a tag attached to an asset with attached_by 'ai'
  When the same tag is synced again with attached_by 'user'
  Then the pivot's attached_by becomes 'user'
# pinned by: tests/Feature/TagAttributionTest.php

Scenario: Soft delete keeps the model recoverable
  Given an asset
  When it is deleted() (soft)
  Then it is excluded from default queries but recoverable via withTrashed()
# pinned by: tests/Unit/AssetTest.php

Scenario: url and thumbnail_url honor a configured custom_domain
  Given the custom_domain setting is set
  Then asset.url and asset.thumbnail_url use that domain instead of the S3 bucket URL
# pinned by: tests/Unit/AssetTest.php

Scenario: thumbnail_url falls back to the original for GIF/SVG without a thumbnail key
  Given a GIF (or SVG) asset with no thumbnail_s3_key
  Then thumbnail_url returns the same value as url
# pinned by: tests/Unit/AssetTest.php

Scenario: folder accessor derives the parent path from s3_key
  Given an asset with s3_key "assets/marketing/uuid.jpg"
  Then asset.folder is "assets/marketing"
# pinned by: tests/Unit/AssetTest.php

Scenario: scopeOfType accepts plural/friendly aliases
  Given assets of various mime types
  When querying ofType('images')
  Then only image/* assets are returned
# pinned by: tests/Unit/AssetTest.php

Scenario: scopeWithTags filters by tag id
  Given assets with and without a given tag
  When querying withTags([$tagId])
  Then only assets carrying that tag are returned
# pinned by: tests/Unit/AssetTest.php

Scenario: scopeApplySort falls back to date_desc for an unknown value
  Given assets with varying updated_at
  When applySort('bogus') is applied
  Then the result is ordered exactly as date_desc (latest updated_at first)
# pinned by: tests/Unit/AssetSortScopeTest.php

Scenario: scopeMissing returns only assets flagged missing on S3
  Given one asset with s3_missing_at set and one without
  When querying missing()
  Then only the flagged asset is returned
# pinned by: tests/Feature/IntegrityTest.php

Scenario: license fields round-trip through casts and labels
  Given an asset with a license_type and license_expiry_date
  Then license_expiry_date casts to a Carbon date
  And getLicenseLabel() returns the translated label for the type
# pinned by: tests/Unit/AssetTest.php

# — browser-level (see e2e-testing.md for the harness) —

Scenario: Editing an asset persists the filename and alt text
  Given the edit page for e2e-detail-alpha.png
  When the filename and alt text are changed and saved
  Then the detail page shows the new values
# pinned by: tests/e2e/asset-detail.spec.js

Scenario: An asset card opens its detail page
  Given the asset grid
  When a card is clicked
  Then the detail page for that asset renders
# pinned by: tests/e2e/asset-detail.spec.js
```

## Tests & verification

- Unit: `tests/Unit/AssetTest.php`, `tests/Unit/AssetSortScopeTest.php`,
  `tests/Unit/Services/AssetSearchParserTest.php`
- Feature: `tests/Feature/TagAttributionTest.php`, `tests/Feature/IntegrityTest.php`
- Run: `php artisan config:clear && php artisan test`
- E2E: `tests/e2e/asset-detail.spec.js` — the metadata fields round-tripping through
  the real edit form, and a grid card opening its detail page.

## Open questions / future

- `filename` vs. `s3_key` immutability (REQ-1) is enforced only by convention —
  `UpdateAssetRequest` simply never lists `s3_key` among its updatable fields —
  there is no test that asserts a submitted `s3_key` is ignored. Worth a explicit
  regression test if this has ever regressed.
- `scopeByUser` and `scopeInFolder` have no dedicated `Asset`-level unit test;
  they're only exercised indirectly through `tests/Feature/AssetTest.php`'s
  index-filtering scenarios (`assets index can filter by user for admins`,
  `assets index uses user home folder preference as default`).
