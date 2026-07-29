# Folder management

```yaml
id: folder-management
status: implemented
version: 1
owner: core
related:
  - architecture
  - s3-storage
  - rest-api
  - discovery-import
  - settings
  - authorization-policies
source:
  - app/Http/Controllers/FolderController.php
  - app/Services/S3Service.php
  - routes/web.php
  - routes/api.php
```

## Background / Why

S3 has no folders — only key prefixes. ORCA nonetheless presents a folder picker on
upload, a folder filter on the grid, and a per-user home folder, all of which need a
*list* of folders to offer. Enumerating prefixes from S3 on every request would be slow
and would fail closed when S3 is unreachable, so the list is materialized into the
`s3_folders` setting and served from there. This spec covers the three HTTP endpoints
that read, refresh and extend that list; the key layout and the underlying
`S3Service::listFolders()` / `createFolder()` primitives belong to
[s3-storage.md](s3-storage.md).

## Requirements

- **REQ-1** — `GET /api/folders` returns the cached folder list to **any** authenticated
  caller (session, Sanctum or JWT via `auth.multi`). It is the only one of the three
  endpoints with no role restriction — every role needs the picker.
- **REQ-2** — When `s3_folders` is empty, `index` back-fills it by deriving folders from
  existing assets: `dirname(s3_key)` over all assets, unioned with the configured root
  folder, de-duplicated and sorted, then persisted. A fresh install therefore returns a
  usable list without an S3 round-trip, and the derivation happens once.
- **REQ-3** — `POST /folders/scan` (admin only) replaces `s3_folders` wholesale with
  `S3Service::listFolders()`. This is the authoritative refresh: prefixes that vanished
  from the bucket disappear from the list.
- **REQ-4** — `POST /folders` (admin only) creates a folder: the name is validated
  `required|string|max:100|regex:/^[a-zA-Z0-9_\-]+$/` — letters, digits, underscore and
  hyphen only, so no slashes, spaces or dots can smuggle a nested or traversing path
  through the `name`. Nesting is expressed by the separate optional `parent`
  (`nullable|string|max:255`, defaulting to the configured root folder).
- **REQ-5** — Creation is S3-first: if `S3Service::createFolder()` returns false the
  response is `500` and `s3_folders` is left untouched — the setting never advertises a
  folder that does not exist in the bucket. On success the path is appended (if absent),
  the list re-sorted, and `201` returned with the resolved path.
- **REQ-6** — Both mutating endpoints authorize with the **`discover`** ability on
  `Asset` (admin-only per [authorization-policies.md](authorization-policies.md)),
  enforced twice: route middleware `can:discover,App\Models\Asset` *and*
  `$this->authorize('discover', Asset::class)` in the action. There is no dedicated
  folder ability — folder mutation is treated as the same administrative concern as S3
  discovery, since both reshape how the bucket is presented.

## Technical design

### Contract / public interface

```yaml
routes:
  GET  /api/folders     FolderController::index   # api.php, middleware auth.multi — any role
  POST /folders/scan    FolderController::scan    # web.php, can:discover,App\Models\Asset
  POST /folders         FolderController::store   # web.php, can:discover,App\Models\Asset

controller (app/Http/Controllers/FolderController.php):
  index(): JsonResponse                           # { folders: string[] }            200
  scan(): JsonResponse                            # { folders: string[] }            200
  store(Request): JsonResponse                    # { folder: string }               201
                                                  # { message: "Failed to create folder" } 500
  getCachedFolders(): array   # protected — REQ-2 back-fill

service (app/Services/S3Service.php — see s3-storage.md):
  listFolders(?prefix): array
  createFolder(folderPath): bool
  getRootFolder(): string     # static
```

### Data shapes

```yaml
# request — POST /folders
name: string      # required, max 100, /^[a-zA-Z0-9_-]+$/
parent: string?   # nullable, max 255; defaults to S3Service::getRootFolder()

# responses
GET  /api/folders   → { folders: ["assets", "assets/marketing"] }
POST /folders/scan  → { folders: [...] }                       # replaces the setting
POST /folders       → { folder: "assets/new-stuff" }           # 201
```

The resolved path is `rtrim(parent,'/') + '/' + trim(name,'/')`, or just the trimmed name
when the parent resolves to an empty string (a bucket-root install).

### Layer touchpoints & ordering

