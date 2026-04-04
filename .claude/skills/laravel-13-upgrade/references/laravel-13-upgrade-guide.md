# Laravel 12 → 13 Upgrade Reference Guide

Authoritative source: https://laravel.com/docs/13.x/upgrade

Laravel 13 is a lightweight release focused on security hardening, developer-experience improvements, and contract updates. The sections below are ordered by impact — read in order the first time, then jump to specific sections as needed.

---

## High-impact changes

### 1. Dependency version constraints

Update `composer.json`:

```json
{
    "require": {
        "laravel/framework": "^13.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^12.0",
        "pestphp/pest": "^4.0"
    }
}
```

Then run:

```bash
composer update
```

### 2. Request Forgery Protection — CSRF middleware renamed

Laravel's CSRF middleware has been renamed from `VerifyCsrfToken` / `ValidateCsrfToken` to `PreventRequestForgery`. The new middleware also adds request-origin verification via the `Sec-Fetch-Site` header.

The old class names are kept as **deprecated aliases** and will not immediately break, but all direct references should be updated.

**Before (Laravel 12):**
```php
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

->withoutMiddleware([VerifyCsrfToken::class]);
```

**After (Laravel 13):**
```php
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

->withoutMiddleware([PreventRequestForgery::class]);
```

The middleware configuration API now also exposes `preventRequestForgery(...)`.

**Where to search in ORCA:**
- Middleware exclusions on routes (`->withoutMiddleware(...)`)
- `bootstrap/app.php` or `Http/Kernel.php` middleware stacks
- Test helpers that reference CSRF middleware by class name
- Any service provider referencing the old class

---

## Medium-impact changes

### 3. Cache `serializable_classes` configuration

The default cache configuration now includes a `serializable_classes` option set to `false`. This hardens cache unserialization to prevent PHP deserialization gadget-chain attacks if `APP_KEY` is ever leaked.

**If ORCA stores PHP objects in cache**, you must explicitly allowlist the classes that may be unserialized:

```php
// config/cache.php
'serializable_classes' => [
    App\Data\SomeModel::class,
    App\Support\SomeCachedValue::class,
],
```

If ORCA only caches scalars, arrays, and JSON-safe data, set it to `false` (the secure default) and move on:

```php
// config/cache.php
'serializable_classes' => false,
```

If the key is entirely absent from `config/cache.php`, add it. Laravel will not add it automatically.

---

## Low-impact changes

### 4. Cache prefixes and session cookie names

Default key generation has changed:

| | Laravel 12 | Laravel 13 |
|---|---|---|
| Cache prefix | `Str::slug(APP_NAME, '_').'_cache_'` | `Str::slug(APP_NAME).'-cache-'` |
| Redis prefix | `Str::slug(APP_NAME, '_').'_database_'` | `Str::slug(APP_NAME).'-database-'` |
| Session cookie | `Str::slug(APP_NAME, '_').'_session'` | `Str::snake(APP_NAME).'_session'` |

**Impact:** If ORCA has live cached data that must survive the upgrade, or if session cookies must remain valid across a rolling deploy, pin these explicitly in `.env`:

```
CACHE_PREFIX=orca_cache_
REDIS_PREFIX=orca_database_
SESSION_COOKIE=orca_session
```

Otherwise, existing sessions will be invalidated on deploy (users get logged out), and the cache will be a cold start. For ORCA this is probably acceptable — but check with the project owner.

### 5. `Container::call` and nullable class defaults

`Container::call` now returns `null` for nullable class parameters when no binding exists, matching constructor injection behaviour introduced in Laravel 12.

**Before (Laravel 12):**
```php
$container->call(function (?Carbon $date = null) {
    return $date;
});
// Returns: Carbon instance
```

**After (Laravel 13):**
```php
$container->call(function (?Carbon $date = null) {
    return $date;
});
// Returns: null
```

Only relevant if ORCA calls `Container::call` (or `app()->call()`) with nullable typed parameters and expects to receive an instance.

### 6. Domain route registration precedence

Routes with an explicit domain are now matched before non-domain routes, regardless of registration order. This is a fix for catch-all subdomain routes — unlikely to affect ORCA unless it uses subdomain routing.

