# AI Tagging (AWS Rekognition)

```yaml
id: ai-tagging
status: implemented
version: 4
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

ORCA can auto-tag uploaded images with AWS Rekognition label detection so editors don't hand-tag
every asset. Rekognition is a paid, latency-bearing external call, so tagging runs as a queued
job after upload, and every layer degrades gracefully: disabled by config, non-image/GIF assets
skipped up front, AWS failures logged and swallowed. Labels are optionally translated (AWS
Translate) so tags match the configured language.

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
  `POST /assets/{asset}/ai-tag` (named limiter `ai-tag` — see
  [`upload-policy.md`](upload-policy.md) REQ-7).

## Technical design

### Contract / public interface

```yaml
App\Services\RekognitionService:
  isEnabled(): bool
  detectLabels(string $s3Key, ?float $minConfidence = null): array<{name, confidence}>
    # [] if disabled; AWS exceptions -> log -> []
  detectText(string $s3Key): array<string>       # LINE-type detections with confidence > 80
  autoTagAsset(Asset $asset): array<{name, confidence}>
    # [] if !isImage(); else detectLabels() -> Tag::resolveAiTagIds(names) -> syncTagsWithAttribution($ids, 'ai')

App\Jobs\GenerateAiTags implements ShouldQueue:
  __construct(public Asset $asset)
  handle(RekognitionService): void   # early return if !isImage() or image/gif; catches + logs, never rethrows

App\Http\Controllers\AssetReplaceController::generateAiTags(Asset $asset)
  # POST /assets/{asset}/ai-tag (assets.ai-tag, throttle:ai-tag); authorize('update', $asset);
  # 302 + flash 'error' if !isImage() or Rekognition disabled; else autoTagAsset() SYNCHRONOUSLY
  # and redirect with a success/warning/error flash
```

The automatic path is dispatched by `AssetProcessingService::processImageAsset()` after every
image upload/replace ([`asset-upload.md`](asset-upload.md) owns that contract). Tag resolution
(`Tag::resolveAiTagIds()` — reuses an existing tag of any type, creates only missing ones as
`ai`) and pivot attribution are owned by [`tags.md`](tags.md).

### Data shapes

```yaml
detectLabels() result item:
  name: string        # lowercased, translated if target language != en
  confidence: float    # 0-100, AWS-reported

Settings consulted (Setting::get, live per call — see settings.md):
  rekognition_max_labels: integer      # default 3
  rekognition_min_confidence: integer  # default 80 (valid range 65-99)
  rekognition_language: string         # default 'nl'
```

### Layer touchpoints & ordering

```
processImageAsset() → [Rekognition enabled] GenerateAiTags::dispatch($asset)->afterResponse()
  → GenerateAiTags::handle() → guards (isImage, not GIF) → autoTagAsset()
      → detectLabels($asset->s3_key)  (S3Object reference, no local read; Translate per label if != en)
      → Tag::resolveAiTagIds() → syncTagsWithAttribution($ids, 'ai')
Manual: POST /assets/{asset}/ai-tag → same autoTagAsset(), synchronously, flash redirect
```

### Persistence

Results land only as `Tag` rows and `asset_tag` pivots (`attached_by = 'ai'`). No Rekognition or
Translate response is cached — a re-run always calls AWS fresh.

## Visual aids

```yaml
aws_sdk: aws/aws-sdk-php  # ^3.379 — Rekognition::detectLabels/detectText, Translate::translateText
```

## Scenarios (BDD)

```gherkin
Scenario: Rekognition disabled by config short-circuits every call
  Given services.aws.rekognition_enabled is false
  Then isEnabled() is false and detectLabels()/detectText() return [] without calling AWS
# pinned by: tests/Unit/Services/RekognitionServiceTest.php

Scenario: autoTagAsset skips non-image assets
  Given a PDF asset and Rekognition disabled
  When autoTagAsset($asset) is called
  Then it returns [] and no tags are attached
# pinned by: tests/Unit/Services/RekognitionServiceTest.php

Scenario Outline: The queued job tags images only
  Given an asset with mime_type <mime>
  When GenerateAiTags::handle() runs
  Then RekognitionService::autoTagAsset() is called <calls>
  Examples:
    | mime            | calls          |
    | image/jpeg      | exactly once   |
    | application/pdf | never          |
    | image/gif       | never          |
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

- Unit: `tests/Unit/Services/RekognitionServiceTest.php`, `tests/Unit/Jobs/GenerateAiTagsTest.php`
- Feature: `tests/Feature/SecurityRemediationTest.php`
- Run: `php artisan config:clear && php artisan test`

## Open questions / future

- Coverage gap: no test exercises the *enabled* paths of `detectLabels()`/`detectText()` against
  a mocked client (translation, confidence filtering, lowercasing).
- Coverage gap: `AssetReplaceController::generateAiTags` has no Feature test beyond the throttle
  assertion — authorization and the flash branches are unpinned.
