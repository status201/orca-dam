# Tags

```yaml
id: tags
status: implemented
version: 7
owner: core
related:
  - architecture
  - tag-input
  - reference-tags-api
source:
  - app/Models/Tag.php
  - app/Models/Asset.php
  - app/Http/Controllers/TagController.php
  - app/Http/Controllers/AssetController.php
  - app/Http/Controllers/AssetBulkController.php
  - database/factories/TagFactory.php
  - resources/js/alpine/tags.js
  - resources/js/alpine/asset-grid.js
  - app/Support/TagInputParser.php
  - app/Services/RekognitionService.php
```

## Background / Why

Assets are categorised by free-form tags rather than a fixed taxonomy. Three producers attach
tags to the same asset — a human editor, AWS Rekognition ([`ai-tagging.md`](ai-tagging.md)), and
external systems via the reference-tags API
([ADR-012](../decisions/adr-012-reference-tags-api-only.md)) — so the `asset_tag` pivot tracks
*who last attached* each tag. "Last attacher wins" lets a user re-affirm an AI-suggested tag
without a separate data model per origin. This spec owns the pivot-write mechanics every other
spec links to.

## Requirements

- **REQ-1** — A `Tag` has exactly one `type`: `user`, `ai`, or `reference`.
  `name` is globally unique across all types (one row per name, regardless of
  type) and capped at `Tag::MAX_NAME_LENGTH` (100).
- **REQ-2** — `Asset::syncTagsWithAttribution()` implements "last attacher
  wins": attaching a tag ID that is already linked to the asset updates its
  `asset_tag.attached_by` in place rather than leaving the original attacher
  recorded twice.
- **REQ-3** — Reference tags are created only through the REST API
  (`POST /api/reference-tags`, see [ADR-012](../decisions/adr-012-reference-tags-api-only.md))
  but, once created, are renamable and deletable in the web UI exactly like
  user tags. Only `ai`-typed tags are immutable by name — `TagController::update`
  rejects renaming an AI tag with a 403.
- **REQ-4** — Tag management exists at both single-asset (`AssetController`)
  and bulk (`AssetBulkController`) granularity, both gated by
  `AssetPolicy::update` (all three roles — admin/editor/api).
- **REQ-5** — Deleting a `Tag` (single or bulk) detaches it from every asset
  via the pivot relationship; no orphaned `asset_tag` rows remain.
- **REQ-6** — Resolving a tag name to an ID never changes an existing tag's `type`.
  `Tag::resolveUserTagIds()` / `resolveReferenceTagIds()` / `resolveAiTagIds()` reuse an existing
  tag of any type — **as the database compares names**, not as PHP does: under
  `utf8mb4_unicode_ci` `café`/`cafe` are one name, so a name the batch lookup did not return
  exactly is resolved with `firstOrCreate()` and the collation finds the existing row (an exact
  PHP comparison would hit the unique index).
- **REQ-tag-name-normalization** — Every path that turns a name into a tag, or looks a tag up by
  name, normalizes it with `Tag::normalizeName()` — trimmed and `mb_strtolower`ed, the same rule
  `TagInputParser` applies: resolving names to ids (user, reference, AI), renaming a tag,
  `reference-tag:create`, and removing reference tags by name. Never byte-wise `strtolower`.
- **REQ-reference-tags-kept-apart** — Reference tags track usage by external systems, so tag
  overviews keep them apart: **All Tags** means user + AI tags (`Tag::GENERAL_TYPES`), never
  reference. The Tags page tabs, the asset grid's *Filter Tags* and the CSV Export tag-type
  dropdowns are ordered **All → User → AI → Reference** and default to **All**; the Reference tab
  sits apart at the far right. `/tags` means All, `?type=user|ai|reference` selects a tab, an
  unrecognised `type` falls back to All, and switching tabs omits `type` for All. `GET /tags` JSON:
  `type=all` = user + AI; an absent `type` stays unfiltered (API compatibility).

## Technical design

### Contract / public interface

