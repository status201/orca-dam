# Tag Input Normalization

```yaml
id: tag-input
status: implemented
version: 3
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

Every place a human or API client supplies free-text tag names — asset edit, upload batch
metadata, the grid bulk bar and row input, CSV import, the reference-tags API — must accept a
single name, a comma-separated list, or an array mixing both, and produce one clean, deduped,
lowercased list. `App\Support\TagInputParser` is the single PHP-side shaper (name → id resolution
is [`tags.md`](tags.md)'s concern); `tag-input-core.js` is the equivalent for the four Alpine
tag-input widgets.

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
    # split on ',' -> Tag::normalizeName() (tags.md REQ-tag-name-normalization)
    # -> drop '' and len > maxLength -> dedupe, first-seen order. Pure: no DB access.

resources/js/alpine/tag-input-core.js:
  parseTagNames(raw: string): string[]
    # split on /[,\n\r]+/ -> trim().toLowerCase() -> Set-based dedupe, first-seen order
  tagInputCore(config): AlpineMixinObject
    # config: { model, onCommitNames, isDuplicate?, onDuplicate?, afterCommit?, clearOnCommit=true }
    # exposes commitInput() and handleTagPaste(event) for x-data spread
```

Backend callers, all passing raw input straight through:

```yaml
AssetController::addTags:             parse($request->input('tags', []))
AssetBulkController::bulkAddTags:     parse($request->input('tags', []))
AssetProcessingService::applyUploadMetadata: parse($metadata->tags)
AssetController::update, Api\AssetApiController::update:
                                       UpdateAssetRequest::userTagIds() -> parse($this->input('tags', []))
Api\AssetApiController::addReferenceTags: parse($request->input('tags'))
ImportController (CSV import):        parse($row['user_tags'] ?? null), parse($row['reference_tags'] ?? null)
```

Frontend callers (`tagInputCore()` spread into `x-data`): asset edit (`asset-editor.js`), upload
batch metadata (`upload-metadata.js`), grid bulk bar and grid row (`asset-grid.js`).

### Layer touchpoints & ordering

```
Raw input → TagInputParser::parse() → Tag::resolve*TagIds() → Asset::syncTagsWithAttribution()  (tags.md)
```

## Scenarios (BDD)

```gherkin
Scenario Outline: parse() shapes raw input into clean names
  When TagInputParser::parse(<input>) is called
  Then the result is <result>
  Examples:
    | input                              | result                     |
    | "Cat, DOG, animal"                 | ["cat", "dog", "animal"]   |
    | ["cat,dog", "animal"]              | ["cat", "dog", "animal"]   |
    | "a,,b,"                            | ["a", "b"]                 |
    | "red, blue, red"                   | ["red", "blue"]            |
    | "ok, <101 chars>, fine"            | ["ok", "fine"]             |
    | "abc, abcd" with maxLength 3       | ["abc"]                    |
    | null, '', [] or '   '              | []                         |
# pinned by: tests/Unit/TagInputParserTest.php

Scenario: The tag endpoints split and dedupe comma-joined values
  When POST /assets/{asset}/tags is sent with tags=["landscape, mountain, sunset"]
  Then the asset ends up with 3 separate tags
  And tags=["red", "red, blue", "RED"] yields exactly red, blue
  And POST /assets/bulk/tags with tags=["alpha, beta", "gamma"] gives every targeted asset all three
# pinned by: tests/Feature/TagTest.php

Scenario: Saving the edit form splits a comma-joined tag value
  When PATCH /assets/{asset} is sent with tags=["landscape, mountain"]
  Then the asset ends up with 2 separate user tags: landscape, mountain
  And sending tags=null clears the user tags instead of failing
# pinned by: tests/Feature/TagTest.php

# — browser-level (see e2e-testing.md for the harness) —

Scenario: Typing a comma splits one entry into two chips on the edit page
  Given the asset edit page
  When "alpha, beta" is typed into the tag input
  Then two separate tag chips are created
# pinned by: tests/e2e/asset-detail.spec.js

Scenario: The grid's inline row input and the bulk bar share the same splitting
  Given the asset grid
  When a comma-separated list is entered in a row input, and again in the bulk bar
  Then both produce the same separate tags
# pinned by: tests/e2e/asset-detail.spec.js, tests/e2e/bulk-operations.spec.js

Scenario: The batch metadata form splits, dedupes, removes and keeps reference tags
  Given the collapsed batch metadata form on the upload page
  When it is opened and a comma-separated list is entered
  Then one badge per tag is created and the input is consumed
  And adding an already staged tag does not add it twice, and a staged tag can be removed
  And a reference tag chosen from the suggestions stays staged as a reference tag
# pinned by: tests/e2e/upload-metadata.spec.js
```

## Tests & verification

- Unit: `tests/Unit/TagInputParserTest.php`
- Feature: `tests/Feature/TagTest.php` — `php artisan config:clear && php artisan test`
- E2E: `tests/e2e/asset-detail.spec.js`, `tests/e2e/bulk-operations.spec.js`,
  `tests/e2e/upload-metadata.spec.js` ([`e2e-testing.md`](e2e-testing.md))

## Open questions / future

- `tag-input-core.js` has no JS unit test (no runner configured); its behaviour is pinned only
  through the browser suite.
