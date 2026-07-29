# Tags

```yaml
id: tags
status: implemented
version: 1
owner: core
related:
  - architecture
  - tag-input
source:
  - app/Models/Tag.php
  - app/Models/Asset.php
  - app/Http/Controllers/TagController.php
  - app/Http/Controllers/AssetController.php
  - app/Http/Controllers/AssetBulkController.php
  - database/factories/TagFactory.php
```

## Background / Why

Assets are categorised by free-form tags rather than a fixed taxonomy. Three
independent producers attach tags to the same asset — a human editor, AWS
Rekognition (see [`ai-tagging.md`](ai-tagging.md)), and external systems via the
reference-tags API (see [ADR-012](../decisions/adr-012-reference-tags-api-only.md))
— so the `asset_tag` pivot tracks *who last attached* each tag rather than only
*whether* it's attached. This "last attacher wins" rule lets a user manually
re-affirm an AI-suggested tag (promoting it to a user tag) without a separate
data model per tag origin.

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
- **REQ-6** — Resolving a tag name to an ID never changes an existing tag's
  `type`. `Tag::resolveUserTagIds()` / `resolveReferenceTagIds()` reuse an
  existing tag of any type if the name already exists.

## Technical design

### Contract / public interface

```yaml
Tag (app/Models/Tag.php):
  MAX_NAME_LENGTH: 100                       # shared by validation + TagInputParser
  assets(): BelongsToMany                    # ->withPivot('attached_by')->withTimestamps()
  resolveUserTagIds(array $names): array     # firstOrCreate as type=user; reuses existing tag as-is
  resolveReferenceTagIds(array $names): array # same, type=reference
  scopeUserTags / scopeAiTags / scopeReferenceTags
  scopeSearch(?string $search)               # name LIKE %search%

Asset (app/Models/Asset.php):
  tags(): BelongsToMany       # ordered: user(0) < reference(1) < ai/other(2)
  userTags() / aiTags() / referenceTags(): BelongsToMany   # type-filtered
  syncTagsWithAttribution(array $tagIds, string $attachedBy): void

TagController:
  index(Request)          # GET /tags — paginated JSON (expectsJson) or type-counts view
  search(Request)          # GET /tags/search — autocomplete, ?type= or ?types=a,b
  show(string $ids)        # GET /tags/{ids} — single object (1 id) or array (2-200 ids)
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

Routes (`routes/web.php`, session-authenticated group):

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

`TagController`'s constructor has a commented-out `$this->middleware('auth')` —
authentication is actually enforced by the enclosing route-group middleware
(`auth.multi`), not the controller; the comment is vestigial.

Authorization: `addTags`/`removeTag`/`bulkAddTags`/`bulkRemoveTags` all check
`AssetPolicy::update` per asset (in a `foreach` for the bulk endpoints, so one
unauthorized asset in a batch 403s the whole request before any writes).
`bulkGetTags` is read-only and checks `AssetPolicy::view` instead — the
stricter update check would be a false restriction, but the per-asset loop is
kept "for consistency ... and to guard against information disclosure if
per-asset ownership is ever added" (see the comment in `AssetBulkController::bulkGetTags`).

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
Request (tag names as raw strings)
  -> TagInputParser::parse()            (see tag-input.md — comma-split/trim/lowercase/dedup)
  -> Tag::resolveUserTagIds() / resolveReferenceTagIds()   (name -> id, reusing existing rows)
  -> Asset::syncTagsWithAttribution(ids, attachedBy)
       -> tags()->syncWithoutDetaching()          (attach missing links)
       -> DB::table('asset_tag')->update(...)     (bulk-set attached_by on ALL passed ids
                                                    in one query — this is the "last attacher
                                                    wins" step, applied even to links that
                                                    already existed before this call)
```

`reference_tag_ids` (as opposed to free-text `tags`) is validated against
`Rule::exists('tags','id')->where('type','reference')` — a non-reference tag ID
submitted this way is rejected with 422 rather than silently reattached.

### Persistence

