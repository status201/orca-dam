<!--
  Recipe: add a queued job in app/Jobs/.
-->

# Recipe — Add a queued job

```yaml
id: add-a-queued-job
status: implemented
version: 1
owner: core
related:
  - architecture
  - ../features/queue-jobs
  - ../decisions/adr-008-sqlite-tests
source:
  - app/Jobs/
```

A repeatable **playbook**, not a feature. ORCA offloads slow/fallible work
(AWS calls, image processing) to the queue so a request never blocks on it.
Every job follows one of two shapes — ID-based re-fetch-and-no-op-if-gone, or
model-in-constructor dispatched `afterResponse()` — because the queue runs
**synchronously in tests** (`QUEUE_CONNECTION=sync`,
[ADR-008](../decisions/adr-008-sqlite-tests.md)) and via a real worker in
production, so `handle()` must behave correctly whether the row still exists
by the time it runs. The concrete worked instance is
`app/Jobs/VerifyAssetIntegrity.php`.

## Background / Why

Dispatching an ID instead of a serialized model means the job re-reads fresh
state when it actually runs (which may be much later, on a real worker) and
degrades gracefully — a silent no-op, logged — if the row was deleted in the
meantime. A job that fails mid-way should re-throw (not swallow) so Laravel's
`$tries` retry mechanism gets a chance; only a job whose failure must never
block/retry-storm the primary flow (AI tagging after upload) swallows its own
exception.

## Steps

### 1. Create the job — `app/Jobs/<Name>.php`

ID-based (the common case — re-fetches its own row, no-ops if gone):

```php
class VerifyAssetIntegrity implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;
    public $tries = 2;

    public function __construct(public int $assetId) {}

    public function handle(S3Service $s3Service): void
    {
        $asset = Asset::find($this->assetId);
        if (! $asset) {
            Log::warning("VerifyAssetIntegrity: Asset {$this->assetId} not found");
            return;   // silent no-op — the row is just gone by the time we ran
        }

        try {
            // ... do the work
        } catch (\Exception $e) {
            Log::error("VerifyAssetIntegrity: Failed for asset {$asset->id}: ".$e->getMessage());
            throw $e;   // re-throw so $tries gets a chance
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("VerifyAssetIntegrity: Job permanently failed for asset {$this->assetId}: ".$exception->getMessage());
    }
}
```

Model-in-constructor + swallow-and-never-retry (only for a job whose failure
must not block the primary flow, like AI tagging after upload):

```php
class GenerateAiTags implements ShouldQueue
{
    use Queueable;

    public function __construct(public Asset $asset) {}

    public function handle(RekognitionService $rekognitionService): void
    {
        if (! $this->asset->isImage() || $this->asset->mime_type === 'image/gif') {
            return;   // skip, not an error
        }
        try {
            $rekognitionService->autoTagAsset($this->asset);
        } catch (\Exception $e) {
            \Log::error("AI tagging failed for asset {$this->asset->id}: ".$e->getMessage());
            // swallowed entirely — no rethrow, no retry
        }
    }
}
```

### 2. Dispatch it from the call site

```php
GenerateAiTags::dispatch($asset)->afterResponse();   // doesn't delay the HTTP response
VerifyAssetIntegrity::dispatch($asset->id);            // ID-based jobs dispatch plainly
```

### 3. Unit-test `handle()` directly (sync queue means this exercises real behavior)

```php
test('job sets s3_missing_at when object is missing', function () {
    $asset = Asset::factory()->image()->create(['s3_missing_at' => null]);

    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldReceive('getObjectMetadata')->once()->with($asset->s3_key)->andReturn(null);

    (new VerifyAssetIntegrity($asset->id))->handle($s3);

    expect($asset->fresh()->s3_missing_at)->not->toBeNull();
});
```

```bash
./vendor/bin/pint
php artisan config:clear && php artisan test tests/Unit/Jobs/
```

## Gotchas

- Pick `$tries`/`$timeout` deliberately: a flaky-but-cheap job gets more
  retries (`tries=3`); a job whose failure means "don't retry, it'll just
  fail again" gets `tries=1` (see `RunTestSuiteJob`, which also needs a long
  `$timeout=900` since it runs the whole Pest suite).
- Don't serialize a full `Asset` model into a job that might sit in a real
  queue for a while — dispatch the ID and re-fetch in `handle()`, so a since-
  deleted row degrades to a no-op instead of operating on stale data.
  `GenerateAiTags` is the deliberate exception (dispatched `afterResponse()`,
  runs essentially immediately).
- A no-op-if-missing branch must **not** throw — log a warning and `return`,
  since "the row is gone" is an expected race, not a failure to retry.
- Because tests run the queue synchronously, a job that would only fail under
  real async timing (e.g. a race with the dispatching request's transaction
  not yet committed) won't be caught by the test suite — be deliberate about
  transaction boundaries around `dispatch()` calls.

## Scenarios (BDD)

```gherkin
Scenario: VerifyAssetIntegrity marks a missing S3 object
  Given an asset whose S3 object no longer exists
  When VerifyAssetIntegrity::handle() runs
  Then s3_missing_at is set and the missing-count cache is invalidated
# pinned by: tests/Unit/Jobs/VerifyAssetIntegrityTest.php
```

## Tests & verification

- `tests/Unit/Jobs/VerifyAssetIntegrityTest.php`,
  `GenerateAiTagsTest.php`, `ProcessDiscoveredAssetTest.php`,
  `RegenerateResizedImageTest.php`, `RunTestSuiteJobTest.php` — each mocks its
  service dependency and calls `handle()` directly.
- `php artisan config:clear && php artisan test tests/Unit/Jobs/`.
