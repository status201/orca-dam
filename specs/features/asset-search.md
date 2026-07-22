# Asset search

```yaml
id: asset-search
status: implemented
version: 1
owner: core
related:
  - architecture
  - asset-model
source:
  - app/Services/AssetSearchParser.php
  - app/Models/Asset.php
```

## Background / Why

The asset grid's search box supports lightweight boolean operators
(`+required`, `-excluded`, `"exact phrase"`) rather than a full query
language, matched against filename, `s3_key`, `alt_text`/`caption`, and tag
names. `AssetSearchParser` is kept separate from `Asset::scopeSearch` (a thin
delegator) specifically so the parsing/query-building logic is unit-testable
without a database — see `architecture.md`'s service-layer map.

## Requirements

- **REQ-1** — Bare terms are OR'd together (at least one must match); `+term`
  and quoted phrases (with or without a leading `+`) are required (AND'd);
  `-term`/`-"phrase"` are excluded (NOT'd).
- **REQ-2** — A lone `+` or `-` token (no following word) is ignored rather
  than treated as a term.
- **REQ-3** — Quoted phrases are extracted **before** whitespace-splitting the
  remaining string, so a phrase's internal spaces are never re-split into
  separate bare terms.
- **REQ-4** — If the raw search input matches the configured S3 bucket URL or
  `custom_domain` prefix, it is stripped down to the bare `s3_key` and treated
  as a single literal term — operator parsing (`+`/`-`/quotes) is skipped
  entirely for a recognized URL, since URLs legitimately contain those
  characters.
- **REQ-5** — On MySQL/MariaDB, free-text matching against `alt_text`/`caption`
  uses a FULLTEXT index in `BOOLEAN MODE`; on SQLite (tests) it falls back to
  `LIKE`. `filename`/`s3_key` always use `LIKE` on every driver.

## Technical design

### Contract / public interface

```yaml
AssetSearchParser::apply(Builder $query, ?string $search): void   # entry point, called by Asset::scopeSearch
AssetSearchParser::normalizeSearchTerm(string $search): string     # strips known URL prefixes
AssetSearchParser::parseSearchTerms(string $search): array         # => {regular: [], required: [], excluded: []}
Asset::scopeSearch($query, ?string $search)                        # thin delegator
```

### Layer touchpoints & ordering

`parseSearchTerms()` first extracts `+"phrase"`/`-"phrase"`/`"phrase"` via a
regex callback (populating `required`/`excluded`/`required`-by-default
respectively and removing them from the string), then whitespace-splits what
remains into bare `+term`/`-term`/`term` tokens. `addSearchCondition()` builds
the per-term OR-across-fields clause (filename, s3_key, alt_text/caption via
FULLTEXT-or-LIKE, tag name via `whereHas`); `addExcludeCondition()` builds the
mirrored AND-NOT-across-fields clause.

## Scenarios (BDD)

```gherkin
Scenario: Bare terms are OR'd
  Given search input "cat dog"
  Then an asset matching either "cat" or "dog" in any searchable field is returned
# pinned by: tests/Unit/Services/AssetSearchParserTest.php, tests/Unit/AssetTest.php

Scenario: A +term is required
  Given search input "+cat"
  Then only assets matching "cat" are returned
# pinned by: tests/Unit/Services/AssetSearchParserTest.php, tests/Unit/AssetTest.php

Scenario: A -term is excluded
  Given search input "-cat"
  Then assets matching "cat" are excluded from the results
# pinned by: tests/Unit/Services/AssetSearchParserTest.php, tests/Unit/AssetTest.php

Scenario: Mixed +, -, and bare terms combine correctly
  Given search input "+cat -dog bird"
  Then results require "cat", exclude "dog", and OR-match "bird"
# pinned by: tests/Unit/Services/AssetSearchParserTest.php, tests/Unit/AssetTest.php

Scenario: A quoted phrase with no prefix is required
  Given search input "\"red car\""
  Then only assets matching the exact phrase "red car" are returned
# pinned by: tests/Unit/Services/AssetSearchParserTest.php, tests/Unit/AssetTest.php

Scenario: A quoted phrase with a - prefix is excluded
  Given search input "-\"red car\""
  Then assets matching "red car" are excluded
# pinned by: tests/Unit/Services/AssetSearchParserTest.php, tests/Unit/AssetTest.php

Scenario: Phrases are extracted before bare-token splitting
  Given search input "\"red car\" +blue"
  Then "red car" is treated as one phrase and "blue" as a separate required term
# pinned by: tests/Unit/Services/AssetSearchParserTest.php

Scenario: A bare + or - with no following word is ignored
  Given search input "+ - cat"
  Then only "cat" is treated as a term
# pinned by: tests/Unit/Services/AssetSearchParserTest.php, tests/Unit/AssetTest.php

Scenario: Search input matching the configured S3 URL is stripped to the bare s3_key
  Given search input equal to "{s3_bucket_url}/assets/marketing/photo.jpg"
  Then the search matches by s3_key "assets/marketing/photo.jpg" as a literal term
# pinned by: tests/Unit/Services/AssetSearchParserTest.php, tests/Feature/AssetTest.php

Scenario: Search input matching the configured custom_domain is stripped
  Given custom_domain is set and search input starts with it
  Then the domain prefix is stripped before matching
# pinned by: tests/Unit/Services/AssetSearchParserTest.php

Scenario: Search excludes assets by tag name
  Given search input "-cat" and an asset tagged "cat"
  Then that asset is excluded even though its filename doesn't contain "cat"
# pinned by: tests/Unit/AssetTest.php

Scenario: Search requires a match on tag name
  Given search input "+cat" and only tag-matching assets
  Then only tag-matching assets are returned
# pinned by: tests/Unit/AssetTest.php

Scenario: Exclude handles null alt_text and caption gracefully
  Given assets with null alt_text/caption
  When an exclude term is searched
  Then those assets are not incorrectly excluded due to the null comparison
# pinned by: tests/Unit/AssetTest.php
```

## Tests & verification

- Unit: `tests/Unit/Services/AssetSearchParserTest.php`, `tests/Unit/AssetTest.php`
  (integration of the parser through the Eloquent scope, including
  FULLTEXT-vs-LIKE driver behavior)
- Feature: `tests/Feature/AssetTest.php` (search via the index endpoint,
  including the URL-stripping scenario end-to-end)
- Run: `php artisan config:clear && php artisan test`
