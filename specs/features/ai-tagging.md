# AI Tagging (AWS Rekognition)

```yaml
id: ai-tagging
status: implemented
version: 1
owner: core
related:
  - architecture
  - tags
source:
  - app/Services/RekognitionService.php
  - app/Jobs/GenerateAiTags.php
  - app/Services/AssetProcessingService.php
  - app/Http/Controllers/AssetReplaceController.php
```

## Background / Why

ORCA can auto-tag uploaded images using AWS Rekognition's label detection, so
editors don't have to hand-tag every asset. Because Rekognition is a paid,
latency-bearing external call, tagging runs in the background (a queued job)
after upload rather than blocking the response, and every layer is built to
degrade gracefully: disabled by config, non-image/GIF assets are skipped
up front, and any AWS failure is caught, logged, and swallowed rather than
surfacing to the uploader. Labels are optionally translated (AWS Translate) so
the DAM's tag vocabulary matches the app's configured locale instead of always
being English.

## Requirements

- **REQ-1** — Rekognition is fully config-gated: `services.aws.rekognition_enabled`
  must be true, or every `RekognitionService` method is a no-op that returns
  `[]` without constructing an AWS client.
- **REQ-2** — Tuning knobs (`rekognition_max_labels`, `rekognition_min_confidence`,
  `rekognition_language`) are read **live from the `Setting` model** on every
  call (falling back to `config('services.aws.*')`), not cached/resolved once
  at construction — an admin can change them without restarting workers.
- **REQ-3** — When the target language is not `en`, every detected label name
  is translated via AWS Translate before being lowercased into a tag name; when
  the target language is `en`, no `TranslateClient` is even constructed.
- **REQ-4** — `autoTagAsset()` only processes image assets (`Asset::isImage()`);
  non-images return `[]` and attach nothing.
- **REQ-5** — `GenerateAiTags` (the queued job) additionally skips
  `image/gif` — Rekognition does not support GIF — even though `isImage()`
  itself would return true for a (non-animated) GIF.
- **REQ-6** — AI-detected tags are attached with `asset_tag.attached_by = 'ai'`
  via `Asset::syncTagsWithAttribution()` (see [`tags.md`](tags.md)), and never
  detach/replace a user's existing tags — only additive `syncWithoutDetaching`.
- **REQ-7** — Any exception from the Rekognition/Translate SDK calls is caught,
  logged, and converted to an empty result — the job and the manual
  "Generate AI tags" controller action must never fail the request/queue
  attempt because AWS is unreachable.
- **REQ-8** — Tagging can be triggered two ways: automatically after upload
  (background, `afterResponse()` dispatch) and manually per-asset via
  `POST /assets/{asset}/ai-tag` (throttled `30,1`).

## Technical design

### Contract / public interface

```yaml
App\Services\RekognitionService:
  isEnabled(): bool
  detectLabels(string $s3Key, ?float $minConfidence = null): array<{name, confidence}>
    # [] if disabled; catches AWS exceptions -> logs -> []
  detectText(string $s3Key): array<string>       # LINE-type detections with confidence > 80
  autoTagAsset(Asset $asset): array<{name, confidence}>
    # [] if !$asset->isImage(); else detectLabels() -> Tag::firstOrNew(type=ai) per label
    # -> Asset::syncTagsWithAttribution($tagIds, 'ai')

App\Jobs\GenerateAiTags implements ShouldQueue:
  __construct(public Asset $asset)
  handle(RekognitionService $rekognitionService): void
    # returns early if !isImage() or mime_type === 'image/gif'
    # try { autoTagAsset() } catch (\Exception $e) { Log::error(...) }  -- never rethrows

App\Services\AssetProcessingService::processImageAsset(Asset $asset, bool $dispatchAiTagging = true): void
    # after thumbnail + resize generation: if ($dispatchAiTagging && $rekognitionService->isEnabled())
    #   GenerateAiTags::dispatch($asset)->afterResponse()

App\Http\Controllers\AssetReplaceController::generateAiTags(Asset $asset)
    # POST /assets/{asset}/ai-tag  (route: assets.ai-tag, throttle:30,1)
    # authorize('update', $asset); 302 + flash 'error' if !isImage() or !rekognitionService->isEnabled()
    # calls autoTagAsset() SYNCHRONOUSLY (not queued) and redirects with a success/warning/error flash
```

`GenerateAiTags` and the manual `generateAiTags` controller action both end up
calling `RekognitionService::autoTagAsset()`, but through different paths: the
job runs on the queue (async, dispatched from `AssetProcessingService` after
every image upload/replace when Rekognition is enabled), while the controller
action runs inline within the request (synchronous "regenerate tags now" button
on the asset edit page) and surfaces a translated flash message instead of
silently logging.

### Data shapes

```yaml
detectLabels() result item:
  name: string        # lowercased, translated if target language != en
  confidence: float    # 0-100, AWS-reported confidence

Settings consulted (Setting::get, live per call — see settings.md-to-be):
  rekognition_max_labels: integer      # default 3
  rekognition_min_confidence: integer  # default 80 (valid range 65-99)
  rekognition_language: string         # default 'nl'
```

### Layer touchpoints & ordering