### 7. `JobAttempted` event — exception payload

**Before (Laravel 12):**
```php
$event->exceptionOccurred; // bool
```

**After (Laravel 13):**
```php
$event->exception; // Throwable|null
```

Search for any listeners on `JobAttempted` that read `exceptionOccurred` and update them.

### 8. `QueueBusy` event — property rename

**Before (Laravel 12):**
```php
$event->connection;
```

**After (Laravel 13):**
```php
$event->connectionName;
```

### 9. Manager `extend` callback binding

Custom driver closures registered via `extend()` are now bound to the manager instance. If you used `$this` inside these closures expecting it to be the service provider, capture the dependency explicitly.

**Before (Laravel 12):**
```php
// $this was bound to the service provider
$manager->extend('custom', function () {
    return $this->buildCustomDriver();
});
```

**After (Laravel 13):**
```php
// $this is now the manager; capture dependencies in the closure
$provider = $this;
$manager->extend('custom', function () use ($provider) {
    return $provider->buildCustomDriver();
});
```

### 10. MySQL `DELETE` queries with `JOIN`, `ORDER BY`, and `LIMIT`

Laravel now compiles full `DELETE ... JOIN` queries including `ORDER BY` and `LIMIT` for MySQL grammar. Previously these clauses could be silently ignored on joined deletes. If ORCA uses joined deletes with these clauses, test them carefully — they will now execute as written rather than being partially ignored.

### 11. Pagination Bootstrap view names

If ORCA references pagination views by name directly:

| Laravel 12 | Laravel 13 |
|---|---|
| `'pagination::default'` | `'pagination::bootstrap-3'` |
| `'pagination::simple-default'` | `'pagination::simple-bootstrap-3'` |

### 12. Polymorphic pivot table name generation

When table names are inferred for polymorphic pivot models using custom pivot model classes, Laravel now generates **pluralized** names. If ORCA depends on the previous singular inferred names, explicitly define `$table` on the pivot model.

### 13. Collection model serialization restores eager-loaded relations

When Eloquent model collections are serialized (e.g. in queued jobs) and then restored, eager-loaded relations are now restored for the collection's models. If any ORCA code expected relations to be absent after deserialization, update that logic.

### 14. `Str` factories reset between tests

Laravel now resets custom `Str` factories (UUID, ULID, random string) during test teardown. If ORCA tests depend on a custom factory persisting across test methods, set it in each relevant test or in a `setUp` hook.

### 15. Model booting and nested instantiation

Creating a new model instance while that model is still booting now throws a `LogicException`:

```php
protected static function boot()
{
    parent::boot();

    // Not allowed — throws LogicException in Laravel 13
    (new static())->getTable();
}
```

Move any such logic outside the boot cycle.

---

## Very-low-impact contract changes

Only relevant if ORCA has custom implementations of these framework contracts:

| Contract | Change |
|---|---|
| `Cache\Store` and `Cache\Repository` | New `touch` method for extending item TTLs |
| `Bus\Dispatcher` | New `dispatchAfterResponse($command, $handler = null)` method |
| `Routing\ResponseFactory` | New `eventStream` method signature |
| `Auth\MustVerifyEmail` | New `markEmailAsUnverified()` method |
| `Queue\Queue` | New `pendingSize`, `delayedSize`, `reservedSize`, `creationTimeOfOldestPendingJob` methods |

Also note:

- **HTTP Client `throw` / `throwIf`:** Callback parameters are now declared in method signatures. Custom response class overrides must be compatible.
- **Password reset subject:** Default changed from "Reset Password Notification" to "Reset your password". Update translation overrides or test expectations if needed.
- **Queued notifications:** Now respect `#[DeleteWhenMissingModels]` on the notification class.
- **`withScheduling` registration timing:** Schedules via `ApplicationBuilder::withScheduling()` are now deferred until `Schedule` resolves.
- **`Js::from` uses `JSON_UNESCAPED_UNICODE`:** Update test expectations that relied on escaped Unicode sequences.

---

## Official resources

- Upgrade guide: https://laravel.com/docs/13.x/upgrade
- Laravel skeleton diff (12.x → 13.x): https://github.com/laravel/laravel/compare/12.x...13.x
