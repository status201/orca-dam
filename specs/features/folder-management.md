# Folder management

```yaml
id: folder-management
status: implemented
version: 2
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
  - app/Services/FolderRegistry.php
  - app/Services/S3Service.php
  - routes/web.php
  - routes/api.php
```

## Background / Why

S3 has no folders — only key prefixes. ORCA nonetheless offers a folder picker on upload, a
folder filter on the grid and a per-user home folder, all needing a *list* of folders.
Enumerating prefixes on every request would be slow and fail closed when S3 is unreachable, so
the list is materialized into the `s3_folders` setting. This spec covers the endpoints that read,
refresh and extend that list; the key layout and `S3Service::listFolders()` / `createFolder()`
belong to [s3-storage.md](s3-storage.md).

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
- **REQ-picker-folder-list** — Every folder picker and folder check (upload, grid filter, tools,
  Discover, export, the profile home folder, bulk-move validation) takes its list from
  `FolderRegistry::forPicker()`: the stored `s3_folders`, falling back to the root folder alone.
  With a root folder configured, a stale `''` entry is dropped and the root is always offered; on
  a bucket-root install `''` ("/ (root)") is always offered first.
- **REQ-6** — Both mutating endpoints authorize with the **`discover`** ability on `Asset`
  (admin-only per [authorization-policies.md](authorization-policies.md)), enforced twice: route
  middleware `can:discover,App\Models\Asset` *and* `$this->authorize('discover', Asset::class)`.
  There is deliberately no dedicated folder ability — folder mutation is the same administrative
  concern as S3 discovery.

## Technical design

### Contract / public interface

```yaml
routes:
  GET  /api/folders     FolderController::index   # api.php, auth.multi — any role
  POST /folders/scan    FolderController::scan    # web.php, can:discover,App\Models\Asset
  POST /folders         FolderController::store   # web.php, can:discover,App\Models\Asset

service (app/Services/FolderRegistry.php — the only reader and writer of s3_folders):
  forPicker(): array          # REQ-picker-folder-list
  cachedOrDerived(): array    # REQ-2 back-fill
  replace(array $folders)     # REQ-3
  add(string $folder)         # REQ-5: append if absent, sort
  forget()                    # when s3_root_folder changes — settings.md
```

### Data shapes

```yaml
# request — POST /folders
name: string      # required, max 100, /^[a-zA-Z0-9_-]+$/
parent: string?   # nullable, max 255; defaults to S3Service::getRootFolder()

# responses
GET  /api/folders   → { folders: ["assets", "assets/marketing"] }      # 200
POST /folders/scan  → { folders: [...] }                                # 200, replaces the setting
POST /folders       → { folder: "assets/new-stuff" }                    # 201
                    | { message: "Failed to create folder" }            # 500
```

The resolved path is `rtrim(parent,'/') + '/' + trim(name,'/')`, or just the trimmed name when
the parent is empty (a bucket-root install).

### Persistence

```
settings: key "s3_folders", type "json", group "aws"   # the whole feature's state
```

No table and no cache beyond `Setting`'s own (invalidated by `Setting::set()`). The per-user
*home* folder lives in `users.preferences` ([user-preferences.md](user-preferences.md)).

## Scenarios (BDD)

```gherkin
Scenario: Any authenticated role can read the folder list (REQ-1)
  Given s3_folders is ["assets", "assets/marketing"]
  When an api-role user with a Sanctum token GETs /api/folders
  Then the response is 200 with exactly those folders
  And without a session or token it is 401
# pinned by: tests/Feature/FolderTest.php

Scenario: An admin refreshes the folder list from S3 (REQ-3)
  Given S3 reports the prefixes ["assets", "assets/new"]
  When an admin POSTs /folders/scan
  Then the response lists those prefixes and s3_folders is replaced with them
# pinned by: tests/Feature/FolderTest.php

Scenario: An admin creates a nested folder (REQ-4, REQ-5)
  Given s3_folders is ["assets"]
  When an admin POSTs /folders with name "new-stuff" and parent "assets"
  Then S3Service::createFolder was called with "assets/new-stuff"
  And the response is 201 with that path and s3_folders now contains it
  And a name "bad name!" is a 422 before S3 is touched
# pinned by: tests/Feature/FolderTest.php

Scenario: A non-admin cannot scan or create folders (REQ-6)
  Given an editor
  When they POST /folders/scan or POST /folders with a valid name
  Then the response is 403
# pinned by: tests/Feature/FolderTest.php

Scenario: Every picker gets the normalised folder list (REQ-picker-folder-list)
  Given root folder "assets" and a stored list that holds a stale "" but not "assets"
  Then forPicker() offers "assets" first and no ""
  And on a bucket-root install it offers "" first
  And with nothing stored it offers the root folder alone
# pinned by: tests/Unit/Services/FolderRegistryTest.php
```

## Tests & verification

- Feature: `tests/Feature/FolderTest.php` (`S3Service` mocked)
- Unit: `tests/Unit/Services/FolderRegistryTest.php` (`forPicker()`, REQ-2 back-fill, `add()`,
  `forget()`); `tests/Unit/S3ServiceTest.php`, `tests/Unit/Services/S3ServiceTest.php` — the S3
  primitives ([s3-storage.md](s3-storage.md))
- Run: `php artisan config:clear && php artisan test tests/Feature/FolderTest.php`

## Open questions / future

- No delete endpoint: a folder disappears only via `scan` after its prefix is gone from S3;
  removing a non-empty folder would need an asset-move story first.
- Coverage gap: REQ-5's `500` branch (S3 refuses the write) is unpinned.
- No E2E coverage.