```yaml
Tag (app/Models/Tag.php):
  MAX_NAME_LENGTH: 100                       # shared by validation + TagInputParser
  assets(): BelongsToMany                    # ->withPivot('attached_by')->withTimestamps()
  normalizeName(string $name): string       # mb_strtolower(trim()) — REQ-tag-name-normalization
  resolveUserTagIds(array $names): array     # firstOrCreate as type=user; reuses existing tag as-is
  resolveReferenceTagIds(array $names): array # same, type=reference
  resolveAiTagIds(array $names): array       # same, type=ai — RekognitionService::autoTagAsset()
  GENERAL_TYPES: ['user', 'ai']              # = All Tags
  scopeUserTags / scopeAiTags / scopeReferenceTags
  scopeGeneral                               # type in GENERAL_TYPES
  scopeSearch(?string $search)               # name LIKE %search%

Asset (app/Models/Asset.php):
  tags(): BelongsToMany       # ordered: user(0) < reference(1) < ai/other(2)
  userTags() / aiTags() / referenceTags(): BelongsToMany   # type-filtered
  syncTagsWithAttribution(array $tagIds, string $attachedBy): void
  syncTagsPreservingAi(?array $userTagIds, ?array $referenceTagIds): void
    # replacing counterpart for the two metadata-update endpoints (via UpdateAssetRequest);
    # null leaves that type untouched, an array (even empty) becomes its complete set; AI pivots kept

TagController:
  index(Request)          # GET /tags — paginated JSON (expectsJson; ?type=user|ai|reference, 'all' = user+ai, absent = unfiltered)
                          #   or view with typeCounts (all = user+ai) + activeType (all|user|ai|reference, default all)
  search(Request)          # GET /tags/search — autocomplete, ?type= or ?types=a,b
  show(string $ids)        # GET /api/tags/{ids} (API only) — single object (1 id) or array (2-200 ids)
  byIds(Request)           # POST /tags/by-ids — resolve ids[] to Tag rows
  update(Request, Tag)     # PATCH /tags/{tag} — rename; 403 if type=ai
  destroy(Tag)             # DELETE /tags/{tag} — cascades detach
  bulkDestroy(Request)     # DELETE /tags/bulk — ids[] (max 500)

AssetController:
  addTags(Request, Asset)      # POST /assets/{asset}/tags — tags[] and/or reference_tag_ids[]
  removeTag(Asset, Tag)        # DELETE /assets/{asset}/tags/{tag}

AssetBulkController:
  bulkAddTags(Request)         # POST /assets/bulk/tags — asset_ids[] + tags[]/reference_tag_ids[]
  bulkRemoveTags(Request)      # POST /assets/bulk/tags/remove — asset_ids[] + tag_ids[]
  bulkGetTags(Request)         # POST /assets/bulk/tags/list — asset_ids[] -> per-tag counts
```

Routes (`routes/web.php`, session `auth` group; `GET /api/tags`, `/api/tags/search` and
`/api/tags/{ids}` are under `auth.multi`):

```yaml
GET    /tags                       tags.index
GET    /tags/search                tags.search
POST   /tags/by-ids                tags.byIds
PATCH  /tags/{tag}                 tags.update
DELETE /tags/bulk                  tags.bulk.destroy
DELETE /tags/{tag}                 tags.destroy
POST   /assets/{asset}/tags        assets.tags.add
DELETE /assets/{asset}/tags/{tag}  assets.tags.remove
POST   /assets/bulk/tags           assets.bulk.tags.add
POST   /assets/bulk/tags/remove    assets.bulk.tags.remove
POST   /assets/bulk/tags/list      assets.bulk.tags.list
```

Authorization: `addTags`/`removeTag`/`bulkAddTags`/`bulkRemoveTags` check `AssetPolicy::update`
per asset; the bulk endpoints check every asset before any write, so one unauthorized asset 403s
the whole request (and creates no tags). `bulkGetTags` is read-only and checks
`AssetPolicy::view` per asset.

### Data shapes

```yaml
Tag:
  id: int
  name: string        # unique, max 100 (Tag::MAX_NAME_LENGTH)
  type: user|ai|reference

asset_tag (pivot):
  asset_id: int
  tag_id: int
  attached_by: string|null   # 'user' | 'ai' | 'reference' — who attached/last-touched this link
  created_at / updated_at
```

### Layer touchpoints & ordering

```
Raw tag names → TagInputParser::parse() (tag-input.md)
  → Tag::resolveUserTagIds() / resolveReferenceTagIds() / resolveAiTagIds()   (name → id, reusing rows)
  → Asset::syncTagsWithAttribution(ids, attachedBy)
       → tags()->syncWithoutDetaching()        (attach missing links; never detaches)
       → one bulk UPDATE on asset_tag setting attached_by for ALL passed ids (last attacher wins)
```

Every pivot writer — `AssetController`, `AssetBulkController`, `ImportController`,
`Api\AssetApiController`, `AssetProcessingService`, `RekognitionService` — goes through
`syncTagsWithAttribution()` / `syncTagsPreservingAi()`, never the pivot directly.
`reference_tag_ids` is validated with `Rule::exists('tags','id')->where('type','reference')` —
a non-reference id is a 422, not a silent reattach.

### Persistence

`tags` (`name` unique, `type`); `asset_tag` pivot (composite key `(asset_id, tag_id)`,
`attached_by`, timestamps). Deleting a `Tag` cascades its `asset_tag` rows (FK cascade).

## Scenarios (BDD)

