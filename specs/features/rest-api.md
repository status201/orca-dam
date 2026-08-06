# REST API (asset & tag endpoints)

```yaml
id: rest-api
status: implemented
version: 3
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
  (see [ADR-010](../decisions/adr-010-services-swallow-controllers-map.md)). The
  role rule itself lives in `App\Support\ErrorAudience`, which `clientError()`
  delegates to, so the global handler can apply the same rule; for a
  `QueryException` even a trusted caller gets the driver's sentence and never the
  SQL-with-bindings. A write the driver rejects returns a **keyed 422** with the
  same body shape as a `FormRequest` failure — never a bare 500 — and a 5xx body
  carries an `error_id` reference and is scrubbed for `api`-role callers
  regardless of `app.debug` ([`error-handling.md`](error-handling.md),
  [ADR-016](../decisions/adr-016-database-errors-are-user-errors.md)).
- **REQ-7** — Chunked upload endpoints (`/api/chunked-upload/*`) are declared in
  `routes/web.php`, **not** `routes/api.php` — they run under
  `auth.multi:web,sanctum,jwt` (session auth is the primary caller, the web
  uploader) rather than the `sanctum,jwt`-only guard list used by the rest of
  this file. See [`chunked-upload.md`](chunked-upload.md) for the endpoint
  contract itself; this spec only documents the routing-location quirk.
- **REQ-8** — `PATCH /api/assets/{asset}` persists **every** metadata field it
  validates and mirrors the web `AssetController::update`: the scalar columns
  `filename`, `alt_text`, `caption`, `license_type`, `license_expiry_date`,
  `copyright`, `copyright_source`, plus `tags` (user tags) and `reference_tag_ids`
  when present. Tag syncing preserves AI pivots verbatim and preserves the
  untouched category (user or reference) when only the other is submitted. The API
  never rewrites `s3_key` (see [ADR-006](../decisions/adr-006-immutable-s3-key.md));
  `filename` is the editable display name.

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
{ message: string, data: [Asset], duplicates: [DuplicatePayload & { existing_asset_url }]|null }
# DuplicatePayload = the 12 fields of DuplicateAssetException::formatDuplicate()
# (duplicate-detection.md). existing_asset_url is API-only: it pre-dates the shared
# payload, and public_url is NOT a substitute — that stays non-null for a trashed
# asset, where existing_asset_url is null.
# 409 when EVERY file in the batch is a duplicate
{ message: 'All files are duplicates of existing assets.', duplicates: [...] }

# PATCH /api/assets/{asset} (UpdateAssetRequest) — mirrors web AssetController::update
request:
  filename / alt_text / caption / license_type / license_expiry_date / copyright / copyright_source: string?  # all persisted
  tags: string[]?              # user tags — full sync, preserving AI + reference pivots
  reference_tag_ids: int[]?    # existing reference-tag ids — synced when present, else preserved
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

**Duplicate payload**: `AssetApiController::store` uses
`DuplicateAssetException::formatDuplicate()` like the web and chunked paths
(`duplicate-detection.md` REQ-4), so an API caller gets the full 12-field payload
and can render the same duplicates panel. It used to build a 3-field array inline,
which is why that requirement previously named only the web paths.

It additionally returns **`existing_asset_url`**, which is API-only: it pre-dates
the shared payload and is not part of it. This is deliberate rather than tidied
away — dropping it would break existing consumers, and `public_url` does not
replace it, since `public_url` stays non-null for a trashed asset where
`existing_asset_url` is null.

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

Scenario: Updating an asset persists all documented metadata fields
  Given an authenticated user who owns an asset
  When they PATCH /api/assets/{id} with filename, license_expiry_date, and copyright_source
  Then the response is 200 and all three fields are persisted to the asset
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

Scenario: Disabling the meta endpoint closes it and withholds the metadata (REQ-5)
  Given api_meta_endpoint_enabled is false
  When a client (no auth) sends GET /api/assets/meta for a known asset
  Then the response status is 403
  And the body contains none of the asset's metadata
# pinned by: tests/Security/RuntimeExposureTogglesTest.php

Scenario: The meta kill switch applies to authenticated callers too (REQ-5)
  Given api_meta_endpoint_enabled is false
  When an admin, editor or api caller sends GET /api/assets/meta
  Then the response status is 403
# pinned by: tests/Security/RuntimeExposureTogglesTest.php

Scenario: Disabling API uploads refuses the upload and stores nothing (REQ-4)
  Given api_upload_enabled is false
  And an authenticated api-role caller
  When they send POST /api/assets with a file
  Then the response status is 403
  And no asset row is created
# pinned by: tests/Security/RuntimeExposureTogglesTest.php
```

## Tests & verification

- Feature: `tests/Feature/ApiTest.php` (index/show/update/destroy/search/meta/
  health/folders/sort/type/search/pagination/user-field redaction),
  `tests/Feature/DuplicatePreventionTest.php` (API upload dedup + etag persistence).
- Security (REQ-4/REQ-5): `tests/Security/RuntimeExposureTogglesTest.php` — both states of
  `api_meta_endpoint_enabled` and `api_upload_enabled`, that the meta kill switch withholds the
  data rather than only changing the status, that it applies to authenticated callers too (the
  check precedes any auth consideration), and that only an admin can flip either setting. These
  settings are operator state rather than code, so the exposure of a public endpoint is invisible
  in a diff — see [security-invariants.md](security-invariants.md) REQ-7.
- Run: `php artisan config:clear && php artisan test tests/Feature/ApiTest.php`.
- Style: `./vendor/bin/pint --test`.

## Open questions / future

- `GET /api/assets/search` has no dedicated "requires authentication" test
  (unlike `index`/`tags`/`folders`); likely covered implicitly by shared
  middleware but not asserted directly.
