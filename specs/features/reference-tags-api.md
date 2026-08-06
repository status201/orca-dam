# Reference tags API

```yaml
id: reference-tags-api
status: implemented
version: 3
owner: core
related:
  - architecture
  - rest-api
  - tags
source:
  - app/Http/Controllers/Api/AssetApiController.php
  - app/Console/Commands/ReferenceTagCreateCommand.php
  - app/Models/Tag.php
  - routes/api.php
```

## Background / Why

Reference tags record that an external system is *using* an asset (e.g. the
WordPress plugin writing `wp:<site>/post/<id>` when an editor embeds an image
in a post). They are a third `Tag::type` alongside `user` and `ai`, and by
design can only be **created** through this API — never through the web
tag-input flow — so a reference tag is always evidence of real external usage,
not something a human typed in. See
[ADR-012](../decisions/adr-012-reference-tags-api-only.md) for the full
rationale. They remain editable/deletable from the web UI so admins can clean
up stale marks.

## Requirements

- **REQ-1** — `POST /api/reference-tags` accepts a singular or batch asset
  identifier (`asset_id` / `asset_ids` / `s3_key` / `s3_keys`, any combination)
  plus a `tags` array; every asset found gets the tags synced with
  `attached_by: reference` via `Asset::syncTagsWithAttribution()`.
- **REQ-2** — Removal has two shapes: `DELETE /api/reference-tags/{tag}` (by
  tag ID) and `DELETE /api/reference-tags` (by `tag_name`/`tag_names`), both
  accepting the same identifier fields as create.
- **REQ-3** — `DELETE /api/reference-tags/{tag}` refuses to touch a non-
  `reference` tag (422) — this endpoint cannot be used to strip a `user`/`ai`
  tag.
- **REQ-4** — Removing by name only matches tags whose `type` is `reference`;
  a `user`/`ai` tag with the same name is reported as "not found", never
  detached.
- **REQ-5** — Every identifier-bearing request validates that at least one of
  the four identifier fields is present (`422` with an `identifiers` error
  otherwise), and unresolved `asset_id`/`s3_key`/`s3_keys` entries are reported
  back rather than silently dropped (`not_found_s3_keys` / `not_found_tags`).
- **REQ-6** — `reference-tag:create` (console) is the operator-facing way to
  pre-create a reference tag (e.g. so it shows up for autocomplete) without
  attaching it to an asset yet; it refuses to silently retype an existing
  non-reference tag with the same name.
- **REQ-7** — All three endpoints require `auth.multi` (sanctum/JWT), rate-
  limited at `throttle:100,1` (`routes/api.php`).

## Technical design

### Contract / public interface

```yaml
POST   /api/reference-tags:          AssetApiController::addReferenceTags
DELETE /api/reference-tags/{tag}:    AssetApiController::removeReferenceTag
DELETE /api/reference-tags:          AssetApiController::removeReferenceTagsByName

# supporting
Tag::resolveReferenceTagIds(names): array<int>   # find-or-create as type=reference
Asset::syncTagsWithAttribution(tagIds, 'reference')
App\Support\TagInputParser::parse()               # splits/normalizes the `tags` input
php artisan reference-tag:create {names?*}          # pre-create without attaching
```

### Data shapes

```yaml
# POST /api/reference-tags
request:
  asset_id: int?            # exists:assets,id
  asset_ids: int[]?         # max 500, each exists:assets,id
  s3_key: string?           # max 1024 — the assets.s3_key column width (ColumnLimits), which is
                            # also S3's own key limit. Was 255 while the column was too narrow.
  s3_keys: string[]?        # max 500, each max 1024
  tags: string[]            # required, min 1, max 100, each max 100 chars
response_200:
  message: string           # "Reference tags added to N asset(s)"
  data: Asset[]             # with tags loaded
  not_found_s3_keys: string[]?   # present only if some s3_key(s) didn't resolve
response_404: { message: 'No assets found' }        # identifiers valid but resolve to zero assets
response_422: { message: 'Validation failed', errors: {...} }

# DELETE /api/reference-tags/{tag}   (tag = Tag route-model-bound)
request: { asset_id | asset_ids | s3_key | s3_keys }   # at least one required
response_200: { message: 'Reference tag removed from N asset(s)', not_found_s3_keys: string[]? }
response_404: { message: 'No assets found' }
response_422: { message: 'Only reference tags can be removed via this endpoint' }  # tag.type !== reference

# DELETE /api/reference-tags   (by name)
request:
  tag_name: string?          # max 100
  tag_names: string[]?       # min 1, max 100, each max 100
  # + one of asset_id / asset_ids / s3_key / s3_keys
response_200: { message: 'Reference tag(s) removed from N asset(s)', not_found_tags: string[]?, not_found_s3_keys: string[]? }
response_404: { message: 'No matching reference tags found', not_found_tags: string[] }
response_422: { message: 'Validation failed', errors: { tags: [...] } | { identifiers: [...] } }
```

### Layer touchpoints & ordering

