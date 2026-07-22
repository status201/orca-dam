<!--
  Recipe: Pest test conventions.
-->

# Recipe — Write a Pest test

```yaml
id: write-a-test
status: implemented
version: 1
owner: core
related:
  - architecture
  - ../decisions/adr-008-sqlite-tests
source:
  - tests/Pest.php
  - database/factories/
```

A repeatable **playbook**, not a feature. Every test in the ~957-test suite
runs against in-memory SQLite with `RefreshDatabase` and the sync queue (see
[ADR-008](../decisions/adr-008-sqlite-tests.md)), configured once in
`tests/Pest.php` (`pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Unit')`)
so every test file in either directory gets a fresh DB automatically — no
per-file boilerplate needed.

## Background / Why

The mandatory `php artisan config:clear` before `php artisan test` exists
because a stale `bootstrap/cache/config.php` can point `RefreshDatabase` at
the **dev MariaDB database** instead of the in-memory SQLite configured for
testing — and `RefreshDatabase` truncates whatever it's pointed at. This one
command is the difference between a fast hermetic test run and wiping a real
database; a PreToolUse hook enforces it, but it's worth internalizing why.

## Steps

### 1. Always clear config first

```bash
php artisan config:clear && php artisan test
```

Never run `php artisan test` on its own from memory/habit — the guard exists
because this has real, destructive failure modes, not because it's a style
preference.

### 2. Pick Feature vs. Unit

- **`tests/Feature/`** — anything touching HTTP (`$this->get()`/`post()`/
  `actingAs()`), a full controller action, middleware, or console commands
  (`tests/Feature/Console/`).
- **`tests/Unit/`** — a service/job/policy/model method called directly, no
  HTTP layer (`(new VerifyAssetIntegrity($id))->handle($mock)`,
  `(new AssetPolicy())->move($user)`).

### 3. Build state with factories, not raw `Model::create()`

```php
$admin = User::factory()->admin()->create();
$editor = User::factory()->editor()->create();
$apiUser = User::factory()->apiUser()->create();

$image = Asset::factory()->image()->create();
$pdf = Asset::factory()->pdf()->create();
$licensed = Asset::factory()->withLicense('cc_by')->create();
```

`UserFactory` defaults to `role => 'editor'`; use `admin()`/`editor()`/
`apiUser()` to opt into a specific role rather than setting `role` inline —
it reads clearer at the call site and matches every existing test.

### 4. Mock external services, never the DB

```php
$mock = Mockery::mock(S3Service::class);
$mock->shouldReceive('getObjectMetadata')->once()->with($asset->s3_key)->andReturn(null);
$this->app->instance(S3Service::class, $mock);
```

S3, Rekognition, and Cloudflare calls are always mocked — `RefreshDatabase`
gives a real (if in-memory) database, so there's no need to mock Eloquent
itself.

### 5. Write the test with Pest's function style

```php
test('an api-role token cannot delete an asset', function () {
    $apiUser = User::factory()->apiUser()->create();
    $asset = Asset::factory()->create(['user_id' => $apiUser->id]);

    $this->actingAs($apiUser)
        ->deleteJson("/api/assets/{$asset->id}")
        ->assertStatus(403);

    expect($asset->fresh()->trashed())->toBeFalse();
});
```

### 6. Verify

```bash
./vendor/bin/pint
php artisan config:clear && php artisan test tests/Feature/YourNewTest.php
php artisan config:clear && php artisan test   # full suite before considering it done
```

## Gotchas

- SQLite ≠ MariaDB — a small class of dialect differences (JSON functions,
  strictness, collations) can pass in tests yet differ in production. A
  migration/query using anything beyond a plain column type or standard SQL
  is worth a manual MariaDB check, not just a green Pest run.
- The queue runs `sync` in tests — a job's `handle()` runs inline when you
  `dispatch()` it in a Feature test, so assertions can check the *result* of
  the job immediately rather than needing `Queue::fake()` + `assertPushed()`
  (use `Queue::fake()` only when you specifically want to assert *dispatch*
  happened without executing the job body).
- `$this->app->instance(Service::class, $mock)` only works if the consuming
  code type-hints the service for container resolution — a service
  instantiated with `new Service()` inside a controller/job bypasses the
  container and can't be swapped this way (see
  [`add-a-service`](add-a-service.md)/[`add-a-queued-job`](add-a-queued-job.md)).
- Don't hand-roll a user/asset with raw arrays when a factory state already
  exists (`image()`, `pdf()`, `withLicense()`, `withCopyright()`, `admin()`,
  `editor()`, `apiUser()`, `unverified()`) — a new recurring shape belongs as
  a new factory state, not copy-pasted attribute arrays across test files.
- A behaviour with no test is a **finding**, not silently acceptable — if
  you're writing a spec alongside the code (per `specs/README.md`'s SDD
  flow), write the Gherkin scenario and list the gap under "Open questions /
  future" rather than fabricating a `# pinned by:` path that doesn't exist.

## Tests & verification

- `tests/Pest.php` — the one-time suite configuration (`RefreshDatabase`,
  `Feature`/`Unit` directories) every test file inherits.
- `database/factories/` — `UserFactory` (`admin()`/`editor()`/`apiUser()`/
  `unverified()`), `AssetFactory` (`image()`/`pdf()`/`withLicense()`/
  `withCopyright()`), `TagFactory` (`ai()`/`user()`/`reference()`),
  `SettingFactory` (`integer()`/`boolean()`).
- `php artisan config:clear && php artisan test` — always, every time.
