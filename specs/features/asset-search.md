# Asset search

```yaml
id: asset-search
status: implemented
version: 1
owner: core
related:
  - architecture
  - asset-model
  - asset-cycle-navigation
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

Each term matches across filename, `s3_key`, `alt_text`/`caption` (FULLTEXT or LIKE, REQ-5)
and tag name (`whereHas`); an excluded term is the mirrored AND-NOT across the same fields.

## Scenarios (BDD)

```gherkin
Scenario Outline: Search operators combine as specified (REQ-1)
  Given search input <input>
  Then <result>
  Examples:
    | input              | result                                                    |
    | cat dog            | assets matching either term in any searchable field       |
    | +cat               | only assets matching "cat"                               |
    | -cat               | assets matching "cat" are excluded                       |
    | +cat -dog bird     | require cat, exclude dog, OR-match bird                   |
    | "red car"          | only the exact phrase (unprefixed phrases are required)   |
    | -"red car"         | the phrase is excluded                                    |
# pinned by: tests/Unit/Services/AssetSearchParserTest.php, tests/Unit/AssetTest.php

Scenario: Phrases are extracted before bare-token splitting
  Given search input "\"red car\" +blue"
  Then "red car" is treated as one phrase and "blue" as a separate required term
# pinned by: tests/Unit/Services/AssetSearchParserTest.php

Scenario: A bare + or - with no following word is ignored
  Given search input "+ - cat"
  Then only "cat" is treated as a term
# pinned by: tests/Unit/Services/AssetSearchParserTest.php, tests/Unit/AssetTest.php

Scenario: A configured S3 URL or custom_domain prefix is stripped to the bare s3_key (REQ-4)
  Given search input "{s3_bucket_url}/assets/marketing/photo.jpg" (or the custom_domain equivalent)
  Then the search matches s3_key "assets/marketing/photo.jpg" as one literal term
# pinned by: tests/Unit/Services/AssetSearchParserTest.php, tests/Feature/AssetTest.php

Scenario: Tag names take part in required and excluded matches
  Given an asset tagged "cat" whose filename does not contain it
  Then "+cat" returns it and "-cat" excludes it
  And an exclude term does not wrongly drop assets with null alt_text/caption
# pinned by: tests/Unit/AssetTest.php

# — browser-level (see e2e-testing.md for the harness) —

Scenario: Searching narrows the grid to matching filenames
  Given 14 seeded grid assets
  When "e2e-grid-01" is typed into the grid search and applied
  Then only that asset's card remains and a search filter pill is shown
  And a search with no matches shows the empty state
# pinned by: tests/e2e/asset-grid.spec.js

Scenario: The type filter narrows the grid and is reflected in the URL
  Given the asset grid
  When type=document is chosen
  Then only the seeded PDF is listed and the URL carries type=document
  And the same holds for the video type
# pinned by: tests/e2e/asset-grid.spec.js

Scenario: Sort order, view mode and pagination drive the grid as specified
  Given more seeded assets than one page holds
  Then sorting by name reorders the cards
  And each of the three view modes renders, with the choice remembered across loads
  And pagination splits the library
  And the tag filter panel lists the seeded tags and filters by them
# pinned by: tests/e2e/asset-grid.spec.js

Scenario: Clear all filters returns the full library
  Given a filtered grid
  When "clear all filters" is clicked
  Then the grid shows the unfiltered total again
# pinned by: tests/e2e/asset-grid.spec.js
```

## Tests & verification

- Unit: `tests/Unit/Services/AssetSearchParserTest.php`, `tests/Unit/AssetTest.php` (through the
  Eloquent scope, incl. FULLTEXT-vs-LIKE)
- Feature: `tests/Feature/AssetTest.php`
- E2E: `tests/e2e/asset-grid.spec.js`
- Run: `php artisan config:clear && php artisan test`