```
GET /api/folders
  → auth.multi                → getCachedFolders()
      → Setting::get('s3_folders')     ── non-empty ⇒ return
      └─ empty ⇒ derive from Asset s3_keys, union root, sort, Setting::set  (REQ-2)

POST /folders/scan
  → can:discover → authorize('discover') → S3Service::listFolders()
                                         → Setting::set('s3_folders', …, 'json', 'aws')

POST /folders
  → can:discover → authorize('discover') → validate (REQ-4)
                 → resolve path → S3Service::createFolder()
                     ├─ false ⇒ 500, setting untouched                     (REQ-5)
                     └─ true  ⇒ append if absent, sort, Setting::set → 201
```

Ordering that matters: S3 write precedes the setting write in `store` (REQ-5), and the
`scan` write is a replace rather than a merge (REQ-3).

### Persistence

```
settings: key "s3_folders", type "json", group "aws"   # the whole feature's state
```

No table of its own, and no cache beyond `Setting`'s own 1-hour cache — writes go through
`Setting::set()`, which invalidates it. Nothing about folders is stored per user; the
per-user *home* folder lives in `users.preferences`
([user-preferences.md](user-preferences.md)).

## Scenarios (BDD)

```gherkin
Scenario: The folder list requires authentication
  Given no session and no token
  When a client GETs /api/folders
  Then the response is 401
# pinned by: tests/Feature/FolderTest.php

Scenario: Any authenticated role can read the folder list (REQ-1)
  Given s3_folders is ["assets", "assets/marketing"]
  And an api-role user with a Sanctum token
  When they GET /api/folders
  Then the response is 200 with exactly those folders
# pinned by: tests/Feature/FolderTest.php

Scenario: An admin refreshes the folder list from S3 (REQ-3)
  Given S3 reports the prefixes ["assets", "assets/new"]
  When an admin POSTs /folders/scan
  Then the response lists those prefixes
  And s3_folders is replaced with them
# pinned by: tests/Feature/FolderTest.php

Scenario: A non-admin cannot refresh the folder list (REQ-6)
  Given an editor
  When they POST /folders/scan
  Then the response is 403
# pinned by: tests/Feature/FolderTest.php

Scenario: An admin creates a nested folder (REQ-4, REQ-5)
  Given s3_folders is ["assets"]
  When an admin POSTs /folders with name "new-stuff" and parent "assets"
  Then S3Service::createFolder was called with "assets/new-stuff"
  And the response is 201 with that path
  And s3_folders now contains it
# pinned by: tests/Feature/FolderTest.php

Scenario: An invalid folder name is rejected before touching S3 (REQ-4)
  Given an admin
  When they POST /folders with name "bad name!"
  Then the response is 422
# pinned by: tests/Feature/FolderTest.php

Scenario: A non-admin cannot create a folder (REQ-6)
  Given an editor
  When they POST /folders with a valid name
  Then the response is 403
# pinned by: tests/Feature/FolderTest.php
```

## Tests & verification

- Feature: `tests/Feature/FolderTest.php` — all seven scenarios; `S3Service` is mocked
  via `$this->app->instance()` so no bucket is touched.
- Unit: `tests/Unit/S3ServiceTest.php` (`getConfiguredFolders`, `getRootFolder`) and
  `tests/Unit/Services/S3ServiceTest.php` (`listFolders` pagination) — the primitives
  behind REQ-2/REQ-3, owned by [s3-storage.md](s3-storage.md).
- Run: `php artisan config:clear && php artisan test tests/Feature/FolderTest.php`
- Style: `./vendor/bin/pint --test`
- Manual check: `POST /folders/scan` from the upload page's folder control, then confirm
  `Setting::get('s3_folders')` in `php artisan tinker` matches the bucket's prefixes.

## Open questions / future

- **No delete endpoint.** Folders can be created and re-scanned but never removed
  individually; the only way to drop one is a `scan` after the prefix disappears from
  S3. Removing a non-empty folder would need an asset-move story first — see
  `bulkMove` in [bulk-operations.md](bulk-operations.md).
- **REQ-2's back-fill is unpinned.** `FolderTest` always seeds `s3_folders`, so the
  derive-from-assets branch of `getCachedFolders()` has no test. It is reachable on a
  fresh install with assets already imported by
  [discovery-import.md](discovery-import.md).
- The `500` branch of REQ-5 (S3 refuses the write) is also unpinned.
- Only `POST /folders` is wired into the UI (`assets/create.blade.php`);
  `/folders/scan` and `GET /api/folders` are called by API clients and admin tooling. A
  scan control in the system UI would make REQ-3 discoverable.
- No E2E coverage — see [e2e-testing.md](e2e-testing.md).
