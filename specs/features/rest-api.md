# REST API (asset & tag endpoints)

```yaml
id: rest-api
status: implemented
version: 1
owner: core
related:
  - architecture
  - asset-model
  - duplicate-detection
  - authorization-policies
  - chunked-upload
  - upload-policy
  - tags
  - reference-tags-api
source:
  - app/Http/Controllers/Api/AssetApiController.php
  - app/Http/Controllers/Api/HealthController.php
  - app/Http/Controllers/TagController.php
  - app/Http/Controllers/Controller.php
  - app/Http/Requests/StoreAssetRequest.php
  - app/Http/Requests/UpdateAssetRequest.php
  - routes/api.php
```

## Background / Why

ORCA exposes a REST API so external systems (a Rich Text Editor, the WordPress
plugin, other backend integrations) can list, upload, update, and delete assets
without going through the Blade UI. It sits behind the same multi-auth stack as
the rest of the app (see [ADR-004](../decisions/adr-004-auth-multi.md)) so a
Sanctum token, a JWT, or an authenticated session can all call it, but it is a
**distinct controller** (`Api\AssetApiController`) from the web
`App\Http\Controllers\AssetController` — different validation shape, JSON-only
responses, and a role split on error verbosity.

## Requirements

- **REQ-1** — All `/api/assets*` and `/api/tags*` routes (except the two public
  ones) require `auth.multi:sanctum,jwt` (`routes/api.php`); unauthenticated calls
  get `401`.
- **REQ-2** — Every list/search endpoint accepts the same filter vocabulary:
  `search`/`q`, `tags`, `type`, `folder`, `sort`, `per_page` (capped at 100).
- **REQ-3** — `DELETE /api/assets/{asset}` is policy-gated
  (`AssetPolicy::delete`) so an `api`-role token gets `403` and cannot delete —
  every other asset ability on this controller (`view`, `create`, `update`) is
  open to all three roles, matching the ability matrix in
  [`authorization-policies.md`](authorization-policies.md).
- **REQ-4** — API uploads are gated by the `api_upload_enabled` setting
  (default on); when off, `POST /api/assets` returns `403` before touching S3.
- **REQ-5** — `GET /api/assets/meta` and `GET /api/health` are public (no auth),
  rate-limited at `throttle:60,1` to curb enumeration/probing; `assets/meta` is
  additionally gated by `api_meta_endpoint_enabled`.
- **REQ-6** — Error responses are role-aware: `Controller::clientError()` gives
  `api`-role users a generic message and admins/editors the exception detail
  (see [ADR-010](../decisions/adr-010-services-swallow-controllers-map.md)).
- **REQ-7** — Chunked upload endpoints (`/api/chunked-upload/*`) are declared in
  `routes/web.php`, **not** `routes/api.php` — they run under
  `auth.multi:web,sanctum,jwt` (session auth is the primary caller, the web
  uploader) rather than the `sanctum,jwt`-only guard list used by the rest of
  this file. See [`chunked-upload.md`](chunked-upload.md) for the endpoint
  contract itself; this spec only documents the routing-location quirk.

## Technical design

### Contract / public interface

```yaml
# routes/api.php — throttle:60,1, no auth
GET  /api/assets/meta:      AssetApiController::getMeta      # ?url=<public asset URL>
GET  /api/health:           HealthController::__invoke

# routes/api.php — auth.multi (sanctum,jwt), throttle:120,1
GET    /api/assets:          AssetApiController::index
POST   /api/assets:          AssetApiController::store
GET    /api/assets/search:   AssetApiController::search
GET    /api/assets/{asset}:  AssetApiController::show
PATCH  /api/assets/{asset}:  AssetApiController::update
DELETE /api/assets/{asset}:  AssetApiController::destroy      # authorize('delete', $asset)
GET    /api/tags:            TagController::index             # ?type=
GET    /api/tags/search:     TagController::search
GET    /api/tags/{ids}:      TagController::show              # single id or comma-list, max 200
GET    /api/folders:         FolderController::index

# routes/web.php — chunked upload, auth.multi:web,sanctum,jwt, throttle:100,1
POST /api/chunked-upload/init | chunk | complete | abort
```