`throttle` → `auth.multi` → inline `validator()` (identifier-presence checked
in a `$validator->after()` closure, not a FormRequest) →
`AssetApiController::collectAssetsFromRequest()` (merges/dedupes assets found
across the four identifier fields, tracking unresolved `s3_key`/`s3_keys`) →
`Tag::resolveReferenceTagIds()` / `Tag::referenceTags()` scope →
`Asset::syncTagsWithAttribution()` or `$asset->tags()->detach()`.

Tag *names* passed to `removeReferenceTagsByName` are lowercased/trimmed
before lookup, matching the normalization `TagInputParser` applies on create —
so `POST` and both `DELETE` shapes agree on casing.

### Persistence

No new tables — `tags.type = 'reference'` and the `asset_tag` pivot
(`attached_by = 'reference'`) on the existing schema
(see [`tags.md`](tags.md), [`asset-model.md`](asset-model.md)).

## Scenarios (BDD)

```gherkin
Scenario: Add reference tags to a single asset by asset_id
  Given an existing asset
  When POST /api/reference-tags is sent with asset_id and tags
  Then the response is 200 and the asset has the given tags with type "reference"
# pinned by: tests/Feature/ApiTest.php

Scenario: Add reference tags by s3_key, normalizing tag case
  Given an existing asset with a known s3_key
  When POST /api/reference-tags is sent with s3_key and tags in mixed case
  Then the asset's tag is stored lowercased with type "reference"
# pinned by: tests/Feature/ApiTest.php

Scenario: Batch add across asset_ids and s3_keys reports unresolved s3_keys
  Given one asset exists and one requested s3_key does not
  When POST /api/reference-tags is sent with both s3_keys
  Then the response is 200, tags are applied to the found asset
  And not_found_s3_keys lists the missing key
# pinned by: tests/Feature/ApiTest.php

Scenario: Add reference tags fails validation with no identifiers
  When POST /api/reference-tags is sent with only a tags array
  Then the response status is 422
# pinned by: tests/Feature/ApiTest.php

Scenario: Remove a reference tag by ID
  Given an asset with an attached reference tag
  When DELETE /api/reference-tags/{tag} is sent with the asset's identifier
  Then the response is 200 and the tag is detached
# pinned by: tests/Feature/ApiTest.php

Scenario: Cannot remove a non-reference tag via the by-ID endpoint
  Given an asset with an attached user-type tag
  When DELETE /api/reference-tags/{tag} targets that tag
  Then the response status is 422
# pinned by: tests/Feature/ApiTest.php

Scenario: Remove reference tags by name across multiple assets
  Given two assets each with two reference tags attached
  When DELETE /api/reference-tags is sent with tag_names and asset_ids
  Then the response is 200 and all matching tags are detached from both assets
# pinned by: tests/Feature/ApiTest.php

Scenario: Remove by name treats a same-named user tag as not found
  Given an asset with a user-type tag sharing a name with no reference tag
  When DELETE /api/reference-tags is sent with that tag_name
  Then the response status is 404 with not_found_tags listing the name
  And the user tag remains attached
# pinned by: tests/Feature/ApiTest.php

Scenario: Remove by name requires at least one tag identifier and one asset identifier
  When DELETE /api/reference-tags is sent with only an asset_id (no tag_name/tag_names)
  Then the response status is 422 with an error under "tags"
# pinned by: tests/Feature/ApiTest.php

Scenario: All reference-tags endpoints require authentication
  Given no authentication is provided
  When a client calls POST or DELETE /api/reference-tags(/{tag})
  Then the response status is 401
# pinned by: tests/Feature/ApiTest.php

Scenario: Pre-create a reference tag via console without attaching it
  When the operator runs `php artisan reference-tag:create linkedin`
  Then a tag "linkedin" of type "reference" exists
# pinned by: tests/Feature/ReferenceTagCreateCommandTest.php

Scenario: Console command refuses to retype an existing non-reference tag
  Given a tag "collide" already exists with type "user"
  When the operator runs `php artisan reference-tag:create collide`
  Then the command exits with a failure code
  And the tag's type is unchanged
# pinned by: tests/Feature/ReferenceTagCreateCommandTest.php
```

## Tests & verification

- Feature: `tests/Feature/ApiTest.php` ("Reference Tags API tests" and "Batch
  Reference Tags API tests" and "Remove Reference Tags by Name API tests"
  sections), `tests/Feature/ReferenceTagCreateCommandTest.php`.
- Run: `php artisan config:clear && php artisan test tests/Feature/ApiTest.php tests/Feature/ReferenceTagCreateCommandTest.php`.
- Style: `./vendor/bin/pint --test`.

## Open questions / future

- No test asserts the web UI can *edit or delete* an existing reference tag
  (ADR-012's "still editable/deletable in the web UI" claim) — `TagController`
  appears to allow it (`update`/`destroy` don't special-case `type`), but the
  behaviour isn't pinned under this spec; likely belongs to
  [`tags.md`](tags.md) instead.