- `tags` table — `name` unique index, `type` column.
- `asset_tag` pivot table — composite key `(asset_id, tag_id)`, `attached_by`
  column, timestamps.
- Deleting a `Tag` model cascades removal of its `asset_tag` rows (DB FK
  cascade / Eloquent relationship — no explicit detach loop needed in
  `TagController::destroy` or `bulkDestroy`).

## Scenarios (BDD)

```gherkin
Scenario: A tag can belong to many assets
  Given a tag and three assets
  When the tag is attached to all three
  Then the tag has 3 assets
# pinned by: tests/Unit/TagTest.php

Scenario: Resolving tag names reuses an existing tag without changing its type
  Given a tag "portrait" already exists with type "user"
  When resolveUserTagIds(['portrait', 'new-tag']) is called
  Then the existing tag's id is returned and no duplicate "portrait" row is created
# pinned by: tests/Unit/TagTest.php

Scenario: Adding a tag through the single-asset endpoint attributes it to the user
  Given an editor and an image asset
  When POST /assets/{asset}/tags is sent with tags=["landscape"]
  Then the tag is attached with asset_tag.attached_by = "user"
# pinned by: tests/Feature/TagAttributionTest.php

Scenario: Last attacher wins — re-attaching an AI tag as a user updates attribution
  Given an asset with an AI-attached tag "tree" (attached_by = "ai")
  When syncTagsWithAttribution(["tree"], "user") is called
  Then the same pivot row now has attached_by = "user" (no duplicate row)
# pinned by: tests/Feature/TagAttributionTest.php

Scenario: Reference tags are renamable in the web UI
  Given a reference-typed tag created via the API
  When PATCH /tags/{tag} renames it
  Then the rename succeeds (200) and the tag's name is updated
# pinned by: tests/Feature/TagTest.php

Scenario: AI tags cannot be renamed
  Given an AI-typed tag
  When PATCH /tags/{tag} attempts to rename it
  Then the response status is 403
# pinned by: tests/Feature/TagTest.php

Scenario: Deleting a tag detaches it from every asset
  Given a tag attached to an asset
  When DELETE /tags/{tag} is sent
  Then the tag row is gone and the asset no longer has that tag
# pinned by: tests/Feature/TagTest.php

Scenario: reference_tag_ids rejects a non-reference tag id
  Given a user-typed tag
  When POST /assets/{asset}/tags is sent with reference_tag_ids=[<that tag's id>]
  Then the response status is 422
# pinned by: tests/Feature/TagAttributionTest.php

Scenario: Bulk add/remove/list operate across multiple assets and require authentication
  Given three assets
  When POST /assets/bulk/tags (add), /assets/bulk/tags/remove, and /assets/bulk/tags/list are called
  Then tags are added/removed/counted correctly across all three assets
  And each endpoint returns 401 for an unauthenticated request
# pinned by: tests/Feature/TagTest.php
```

## Tests & verification

- Unit: `tests/Unit/TagTest.php` — model relationships, `resolveUserTagIds`,
  `resolveReferenceTagIds`, scopes.
- Feature: `tests/Feature/TagTest.php` — `TagController` + single/bulk asset
  tag-attach endpoints, `tests/Feature/TagAttributionTest.php` — `attached_by`
  semantics end-to-end (web, API, CSV import, `applyUploadMetadata`).
- Run: `php artisan config:clear && php artisan test tests/Feature/TagTest.php tests/Feature/TagAttributionTest.php tests/Unit/TagTest.php`
- E2E: `tests/e2e/tags.spec.js` (rename/delete/type badges) and `tests/e2e/asset-detail.spec.js` (edit-page + inline row tag input).

## Open questions / future

- The reference-tags REST API contract itself (`POST /api/reference-tags`,
  `DELETE /api/reference-tags[/{tag}]`, batch `asset_ids`/`s3_keys` resolution)
  is documented by the not-yet-written `reference-tags-api.md` spec (Wave D of
  the backfill) — this spec deliberately only covers the web-side tag CRUD and
  the attribution rule that reference tags participate in.
