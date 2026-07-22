# Tag Input Normalization

```yaml
id: tag-input
status: implemented
version: 1
owner: core
related:
  - architecture
  - tags
source:
  - app/Support/TagInputParser.php
  - resources/js/alpine/tag-input-core.js
  - app/Http/Controllers/AssetController.php
  - app/Http/Controllers/AssetBulkController.php
  - app/Http/Controllers/ImportController.php
  - app/Services/AssetProcessingService.php
  - app/Http/Controllers/Api/AssetApiController.php
```

## Background / Why

Every place a human or API client supplies free-text tag names — the asset
edit form, the upload batch-metadata form, the grid's bulk tag bar, a single
grid row, CSV import, and the reference-tags API — needs to accept a single
name, a comma-separated list ("a,b,c"), or an array mixing both, and turn it
into a clean, de-duplicated, correctly-cased list. Before this was
centralised, each call site re-implemented the splitting/trimming/lowercasing,
so behaviour drifted (e.g. one call site might not dedupe case-insensitively).
`App\Support\TagInputParser` is now the single PHP-side source of truth for
this shaping (name resolution/id lookup stays a separate concern — see
[`tags.md`](tags.md)'s `Tag::resolveUserTagIds`/`resolveReferenceTagIds`); the
JS module `tag-input-core.js` is the equivalent single source of truth for the
four Alpine tag-input widgets in the browser.

## Requirements

- **REQ-1** — `TagInputParser::parse()` accepts `string|array|null`. Any
  element (including array elements) may itself be a comma-separated list.
- **REQ-2** — Output is: comma-split, trimmed, lowercased, empty segments
  dropped, de-duplicated (case-insensitive, first-seen order preserved), and
  any name longer than the max length dropped.
- **REQ-3** — The default max length is `Tag::MAX_NAME_LENGTH` (100); a caller
  may override it with an explicit second argument.
- **REQ-4** — Every backend call site that accepts free-text tag input goes
  through `TagInputParser::parse()` — no ad hoc `explode(',', ...)` elsewhere.
- **REQ-5** — The browser-side `tagInputCore()` Alpine mixin (and its pure
  helper `parseTagNames()`) provides equivalent comma/newline splitting,
  trimming, lowercasing, and de-duplication for the four tag-input widgets, and
  intercepts multi-tag pastes so a comma/newline-bearing clipboard paste commits
  immediately instead of landing as one malformed tag.

## Technical design

### Contract / public interface

```yaml
App\Support\TagInputParser:
  parse(string|array|null $input, int $maxLength = Tag::MAX_NAME_LENGTH): array<string>
    # split each piece on ',' -> mb_strtolower(trim()) -> drop '' and len > maxLength
    # -> dedupe via keyed array (preserves first-seen order) -> array_keys()

resources/js/alpine/tag-input-core.js:
  parseTagNames(raw: string): string[]
    # split on /[,\n\r]+/ -> trim().toLowerCase() -> Set-based dedupe, first-seen order
  tagInputCore(config): AlpineMixinObject
    # config: { model, onCommitNames, isDuplicate?, onDuplicate?, afterCommit?, clearOnCommit=true }
    # exposes commitInput() and handleTagPaste(event) for x-data spread
```

Callers (backend), all passing raw request/CSV input straight through:

```yaml
AssetController::addTags:             TagInputParser::parse($request->input('tags', []))
AssetBulkController::bulkAddTags:     TagInputParser::parse($request->input('tags', []))
AssetProcessingService::applyUploadMetadata: TagInputParser::parse($tagNames)   # metadata_tags[] from upload
Api\AssetApiController::update:       TagInputParser::parse($request->input('tags', []))
Api\AssetApiController::addReferenceTags: TagInputParser::parse($request->input('tags'))
ImportController (CSV import):        TagInputParser::parse($row['user_tags'] ?? null)
                                       TagInputParser::parse($row['reference_tags'] ?? null)
```

Callers (frontend, `tagInputCore()` spread into Alpine `x-data`) — the four tag
inputs named in `CLAUDE.md`'s conventions section: asset edit (`asset-editor.js`),
upload batch-metadata form (`upload-metadata.js`), grid bulk bar and grid row
(`asset-grid.js`).

### Data shapes

Input: `string | array<int, string|null> | null` — e.g. `"cat, dog, animal"`,
`["cat,dog", "animal"]`, or a mix.

Output: `array<int, string>` — lowercase, trimmed, deduped, length-bounded tag
names, ready for `Tag::resolveUserTagIds()` / `resolveReferenceTagIds()` (name
-> id resolution is intentionally a separate step, documented in
[`tags.md`](tags.md)).

### Layer touchpoints & ordering

```
Raw input (string | array, possibly comma-joined)
  -> TagInputParser::parse()           (this spec — pure, no DB access)
  -> Tag::resolveUserTagIds() / resolveReferenceTagIds()   (tags.md — name -> id, creates missing tags)
  -> Asset::syncTagsWithAttribution()  (tags.md — attach + attribution)
```

`TagInputParser` has no dependency on the DB or the `Tag` model beyond reading
the `Tag::MAX_NAME_LENGTH` constant for its default — it is a pure string
transform, safely unit-testable without a database.

### Persistence

None — this is a stateless parsing utility; nothing is persisted at this
layer. (Persistence of the resulting tag names is `tags.md`'s concern.)

## Scenarios (BDD)

```gherkin
Scenario: A comma-separated string is split into trimmed, lowercased names
  When TagInputParser::parse("Cat, DOG, animal") is called
  Then the result is ["cat", "dog", "animal"]
# pinned by: tests/Unit/TagInputParserTest.php

Scenario: An array with comma-joined elements is flattened
  When TagInputParser::parse(["cat,dog", "animal"]) is called
  Then the result is ["cat", "dog", "animal"]
# pinned by: tests/Unit/TagInputParserTest.php

Scenario: Empty segments are dropped
  When TagInputParser::parse("a,,b,") is called
  Then the result is ["a", "b"]
# pinned by: tests/Unit/TagInputParserTest.php

Scenario: Names are de-duplicated case-insensitively, preserving first-seen order
  When TagInputParser::parse("red, blue, red") is called
  Then the result is ["red", "blue"]
# pinned by: tests/Unit/TagInputParserTest.php

Scenario: Names longer than the max length are dropped
  Given a 101-character name and the default max length of 100
  When TagInputParser::parse("ok, <101 chars>, fine") is called
  Then the result is ["ok", "fine"]
# pinned by: tests/Unit/TagInputParserTest.php

Scenario: A custom max length overrides the Tag default
  When TagInputParser::parse("abc, abcd", 3) is called
  Then the result is ["abc"]
# pinned by: tests/Unit/TagInputParserTest.php

Scenario: null, empty string, empty array, and whitespace-only input all yield []
  When TagInputParser::parse(null | '' | [] | '   ') is called
  Then the result is [] in every case
# pinned by: tests/Unit/TagInputParserTest.php

Scenario: Adding tags via the single-asset endpoint splits a comma-joined value
  Given an authenticated user and an asset
  When POST /assets/{asset}/tags is sent with tags=["landscape, mountain, sunset"]
  Then the asset ends up with 3 separate tags: landscape, mountain, sunset
# pinned by: tests/Feature/TagTest.php

Scenario: Adding tags dedups within a single request across mixed values
  Given an authenticated user and an asset
  When POST /assets/{asset}/tags is sent with tags=["red", "red, blue", "RED"]
  Then the asset ends up with exactly 2 tags: red, blue
# pinned by: tests/Feature/TagTest.php

Scenario: Bulk add tags splits comma-joined values across every targeted asset
  Given an authenticated user and two assets
  When POST /assets/bulk/tags is sent with tags=["alpha, beta", "gamma"]
  Then both assets end up with tags alpha, beta, gamma
# pinned by: tests/Feature/TagTest.php
```

## Tests & verification

- Unit: `tests/Unit/TagInputParserTest.php` — exhaustive coverage of `parse()`'s
  splitting/trim/case/dedupe/length rules in isolation.
- Feature (integration through real endpoints): `tests/Feature/TagTest.php`
  ("add tags splits a comma-joined value...", "add tags dedups within a single
  request", "bulk add tags splits comma-joined values").
- Run: `php artisan config:clear && php artisan test tests/Unit/TagInputParserTest.php tests/Feature/TagTest.php`
- The JS side (`tag-input-core.js`) has no dedicated JS test suite in this repo
  (no JS test runner is configured) — see "Open questions" below.

## Open questions / future

- `tag-input-core.js` (`parseTagNames`, `tagInputCore`) has no automated test
  coverage — the repo has no JS unit-test runner wired up. The four Alpine
  widgets that consume it (`asset-editor.js`, `upload-metadata.js`,
  `asset-grid.js` bulk bar + row) are exercised only indirectly, through the
  Feature tests that hit the backend endpoints they eventually POST to. This is
  a coverage gap, not a documented behaviour — flagged here rather than pinned.
