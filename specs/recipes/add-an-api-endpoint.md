<!--
  Recipe: add a REST endpoint under /api.
-->

# Recipe — Add a REST API endpoint

```yaml
id: add-an-api-endpoint
status: implemented
version: 1
owner: core
related:
  - architecture
  - ../features/rest-api
  - ../features/authorization-policies
  - ../decisions/adr-002-explicit-policy-roles
  - ../decisions/adr-010-services-swallow-controllers-map
source:
  - routes/api.php
  - app/Http/Controllers/Api/AssetApiController.php
  - app/Policies/AssetPolicy.php
  - app/Http/Controllers/Controller.php
```

A repeatable **playbook**, not a feature. Every `/api/*` route follows the
same four-part shape — route → controller action → policy check (where the
ability differs by role) → role-aware JSON response — because the API is
consumed by untrusted/semi-trusted external callers (RTE, WordPress) behind
three different roles. The concrete worked instance is
[`rest-api`](../features/rest-api.md) (`AssetApiController::destroy`).

## Background / Why

`routes/api.php` sits behind `auth.multi:sanctum,jwt` + `throttle`, so every
route on it is reachable by all three roles by default. Most abilities
(`view`, `create`, `update`) are intentionally open to `admin`/`editor`/`api`
alike — the exception is the minority that must be **role-gated** (like
`delete`), which is where a controller action calls `$this->authorize()`
explicitly rather than relying on the route being reachable at all. Following
this pattern means a new endpoint's authorization is auditable in one place
(the policy) instead of scattered `if ($user->role === ...)` checks in the
controller.

## Steps

### 1. Add the route — `routes/api.php`

Public/unauthenticated routes go in the `throttle:60,1` group at the top;
everything else goes in the `auth.multi` + `throttle:120,1` group:

```php
Route::middleware(['auth.multi', 'throttle:120,1'])->group(function () {
    Route::get('assets', [AssetApiController::class, 'index']);
    Route::delete('assets/{asset}', [AssetApiController::class, 'destroy']);
    // new: Route::post('assets/{asset}/whatever', [AssetApiController::class, 'whatever']);
});
```

### 2. Add the controller action — `app/Http/Controllers/Api/AssetApiController.php`

Validate (inline `$request->validate()` for simple shapes, a Form Request for
anything reused elsewhere — see `StoreAssetRequest`/`UpdateAssetRequest`),
authorize only if this ability is **not** already open to all three roles,
delegate the real work to a service, return JSON:

```php
public function destroy(Asset $asset)
{
    $this->authorize('delete', $asset);   // policy gate — see step 3

    $asset->delete();

    return response()->json(['message' => 'Asset moved to trash successfully']);
}
```

For an ability every role already has (`view`/`create`/`update`), no explicit
`authorize()` call is needed — the route being reachable at all under
`auth.multi` *is* the check, matching the existing `index`/`show`/`update`
actions.

### 3. Gate it in the policy if the ability isn't uniform — `app/Policies/AssetPolicy.php`

Only needed when the new ability's allowed roles differ from "all three known
roles." See [`add-a-policy-ability`](add-a-policy-ability.md) for adding the
ability itself; this step is just the controller-side wiring
(`$this->authorize('<ability>', $model)`).

### 4. Role-aware error responses — `app/Http/Controllers/Controller.php`

For failure paths that surface service-layer errors, use
`Controller::clientError()` so an `api`-role caller gets a generic message and
admins/editors see the real exception detail (ADR-010):

```php
return $this->clientError($exception, 'Something went wrong uploading the file.');
```

### 5. Verify

```bash
./vendor/bin/pint
php artisan config:clear && php artisan test tests/Feature/ApiTest.php
```

## Gotchas

- Chunked upload endpoints deliberately live in `routes/web.php`, not
  `routes/api.php`, because they run under `auth.multi:web,sanctum,jwt` (the
  web uploader is the primary caller) — don't assume every upload-shaped route
  belongs in `api.php`.
- The two genuinely public routes (`assets/meta`, `health`) sit in their own
  `throttle:60,1` group at the top of the file, tighter than the authenticated
  group's `throttle:120,1` — a new public endpoint should default to the
  tighter limit to curb enumeration/probing.
- A gated setting toggle (like `api_upload_enabled`) should be checked
  **first**, before touching S3/the DB, and return its own 403 — see
  `AssetApiController::store`'s `Setting::get('api_upload_enabled', true)`
  check at the top of the method.
- `index`/`show`/`update`/`store` don't call `$this->authorize()` at all —
  don't add one reflexively for a uniformly-open ability; it's dead code that
  will always pass and just adds noise to the controller.
- Match the existing list/search filter vocabulary (`search`/`q`, `tags`,
  `type`, `folder`, `sort`, `per_page` capped at 100) rather than inventing a
  new one for a new list-shaped endpoint — see `Asset::scopeApplySort` for the
  canonical sort values.

## Scenarios (BDD)

```gherkin
Scenario: An api-role token cannot delete an asset
  Given an authenticated user with role "api" who owns an asset
  When they send DELETE /api/assets/{id}
  Then the response status is 403
  And the asset is not soft-deleted
# pinned by: tests/Feature/ApiTest.php
```

## Tests & verification

- `tests/Feature/ApiTest.php` — the full existing endpoint contract
  (index/show/update/destroy/search/meta/health), a template for a new
  endpoint's happy-path + auth + role-gate tests.
- `./vendor/bin/pint --test` / `php artisan config:clear && php artisan test`.