```
Upload/replace succeeds
  -> AssetProcessingService::processImageAsset()
       -> (thumbnail + resize generation, unrelated to this spec)
       -> if Rekognition enabled: GenerateAiTags::dispatch($asset)->afterResponse()
            (queue: sync in tests per ADR-008; real queue worker in prod)
  -> GenerateAiTags::handle()
       -> guards: isImage() / not GIF
       -> RekognitionService::autoTagAsset($asset)
            -> RekognitionService::detectLabels($asset->s3_key)
                 -> AWS Rekognition detectLabels()  (S3Object reference, no local file read)
                 -> per label: if target language != 'en', AWS Translate::translateText()
            -> Tag::firstOrNew(['name' => lowercased label]); set type='ai' only if new
            -> Asset::syncTagsWithAttribution($tagIds, 'ai')   (tags.md)

Manual re-tag:
  POST /assets/{asset}/ai-tag -> AssetReplaceController::generateAiTags()
    -> authorize('update', $asset) -> same autoTagAsset() call, synchronously, with a flash redirect
```

### Persistence

- No dedicated table — results land as `Tag` rows (`type = 'ai'` for newly
  created tags only; an existing tag of any other type is reused unchanged,
  per `Tag::resolveTagIds`'s "don't change existing type" rule) and
  `asset_tag` pivot rows with `attached_by = 'ai'`.
- Nothing about the Rekognition/Translate call itself is persisted (no raw AWS
  response caching) — a re-run always calls AWS fresh.

## Visual aids

```yaml
aws_sdk: aws/aws-sdk-php  # ^3.379 — Rekognition::detectLabels/detectText, Translate::translateText
```

## Scenarios (BDD)

```gherkin
Scenario: Rekognition is disabled by config
  Given services.aws.rekognition_enabled is false
  When RekognitionService::isEnabled() is checked
  Then it returns false
# pinned by: tests/Unit/Services/RekognitionServiceTest.php

Scenario: detectLabels/detectText return empty arrays when disabled, without calling AWS
  Given Rekognition is disabled
  When detectLabels() or detectText() is called
  Then both return []
# pinned by: tests/Unit/Services/RekognitionServiceTest.php

Scenario: autoTagAsset skips non-image assets
  Given a PDF asset and Rekognition disabled
  When autoTagAsset($asset) is called
  Then it returns [] and no tags are attached
# pinned by: tests/Unit/Services/RekognitionServiceTest.php

Scenario: The queued job calls autoTagAsset for image assets
  Given an image asset with mime_type image/jpeg
  When GenerateAiTags::handle() runs
  Then RekognitionService::autoTagAsset() is called exactly once with that asset
# pinned by: tests/Unit/Jobs/GenerateAiTagsTest.php

Scenario: The queued job skips non-image assets
  Given a PDF asset
  When GenerateAiTags::handle() runs
  Then RekognitionService::autoTagAsset() is never called
# pinned by: tests/Unit/Jobs/GenerateAiTagsTest.php

Scenario: The queued job skips GIF images (Rekognition does not support GIF)
  Given an asset with mime_type image/gif
  When GenerateAiTags::handle() runs
  Then RekognitionService::autoTagAsset() is never called
# pinned by: tests/Unit/Jobs/GenerateAiTagsTest.php

Scenario: The queued job swallows a Rekognition exception instead of failing the job
  Given autoTagAsset() throws an exception
  When GenerateAiTags::handle() runs
  Then the job completes without raising
# pinned by: tests/Unit/Jobs/GenerateAiTagsTest.php

Scenario: Manual AI tagging is rate-limited
  Given the assets.ai-tag route
  Then it carries a throttle middleware
# pinned by: tests/Feature/SecurityRemediationTest.php
```

## Tests & verification

- Unit: `tests/Unit/Services/RekognitionServiceTest.php` — `isEnabled`,
  `detectLabels`/`detectText` disabled-path short-circuits, `autoTagAsset`
  non-image guard.
- Unit: `tests/Unit/Jobs/GenerateAiTagsTest.php` — job guards (image/GIF),
  exception swallowing, delegation to `RekognitionService::autoTagAsset`
  (mocked).
- Feature: `tests/Feature/SecurityRemediationTest.php` — confirms
  `assets.ai-tag` is throttled.
- Run: `php artisan config:clear && php artisan test tests/Unit/Services/RekognitionServiceTest.php tests/Unit/Jobs/GenerateAiTagsTest.php`

## Open questions / future

- No test in the suite exercises `RekognitionService::detectLabels()`'s
  *enabled* path against a mocked `RekognitionClient` (label translation,
  confidence filtering, the `strtolower()` normalization, or the
  `Log::info` call per detected label) — coverage stops at the disabled
  short-circuit and the job-level integration via a mocked
  `RekognitionService`. Same gap for `detectText()`'s enabled path and for
  `translateText()`/`getTranslateClient()`'s language-switch behaviour. This is
  a coverage gap, not a documented behaviour — flagged here rather than
  pinned to a nonexistent test.
- `AssetReplaceController::generateAiTags` (the manual re-tag button) has no
  dedicated Feature test found in `tests/Feature/` beyond the throttle
  assertion in `SecurityRemediationTest.php` — its authorization check, the
  "not an image" / "Rekognition not enabled" flash-redirect branches, and the
  success/warning/error message selection are otherwise unpinned.