Sort values (`Asset::scopeApplySort`, shared by `index` and `search`):
`date_desc` (default) · `date_asc` · `upload_asc` · `upload_desc` · `size_asc` ·
`size_desc` · `name_asc` · `name_desc` · `s3key_asc` · `s3key_desc`.

`type` accepts MIME prefixes or the plural aliases (`images`, `videos`,
`documents`) via `Asset::scopeOfType`.

### Data shapes

```yaml
# GET/POST list response envelope — Laravel paginator + Asset::APPEND_FIELDS per item
paginated_assets:
  data: [Asset & { url, thumbnail_url, resize_s_url, resize_m_url, resize_l_url, formatted_size, folder }]
  total: int
  per_page: int
  # user relation is loaded but only id/name/role are visible — no email/password/
  # jwt_secret/preferences (Asset -> User relation uses a restricted resource shape)

# POST /api/assets (StoreAssetRequest)
request:
  files.*: file            # required, max 500MB, must pass AllowedUploadExtension
  folder: string?
  keep_original_filename: bool?
  metadata_tags / metadata_license_type / metadata_copyright / metadata_copyright_source: # HasUploadMetadataRules

# 201 response
{ message: string, data: [Asset], duplicates: [{ filename, existing_asset_id, existing_asset_url }]|null }
# 409 when EVERY file in the batch is a duplicate
{ message: 'All files are duplicates of existing assets.', duplicates: [...] }

# PATCH /api/assets/{asset} (UpdateAssetRequest)
request:
  alt_text / caption / license_type / copyright: string?   # <- the only 4 fields actually persisted
  filename / license_expiry_date / copyright_source / tags / reference_tag_ids: # validated but see Open Questions
response: { message: string, data: Asset }

# GET /api/assets/meta (public)
response: { alt_text, caption, license_type, copyright, filename, url }

# GET /api/health (public)
response: { status: 'ok'|'error', database: 'ok'|'error' }   # 200 or 503
```

### Layer touchpoints & ordering

`throttle` → `auth.multi` → `StoreAssetRequest`/`UpdateAssetRequest` (validate)
→ `AssetApiController` (authorize where applicable) → `S3Service` /
`AssetProcessingService` → `Asset` (Eloquent). `destroy` is the only action that
calls `$this->authorize()` explicitly; `index`/`show`/`update`/`store` rely on
the route being open to all three known roles (no policy check beyond
`isKnownRole`-equivalent access via the guard).

**Divergence from the web/chunked upload paths**: `AssetApiController::store`
does **not** use `DuplicateAssetException::formatDuplicate()` — it builds its
own lighter `duplicates` array (`filename`, `existing_asset_id`,
`existing_asset_url`) inline and deletes the just-uploaded S3 object on etag
collision. The richer duplicate payload (`thumbnail_url`, `show_url`,
`is_trashed`, `can_restore`, …) described in `CLAUDE.md`'s Upload workflow is a
web/chunked-upload-only contract — see [`duplicate-detection.md`](duplicate-detection.md).

### Persistence

No new tables — reads/writes the existing `assets`/`tags`/`asset_tag` schema
documented in [`asset-model.md`](asset-model.md). Settings consulted at request
time (1h cache, [ADR-011](../decisions/adr-011-settings-in-db.md)):
`api_upload_enabled`, `api_meta_endpoint_enabled`.

## Scenarios (BDD)

