# Asset model

```yaml
id: asset-model
status: implemented
version: 8
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
  - app/Support/AssetFilter.php
  - app/Support/AssetSort.php
```

## Background / Why

`Asset` is the central entity of ORCA DAM: every uploaded/discovered file, its S3 location, its
derived thumbnail/resize keys, its licensing metadata and its tags hang off this one Eloquent
model. Nearly every other feature reads or writes through its relations, scopes and computed
attributes, so its contract is documented once here.

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
- **REQ-6** — `scopeOfType` is deliberately lenient: an unrecognised value leaves the query
  untouched rather than erroring, so a stale bookmark still returns results. The cost is that a
  wrong value filters *nothing*, silently — so the valid values are published as
  `Asset::typeCategories()` and every caller offering a type filter must source its options from
  it and reject anything else at its own boundary.

## Technical design

### Contract / public interface

Relations: `user(): BelongsTo`, `modifier(): BelongsTo` (`last_modified_by`),
`parent(): BelongsTo` (self-FK), `children(): HasMany` (self-FK, ordered by
`created_at`), `tags(): BelongsToMany` (pivot `attached_by`, ordered
user → reference → ai), `userTags()`/`aiTags()`/`referenceTags()` (filtered
`tags()` by `type`).

Methods: `syncTagsWithAttribution(array $tagIds, string $attachedBy): void` and
`syncTagsPreservingAi(?array $userTagIds, ?array $referenceTagIds): void` (the only ways to
write the pivot — mechanics in [`tags.md`](tags.md)), `wasModified(): bool`,
`isImage()`/`isVideo()`/`isPdf()`/`isSvg()`/`isEps()`/`isMathMl()`/`isTex()`,
`getFileIcon()`/`getIconColorClass()`, `licenseTypes(): array` (static),
`getLicenseLabel(): string`, `typeCategories(): array<string>` (static),
`typeCategoryFor(string $mimeType): ?string` (static).

**The type categories are `document`, `image`, `video`, `audio`** — not mime prefixes
(`application/pdf` is a `document`). Type filters source options from `Asset::typeCategories()`
and map a mime type with `Asset::typeCategoryFor()`, never `explode('/', $mimeType)[0]`.

Scopes: `scopeSearch($query, ?string $search)` (delegates to `AssetSearchParser::apply` —
[`asset-search.md`](asset-search.md)), `scopeWithTags($query, array $tagIds)`,
`scopeOfType($query, ?string $type)` (also accepts `images`/`videos`/`documents` aliases),
`scopeByUser($query, ?int $userId)`, `scopeInFolder($query, ?string $folder)`,
`scopeApplySort($query, ?string $sort)`, `scopeMissing($query)`
([`s3-integrity.md`](s3-integrity.md)), `scopeDuplicateOf($query, ?string $etag)`
([`duplicate-detection.md`](duplicate-detection.md); callers add `withTrashed()`).

**Sorting.** `App\Support\AssetSort` is a string-backed enum of the ten sort keys
(`date_desc` … `s3key_desc`); each case owns its ordering (`apply()`) and translated label
(`label()`). `AssetSort::fromInput(?string)` maps an unknown, null **or empty** value to
`date_desc`. `scopeApplySort`, the cycle-nav summary and the grid's sort `<select>` all read it.

**Listing.** `App\Support\AssetFilter` applies every list filter once:

```yaml
new AssetFilter(search?, tagIds=[], type?, folder?, userId?, missingOnly=false, ids?, sort='date_desc')
  ->apply(Builder $query): Builder   # where-clauses, then AssetSort::fromInput($sort)->apply()
AssetFilter::tagIds(mixed $raw): array   # the `tags` param as an array or a comma-separated string
```

The web index/embed/cycle-nav query (`AssetController::buildFilteredAssetQuery()`) and the REST
`index`/`search` endpoints each build one, mapping their own parameters and deciding their own
`user` filter (web: an admin, or the viewer filtering by themselves; REST: admins only).

**Derived S3 objects.** Every caller that deletes, purges, resets or fills the derived columns
reads them from here:

```yaml
Asset::RESIZE_KEY_COLUMNS:  { s: resize_s_s3_key, m: resize_m_s3_key, l: resize_l_s3_key }
Asset::DERIVED_KEY_COLUMNS: [thumbnail_s3_key, resize_s_s3_key, resize_m_s3_key, resize_l_s3_key]
Asset::derivedKeyResetAttributes(): array        # every derived column => null
Asset::resizeKeyAttributes(array $resizedKeys)   # generateResizedImages() result => columns (missing size => null)
$asset->derivedS3Keys(bool $resizesOnly = false) # the non-empty derived keys, thumbnail first
```

Computed attributes: `url`, `thumbnail_url` (falls back to `url` for GIF/SVG without
`thumbnail_s3_key`), `resize_s_url`/`resize_m_url`/`resize_l_url` (null when the key is unset),
`formatted_size`, `folder` (derived from `s3_key`; `S3Service::getRootFolder()` for a root-level
key), `is_missing` (`s3_missing_at !== null`). `Asset::APPEND_FIELDS` is the canonical list to
`append()` before serializing to JSON.

### Data shapes

