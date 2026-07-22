<!--
  Recipe: add a new service in app/Services/.
-->

# Recipe — Add a service in `app/Services/`

```yaml
id: add-a-service
status: implemented
version: 1
owner: core
related:
  - architecture
  - ../features/s3-storage
  - ../features/cloudflare-purge
  - ../decisions/adr-001-service-layer
  - ../decisions/adr-010-services-swallow-controllers-map
source:
  - app/Services/CloudflareService.php
  - config/cloudflare.php
```

A repeatable **playbook**, not a feature. ORCA puts every non-trivial piece of
work — S3, AWS Rekognition, Cloudflare, TeX Live — behind a service class so
controllers stay thin and the logic is unit-testable without an HTTP request
(see [ADR-001](../decisions/adr-001-service-layer.md)). The concrete worked
instance is [`cloudflare-purge`](../features/cloudflare-purge.md)
(`app/Services/CloudflareService.php`).

## Background / Why

A service in ORCA has three fixed characteristics: it reads its own
configuration in the constructor (env via `config()`, runtime toggles via
`Setting::get()`), it swallows and logs its own failures rather than letting
them cross into the controller (see
[ADR-010](../decisions/adr-010-services-swallow-controllers-map.md)), and it
is constructed via the container (plain `__construct` type-hint, no facade
coupling) so a controller or job can just type-hint it. Following this buys
you: the controller/job stays a thin "validate → delegate → map" layer, and
the service is testable with `new Service()` plus config/Setting fakes — no
HTTP layer needed.

## Steps

### 1. Define the service — `app/Services/<Name>Service.php`

Constructor reads config/settings; public methods are the contract; failures
are caught, logged, and return a benign value (`null`/`false`/`[]`) — never
thrown across the service boundary for a fallible-external failure (a genuine
domain condition that needs typed control flow, like a duplicate upload, still
gets its own exception class in `app/Exceptions/` — that's a different case
from an external-service failure):

```php
class CloudflareService
{
    protected bool $enabled;
    protected string $apiToken;
    protected string $zoneId;

    public function __construct()
    {
        $this->enabled = (bool) config('cloudflare.enabled', false);
        $this->apiToken = (string) config('cloudflare.api_token', '');
        $this->zoneId = (string) config('cloudflare.zone_id', '');
    }

    public function isEnabled(): bool
    {
        return $this->enabled
            && $this->apiToken !== ''
            && $this->zoneId !== ''
            && Setting::get('custom_domain', '') !== ''
            && (bool) Setting::get('cloudflare_cache_purge', false);
    }

    public function purgeUrls(array $urls): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        try {
            $response = Http::withToken($this->apiToken)->timeout(10)->post(/* ... */);
            // ... success => true
        } catch (\Exception $e) {
            Log::error('Cloudflare cache purge failed: '.$e->getMessage());
            return false;
        }
    }
}
```

If the service needs deploy-fixed wiring (API keys, endpoints), add a
`config/<name>.php` file (see `config/cloudflare.php`) rather than reading
`env()` directly in the service — config files are cacheable and the single
place deploy-time wiring is declared.

### 2. Wire it into callers — controller/job constructor

Type-hint the service in the consuming controller or job constructor; Laravel's
container resolves it, no service-provider binding needed for a plain class:

```php
public function __construct(
    protected S3Service $s3Service,
    protected CloudflareService $cloudflareService,
) {}
```

A service that itself needs runtime settings should read them **live** via
`Setting::get()` at call time (not injected/cached at construction), matching
[ADR-011](../decisions/adr-011-settings-in-db.md) — an admin toggling
`cloudflare_cache_purge` must take effect on the very next request.

### 3. Unit-test it directly (no HTTP layer)

```php
test('isEnabled returns false when disabled in config', function () {
    config()->set('cloudflare.enabled', false);
    config()->set('cloudflare.api_token', 'token');
    config()->set('cloudflare.zone_id', 'zone123');
    Setting::set('custom_domain', 'https://cdn.example.com');
    Setting::set('cloudflare_cache_purge', '1', 'boolean', 'aws');

    $service = new CloudflareService;

    expect($service->isEnabled())->toBeFalse();
});
```

```bash
./vendor/bin/pint
php artisan config:clear && php artisan test tests/Unit/CloudflareServiceTest.php
```

## Gotchas

- Don't inject `Setting` values into the constructor — read them live inside
  each method call. A service constructed once per request (or cached across
  a batch loop) that snapshots a setting at construction time will miss a
  same-request `Setting::set()` write.
- Log *before* returning the benign value, not after — a caller that ignores
  the `null`/`false` return still needs the log line to be the record that
  something failed (ADR-010's trade-off: the log is the only trace).
- A service that talks to an external API should never throw from a method
  whose failure is non-fatal to the caller's primary operation (e.g. a CDN
  purge failing must not fail the asset replace it's cleaning up after) —
  swallow at the service boundary, not in the caller.
- If the service needs both env wiring and a runtime toggle (like Cloudflare's
  four-way `isEnabled()` gate), put all four checks in one `isEnabled()`/
  guard method rather than scattering `config()`/`Setting::get()` checks
  across call sites.

## Tests & verification

- `tests/Unit/CloudflareServiceTest.php` — direct unit tests of a service with
  no controller/HTTP involved (config fakes + `Setting::set()` + a plain
  `new CloudflareService`).
- `./vendor/bin/pint --test` / `php artisan config:clear && php artisan test`.