```gherkin
Scenario: Unauthenticated request to list assets is rejected
  Given no authentication is provided
  When the client sends GET /api/assets
  Then the response status is 401
# pinned by: tests/Feature/ApiTest.php

Scenario: Paginated asset list respects search, type, and folder filters
  Given assets exist with different filenames, types, and s3_key folders
  When the client sends GET /api/assets with search/type/folder query params
  Then only matching assets are returned in the paginated envelope
# pinned by: tests/Feature/ApiTest.php

Scenario: Default sort is newest-updated-first
  Given two assets with different updated_at timestamps
  When the client sends GET /api/assets with no sort param
  Then the newer asset is returned first
# pinned by: tests/Feature/ApiTest.php

Scenario: List and show responses hide sensitive user fields
  Given an asset owned by a user with an email and encrypted preferences
  When the client fetches GET /api/assets or GET /api/assets/{id}
  Then the embedded user object exposes only id, name, and role
# pinned by: tests/Feature/ApiTest.php

Scenario: API upload deduplicates by etag and cleans up the new S3 object
  Given an existing asset with etag "abc123"
  When the client uploads a file whose computed etag is also "abc123" via POST /api/assets
  Then the response status is 409 with the existing asset's id
  And the newly uploaded S3 object is deleted
# pinned by: tests/Feature/DuplicatePreventionTest.php

Scenario: An api-role token cannot delete an asset
  Given an authenticated user with role "api" who owns an asset
  When they send DELETE /api/assets/{id}
  Then the response status is 403
  And the asset is not soft-deleted
# pinned by: tests/Feature/ApiTest.php

Scenario: Any authenticated role can soft-delete their own asset
  Given an authenticated user with an owned asset
  When they send DELETE /api/assets/{id}
  Then the response status is 200 and the asset is soft-deleted
# pinned by: tests/Feature/ApiTest.php

Scenario: The public meta endpoint resolves an asset by its public URL
  Given an asset with a known s3_key
  When a client (no auth) sends GET /api/assets/meta?url=<the asset's public URL>
  Then the response is 200 with alt_text/caption/license_type/copyright/filename/url
# pinned by: tests/Feature/ApiTest.php

Scenario: The meta endpoint rejects a URL that doesn't match the configured domain
  When a client sends GET /api/assets/meta?url=<an unrelated external URL>
  Then the response status is 400
# pinned by: tests/Feature/ApiTest.php

Scenario: The health endpoint is public and reports database connectivity
  Given no authentication is provided
  When the client sends GET /api/health
  Then the response is 200 with { status: "ok", database: "ok" }
# pinned by: tests/Feature/ApiTest.php
```

## Tests & verification

- Feature: `tests/Feature/ApiTest.php` (index/show/update/destroy/search/meta/
  health/folders/sort/type/search/pagination/user-field redaction),
  `tests/Feature/DuplicatePreventionTest.php` (API upload dedup + etag persistence).
- Run: `php artisan config:clear && php artisan test tests/Feature/ApiTest.php`.
- Style: `./vendor/bin/pint --test`.

## Open questions / future

- `UpdateAssetRequest` validates `filename`, `license_expiry_date`,
  `copyright_source`, `tags`, and `reference_tag_ids`, but
  `AssetApiController::update` only persists `alt_text`, `caption`,
  `license_type`, and `copyright` via `$request->only([...])` (plus `tags` in a
  separate branch). A `PATCH` with `filename` or `license_expiry_date` passes
  validation and returns 200 but silently does not change those fields — no
  test currently pins this either way. Worth confirming whether this is
  intentional (fields reserved for a future contract) or a gap before another
  caller depends on it.
- No test exercises `api_upload_enabled = false` returning 403 on
  `POST /api/assets`, nor `api_meta_endpoint_enabled = false` on
  `GET /api/assets/meta` — both are real branches in `AssetApiController` with
  no coverage found under `tests/`.
- `GET /api/assets/search` has no dedicated "requires authentication" test
  (unlike `index`/`tags`/`folders`); likely covered implicitly by shared
  middleware but not asserted directly.