```yaml
Asset:
  id: int
  s3_key: string(1024)    # IMMUTABLE (REQ-1) — input-validation.md REQ-10
  s3_key_hash: char(64)   # sha256 of s3_key, carries the UNIQUE; not fillable, $hidden,
                          # set by a saving hook — input-validation.md REQ-11
  etag: ?string           # dedup key — duplicate-detection.md
  filename: string(500)   # editable display name; browser's original name, stored verbatim
  mime_type: string
  size: int
  width / height: int|null
  thumbnail_s3_key / resize_{s,m,l}_s3_key: string(1024)|null
  alt_text / caption: text|null    # TEXT; rules cap characters — input-validation.md
  license_type: enum      # Asset::licenseTypes()
  license_expiry_date: date|null   # cast 'date'. Retired from the edit form; column, API field,
                                   # CSV column and detail-page display stay
  date_obtained: date|null         # cast 'date'
  copyright / copyright_source: string(500)|null
  user_id: int            # uploader
  last_modified_by: int|null   # modifier()
  parent_id: int|null     # self-FK, nullOnDelete
  s3_missing_at: datetime|null   # set by VerifyAssetIntegrity
  deleted_at: datetime|null      # soft delete
```

## Scenarios (BDD)

```gherkin
Scenario: Relations resolve
  Given an asset created with a user_id, and a .tex asset with a render whose parent_id points at it
  Then asset.user resolves to that User
  And the render's parent() is the .tex asset and the .tex asset's children() includes the render
  And force-deleting the parent nulls the child's parent_id (nullOnDelete)
# pinned by: tests/Unit/AssetTest.php

Scenario: Tags attach with attribution and the last attacher wins
  Given a tag attached to an asset with attached_by 'ai'
  When the same tag is synced via syncTagsWithAttribution($ids, 'user')
  Then the pivot's attached_by becomes 'user'
# pinned by: tests/Feature/TagAttributionTest.php

Scenario: Replacing user tags keeps AI and reference tags with their attribution
  Given an asset with an AI tag, a reference tag and a user tag
  When syncTagsPreservingAi([newUserTag], null) runs
  Then the AI and reference pivots survive unchanged and the user set is exactly [newUserTag]
  And syncTagsPreservingAi(null, []) clears only the reference tags
# pinned by: tests/Unit/AssetTest.php

Scenario: Soft delete keeps the model recoverable
  When an asset is deleted() (soft)
  Then it is excluded from default queries but recoverable via withTrashed()
# pinned by: tests/Unit/AssetTest.php

Scenario: Computed attributes
  Given the custom_domain setting is set
  Then url and thumbnail_url use that domain instead of the S3 bucket URL
  And a GIF or SVG without thumbnail_s3_key has thumbnail_url equal to url
  And an asset with s3_key "assets/marketing/uuid.jpg" has folder "assets/marketing"
  And license_expiry_date and date_obtained cast to Carbon dates; getLicenseLabel() is translated
# pinned by: tests/Unit/AssetTest.php

Scenario: Every allowlisted non-raster type has a file icon
  Given an asset of each type config/uploads.php maps an extension to, raster images aside
  Then getFileIcon() returns a specific icon, not the generic fa-file
# pinned by: tests/Unit/AssetTest.php

Scenario: Type filtering uses categories, and an unknown value filters nothing (REQ-6)
  Then Asset::typeCategoryFor('application/pdf') is "document" and typeCategories() lists document, image, video, audio
  And ofType('images') returns only image/* assets
  And ofType('application') returns every asset
# pinned by: tests/Unit/AssetTest.php

Scenario: scopeWithTags filters by tag id
  Given assets with and without a given tag
  When querying withTags([$tagId])
  Then only assets carrying that tag are returned
# pinned by: tests/Unit/AssetTest.php

Scenario: Every sort key has one ordering and one label; unknown falls back to date_desc
  Then each AssetSort case applies its own ordering and has a translated label
  And applySort('bogus') and AssetSort::fromInput() of null or '' order as date_desc
# pinned by: tests/Unit/AssetSortScopeTest.php

Scenario: An empty sort parameter falls back to the default order
  When GET /assets?sort= and GET /api/assets?sort= are requested as an admin
  Then both respond 200, ordered as date_desc
# pinned by: tests/Feature/AssetTest.php

Scenario: scopeMissing returns only assets flagged missing on S3
  Given one asset with s3_missing_at set and one without
  Then missing() returns only the flagged asset
# pinned by: tests/Feature/IntegrityTest.php

Scenario: date_obtained round-trips through the edit form; license expiry is display-only
  Given an asset edited with a date_obtained of 2026-05-01
  Then the detail page shows it under "Date Obtained"
  And the edit form renders no license expiry input, while the detail page still shows a stored one
# pinned by: tests/Feature/AssetTest.php

Scenario: The derived-key helpers name the same four columns in a fixed order
  Given an asset with a thumbnail and only an S resize
  Then derivedS3Keys() lists the thumbnail key then the S key, and derivedS3Keys(true) only the S key
  And derivedKeyResetAttributes() nulls all four derived columns
  And resizeKeyAttributes(['s' => k]) maps k to resize_s_s3_key and nulls M and L
# pinned by: tests/Unit/AssetTest.php

Scenario: duplicateOf matches by etag and never matches a missing etag
  Given an asset with etag "abc"
  Then Asset::duplicateOf("abc") finds it and Asset::duplicateOf(null) finds nothing
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
- E2E: `tests/e2e/asset-detail.spec.js`

## Open questions / future

- REQ-1 is enforced only by `UpdateAssetRequest` not listing `s3_key`; no test asserts a
  submitted `s3_key` is ignored.
- `scopeByUser` and `scopeInFolder` have no dedicated unit test — only indirect coverage via
  `tests/Feature/AssetTest.php` index filtering.
