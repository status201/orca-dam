# REST API (asset & tag endpoints)

```yaml
id: rest-api
status: implemented
version: 8
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
  - folder-management
source:
  - app/Http/Controllers/Api/AssetApiController.php
  - app/Http/Controllers/Api/HealthController.php
  - app/Http/Controllers/TagController.php
  - app/Http/Controllers/Controller.php
  - app/Http/Requests/StoreAssetRequest.php
  - app/Http/Requests/UpdateAssetRequest.php
  - routes/api.php
  - app/Support/AssetFilter.php
  - app/Policies/AssetPolicy.php
```

## Background / Why

External systems (a Rich Text Editor, the WordPress plugin, backend integrations) list, upload,
update and delete assets through a REST API. It sits behind the multi-auth stack
([ADR-004](../decisions/adr-004-auth-multi.md)) — Sanctum, JWT or session — but is a **distinct
controller** (`Api\AssetApiController`) from the web `AssetController`: different validation shape,
JSON-only responses, role-aware error verbosity.

## Requirements

- **REQ-1** — All `/api/assets*` and `/api/tags*` routes (except the two public ones) require
  `auth.multi` (sanctum, then jwt) (`routes/api.php`); unauthenticated calls get `401`.
- **REQ-2** — `GET /api/assets` and `GET /api/assets/search` share the filters `tags`, `type`,
  `folder`, `sort`, `per_page` (capped at 100); the search term is `search` on the index and `q` on
  search. The index also takes an admin-only `user` filter (ignored for other roles).
- **REQ-3** — `DELETE /api/assets/{asset}` is gated by `AssetPolicy::delete` (an `api`-role token
  gets `403`). `PATCH /api/assets/{asset}` is gated by `AssetPolicy::updateViaApi`: admins update any
  asset, editors and api tokens only ones they uploaded (`403 {"message": "Unauthorized"}`
  otherwise). Viewing and creating are open to all three roles
  ([`authorization-policies.md`](authorization-policies.md)).
- **REQ-4** — API uploads are gated by `api_upload_enabled` (default on); when off,
  `POST /api/assets` returns `403` before touching S3.
- **REQ-5** — `GET /api/assets/meta` and `GET /api/health` are public, rate-limited at
  `throttle:60,1`; `assets/meta` is additionally gated by `api_meta_endpoint_enabled`.
- **REQ-6** — Error responses follow [`error-handling.md`](error-handling.md): role-aware detail via
  `Controller::clientError()` / `ErrorAudience`, driver rejections as a keyed 422, and 5xx bodies
  carrying `error_id` ([ADR-010](../decisions/adr-010-services-swallow-controllers-map.md),
  [ADR-016](../decisions/adr-016-database-errors-are-user-errors.md)).
- **REQ-7** — Chunked upload endpoints (`/api/chunked-upload/*`) are declared in `routes/web.php`,
  **not** `routes/api.php`, under `auth.multi:web,sanctum,jwt` (the web uploader's session is the
  primary caller). Endpoint contract: [`chunked-upload.md`](chunked-upload.md).
- **REQ-8** — `PATCH /api/assets/{asset}` persists **every** field it validates, mirroring the web
  `AssetController::update`: `filename`, `alt_text`, `caption`, `license_type`,
  `license_expiry_date`, `date_obtained`, `copyright`, `copyright_source`, plus `tags` (user tags)
  and `reference_tag_ids` when present. Tag syncing preserves AI pivots and the untouched category.
  The API never rewrites `s3_key` ([ADR-006](../decisions/adr-006-immutable-s3-key.md)); `filename`
  is the editable display name.

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
GET    /api/folders:         FolderController::index           # see folder-management.md

# routes/web.php — chunked upload, auth.multi:web,sanctum,jwt, throttle:chunked-upload (100/min)
POST /api/chunked-upload/init | chunk | complete | abort
```

Sort values (`Asset::scopeApplySort`): `date_desc` (default) · `date_asc` · `upload_asc` ·
`upload_desc` · `size_asc` · `size_desc` · `name_asc` · `name_desc` · `s3key_asc` · `s3key_desc`.
`type` accepts MIME prefixes or `images`, `videos`, `documents` (`Asset::scopeOfType`).

### Data shapes

```yaml
# list response envelope — Laravel paginator + Asset::APPEND_FIELDS per item
paginated_assets:
  data: [Asset & { url, thumbnail_url, resize_s_url, resize_m_url, resize_l_url, formatted_size, folder }]
  total: int
  per_page: int
  # embedded user exposes only id/name/role — no email/password/jwt_secret/preferences

# POST /api/assets (StoreAssetRequest)
request:
  files.*: file            # required, max 500MB, must pass AllowedUploadExtension
  folder: string?
  keep_original_filename: bool?
  metadata_*: # HasUploadMetadataRules (asset-upload.md REQ-5)