```gherkin
Scenario: A name the collation already holds reuses that tag instead of failing (REQ-6)
  Given MySQL/MariaDB with utf8mb4_unicode_ci and an existing tag "cafe"
  When resolveAiTagIds(['café']) runs
  Then it returns the id of "cafe" and creates nothing
  # Driver-specific: skips on SQLite, which compares byte-wise.
# pinned by: tests/Unit/TagTest.php

Scenario: A non-ASCII tag name is normalized the same way on every path
  Given a tag created as "été" through the tag input
  When an editor renames another tag to " ÉTÉ " it collides with "été" (422)
  And reference tags named "ÉTÉ" are removed by name, the reference tag "été" is found
# pinned by: tests/Feature/TagTest.php, tests/Feature/ApiTest.php

Scenario: Tag model relations and resolution
  Given a tag attached to three assets
  Then the tag has 3 assets
  And resolveUserTagIds(['portrait', 'new-tag']) reuses an existing "portrait" (any type, unchanged)
  And creates no duplicate row
# pinned by: tests/Unit/TagTest.php

Scenario: Attribution — a user add is 'user', and the last attacher wins
  Given an editor and an image asset
  When POST /assets/{asset}/tags is sent with tags=["landscape"]
  Then the tag is attached with attached_by = "user"
  And syncTagsWithAttribution(["tree"], "user") on an AI-attached "tree" flips the same row to "user"
# pinned by: tests/Feature/TagAttributionTest.php

Scenario: reference_tag_ids rejects a non-reference tag id
  When POST /assets/{asset}/tags is sent with reference_tag_ids=[<a user tag's id>]
  Then the response status is 422
# pinned by: tests/Feature/TagAttributionTest.php

Scenario: Renaming and deleting tags
  Then a reference tag can be renamed (200), including to a name of Tag::MAX_NAME_LENGTH
  And renaming an AI tag is 403
  And DELETE /tags/{tag} removes the row and detaches it from every asset
# pinned by: tests/Feature/TagTest.php

Scenario: Bulk add/remove/list operate across multiple assets and require authentication
  Given three assets
  When POST /assets/bulk/tags (add), /assets/bulk/tags/remove, and /assets/bulk/tags/list are called
  Then tags are added/removed/counted correctly across all three assets
  And each endpoint returns 401 for an unauthenticated request
  And a bulk add refused (403) for one asset creates no tag
# pinned by: tests/Feature/TagTest.php

Scenario: All Tags means user and AI tags; reference tags stay apart (REQ-reference-tags-kept-apart)
  Given a user, an ai and a reference tag
  When GET /tags is rendered with no type, with type=reference, and with type=bogus
  Then the view's activeType is "all", "reference" and "all", and typeCounts.all omits reference
  And GET /tags as JSON with type=all returns the user and ai tags only
  And GET /tags as JSON with no type returns all three
# pinned by: tests/Feature/TagTest.php

# — browser-level (see e2e-testing.md for the harness) —

Scenario: The tags page manages tags by type
  Given seeded user, ai and reference tags
  Then each is listed with its type badge, and the ai tag offers no rename control
  And a seeded tag can be renamed and deleted
  And a tag card links into the asset grid filtered to that tag
# pinned by: tests/e2e/tags.spec.js

Scenario: The tags page opens on All and ends with a separate Reference tab (REQ-reference-tags-kept-apart)
  When the tags page opens
  Then the tabs read All, User, AI, Reference and the reference tag is not listed
  When the Reference tab is clicked
  Then the reference tag is listed and the URL carries type=reference
  And clicking All again leaves a bare URL
# pinned by: tests/e2e/tags.spec.js

Scenario: The grid's tag filter lists all but reference tags until Reference is chosen (REQ-reference-tags-kept-apart)
  Given the asset grid's tag filter panel is open
  Then its type dropdown is on All, Reference last, and the reference tag is not listed
  When Reference is chosen
  Then the reference tag is listed
# pinned by: tests/e2e/asset-grid.spec.js

Scenario: The CSV Export tag-type dropdown defaults to All with Reference last (REQ-reference-tags-kept-apart)
  When the export page opens
  Then its tag-type dropdown reads All, User, AI, Reference and is on All
# pinned by: tests/e2e/csv-import-export.spec.js

Scenario: A tag added on the edit page appears on the asset and is removable inline
  Given the edit page for a seeded asset
  When a tag is typed and added, then saved
  Then the tag badge is listed on the detail page
  And it can be added and removed again from the grid's inline row input
# pinned by: tests/e2e/asset-detail.spec.js
```

## Tests & verification

- Unit: `tests/Unit/TagTest.php`
- Feature: `tests/Feature/TagTest.php`, `tests/Feature/TagAttributionTest.php` (web, API, CSV
  import, `applyUploadMetadata`) — `php artisan config:clear && php artisan test`
- E2E: `tests/e2e/tags.spec.js`, `tests/e2e/asset-grid.spec.js`, `tests/e2e/asset-detail.spec.js`,
  `tests/e2e/csv-import-export.spec.js`