# 201 response
{ message: string, data: [Asset], duplicates: [DuplicatePayload & { existing_asset_url }]|null }
# DuplicatePayload = DuplicateAssetException::formatDuplicate() (duplicate-detection.md REQ-4).
# existing_asset_url is API-only and kept for existing consumers; public_url is NOT a substitute
# (it stays non-null for a trashed asset, where existing_asset_url is null).
# 409 when EVERY file in the batch is a duplicate
{ message: 'All files are duplicates of existing assets.', duplicates: [...] }

# PATCH /api/assets/{asset} (UpdateAssetRequest)
request:
  filename / alt_text / caption / license_type / copyright / copyright_source: string?
  license_expiry_date / date_obtained: date?   # YYYY-MM-DD; license_expiry_date is retired from
                                               # the web edit form but still accepted here
  tags: string[]?              # user tags — full sync, preserving AI + reference pivots
  reference_tag_ids: int[]?    # synced when present, else preserved
response: { message: string, data: Asset }

# GET /api/assets/meta (public)
response: { alt_text, caption, license_type, copyright, filename, url }

# GET /api/health (public)
response: { status: 'ok'|'error', database: 'ok'|'error' }   # 200 or 503
```

### Layer touchpoints & ordering

`throttle` → `auth.multi` → `StoreAssetRequest`/`UpdateAssetRequest` → `AssetApiController`
(authorize where applicable) → `S3Service` / `AssetProcessingService` → `Asset`.
`index`/`show`/`store` rely on the route being open to all three roles.

### Persistence

No new tables ([`asset-model.md`](asset-model.md)). Settings read at request time (1h cache,
[ADR-011](../decisions/adr-011-settings-in-db.md)): `api_upload_enabled`, `api_meta_endpoint_enabled`.

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
  Given an asset owned by a user with an email and stored preferences
  When the client fetches GET /api/assets or GET /api/assets/{id}
  Then the embedded user object exposes only id, name, and role
# pinned by: tests/Feature/ApiTest.php

Scenario: API upload deduplicates by etag and cleans up the new S3 object
  Given an existing asset with etag "abc123"
  When the client uploads a file whose computed etag is also "abc123" via POST /api/assets
  Then the response status is 409 with the existing asset's id
  And the newly uploaded S3 object is deleted
# pinned by: tests/Feature/DuplicatePreventionTest.php

Scenario: An api-role token cannot delete an asset; other roles can soft-delete their own
  Given an authenticated user who owns an asset
  When they send DELETE /api/assets/{id}
  Then the response is 403 and nothing is deleted if their role is "api"
  And otherwise the response is 200 and the asset is soft-deleted
# pinned by: tests/Feature/ApiTest.php

Scenario: Updating an asset persists all documented metadata fields
  Given an authenticated user who owns an asset
  When they PATCH /api/assets/{id} with filename, date_obtained, license_expiry_date, and copyright_source
  Then the response is 200 and all four fields are persisted to the asset
# pinned by: tests/Feature/ApiTest.php

Scenario: reference_tag_ids syncs reference tags while preserving user and AI tags
  Given an asset carrying a user tag and an AI tag
  When they PATCH /api/assets/{id} with reference_tag_ids for an existing reference tag
  Then the reference tag is attached and the user and AI tags remain
# pinned by: tests/Feature/ApiTest.php

Scenario: The public meta endpoint resolves an asset by its public URL
  Given an asset with a known s3_key
  When a client (no auth) sends GET /api/assets/meta?url=<the asset's public URL>
  Then the response is 200 with alt_text/caption/license_type/copyright/filename/url
  But a URL outside the configured domain returns 400
# pinned by: tests/Feature/ApiTest.php

Scenario: The health endpoint is public and reports database connectivity
  Given no authentication is provided
  When the client sends GET /api/health
  Then the response is 200 with { status: "ok", database: "ok" }
# pinned by: tests/Feature/ApiTest.php

Scenario: The runtime toggles close their endpoints (REQ-4, REQ-5)
  Given api_meta_endpoint_enabled or api_upload_enabled is false
  When GET /api/assets/meta or POST /api/assets is called
  Then the response is 403 and no metadata is returned / no asset is created
  # full scenarios: security-invariants.md REQ-7
# pinned by: tests/Security/RuntimeExposureTogglesTest.php
```

## Tests & verification

- Feature: `tests/Feature/ApiTest.php`, `tests/Feature/DuplicatePreventionTest.php`.
- Security: `tests/Security/RuntimeExposureTogglesTest.php` — runtime toggles
  ([security-invariants.md](security-invariants.md) REQ-7).
- Run: `php artisan config:clear && php artisan test tests/Feature/ApiTest.php`; style
  `./vendor/bin/pint --test`.

## Open questions / future

- `GET /api/assets/search` has no dedicated "requires authentication" test.
