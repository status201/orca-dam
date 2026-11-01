# Asset cycle navigation

```yaml
id: asset-cycle-navigation
status: implemented
version: 3
owner: core
related:
  - architecture
  - asset-search
  - asset-model
  - user-preferences
source:
  - app/Http/Controllers/AssetController.php
  - resources/views/assets/show.blade.php
  - resources/views/assets/partials/grid-cards.blade.php
  - resources/views/components/asset-cycle-nav.blade.php
  - resources/js/alpine/asset-detail.js
  - app/Support/AssetFilter.php
  - app/Support/AssetSort.php
```

## Background / Why

The asset library is a filtered, sorted, paginated grid; the detail page is a separate route.
Cycle navigation reconstructs the index's result set on the detail page so prev/next walks the
*same* list across pagination boundaries, and "back" returns to the exact view the user left. It
is derived from the request's query string, not session state, so a shared or bookmarked URL
either carries its context or degrades cleanly to a plain deeplink.

## Requirements

- **REQ-1** — The show page renders cycle navigation only when the request carries at
  least one *index-context* query parameter (`AssetController::CONTEXT_KEYS`:
  `search`, `tags`, `type`, `folder`, `user`, `missing`, `ids`, `sort`, `per_page`,
  `page`). A bare deeplink (`/assets/{id}`) gets `cycleNav = null` and no widget.
- **REQ-2** — The cycled list is the *same* query the index uses:
  `buildFilteredAssetQuery()` is shared by `index`, `embed` and `buildCycleNav()`, so
  every filter, the folder scoping, the admin-only `user` filter, and the sort order
  behave identically in both places. Cycling is unpaginated — it spans the whole result
  set, not the current page.
- **REQ-3** — `cycleNav` is `null` (widget hidden) in three further cases: the result set
  holds one asset or fewer; the current asset is not in the reconstructed list (a stale
  link, e.g. after the asset was re-filtered out); or context extraction yielded nothing
  because every context param was empty. An empty `ids` param (`?ids=`) does not count
  as context.
- **REQ-4** — A neighbour's URL carries the same context **with `page` recalculated** to
  the index page that neighbour actually falls on
  (`floor(neighbourIndex / perPage) + 1`), so returning to the index from a neighbour
  lands on the right page rather than the original one.
- **REQ-5** — The widget shows a 1-based `position` of `total`, and a human-readable
  `summary` of the active filter/sort ("Filtered by … · sorted by …"), built from
  non-default values only. The `user` filter is named in the summary **only** when the
  viewer is an admin or the filter targets themselves — the same guard
  `buildFilteredAssetQuery()` applies — so a non-admin can never learn another user's
  name through the badge.
- **REQ-6** — The back link derives from the context params when present (returning to
  the exact filtered/sorted/paged view); otherwise it falls back to the
  `assets_return_url` session value stamped by `index`/`embed`, and finally to
  `assets.index`. That stamp is the index route plus the **context params only** — not the
  raw `fullUrl()` — for the reason given in REQ-7.
- **REQ-7** — Grid cards link to the show route with the **index-context query parameters**
  appended (the REQ-1 `CONTEXT_KEYS` allowlist, canonical order, empties dropped) — never the
  whole query string. Why: any other parameter can reactivate an unrelated feature on the detail
  page (e.g. a leaked `?demo=` — [`guided-demos.md`](guided-demos.md) REQ-6a).

## Technical design

### Contract / public interface

```yaml
routes:
  GET /assets/{asset}   AssetController::show    # authorize('view', $asset)

view data (buildIndexData — index and embed alike):
  $showSuffix → '' or '?'+http_build_query(context params)  # REQ-7; grid card links append it,
                and index/embed stamp assets_return_url from it

view data (resources/views/assets/show.blade.php):
  $cycleNav  → <x-asset-cycle-nav :nav="$cycleNav" />, and Js::from($cycleNav) into the
               assetDetail() Alpine component (keyboard + prefetch)
  $backUrl   → the back link (REQ-6)
```

The cycled query is `AssetController::buildFilteredAssetQuery()` (an `App\Support\AssetFilter`,
[`asset-model.md`](asset-model.md)); the summary's "sorted by" text is `AssetSort::label()`.

### Data shapes

```yaml
# the $cycleNav payload, or null
cycleNav:
  position: int          # 1-based index of the current asset in the result set
  total: int             # size of the whole filtered result set (all pages)
  prev: entry|null       # null on the first asset
  next: entry|null       # null on the last asset
  summary: string        # "" when nothing notable is active

entry:
  url: string            # route('assets.show', id) + context, page recalculated (REQ-4)
  thumb: string          # thumbnail_url, falling back to url — for client-side prefetch
  filename: string
```

### Layer touchpoints & ordering

The ID list is resolved **before** paging is considered (prev/next must cross page boundaries);
`perPage` only recomputes each neighbour's `page` (REQ-4). Both neighbours are loaded in one
`Asset::whereIn()` — never N+1.

### Persistence

Nothing is persisted. Transient state:

```
asset-cycle:{userId}:{sha1(json(context))}   # Cache, 60s TTL — the ordered ID list
session('assets_return_url')                 # stamped by index/embed, used by REQ-6
```

The cache key is **per user** (folder scoping and the `user` filter are viewer-dependent),
excludes `page` and `per_page` (the list is identical across pages), and `ksort`s the remaining
keys so parameter order cannot fragment it.

## Scenarios (BDD)

```gherkin
Scenario: A deeplink with no context renders no cycle navigation
  Given three assets and a signed-in user
  When they GET /assets/{id} with no query parameters
  Then the response has no cycle-nav markup
# pinned by: tests/Feature/AssetCycleNavigationTest.php

Scenario: Opening an asset in context shows its position and neighbours
  Given five assets named a.jpg … e.jpg
  When the user opens the third with ?sort=name_asc
  Then the cycle nav reports position 3 of 5 with prev and next anchors
  And the first asset has no prev anchor and the last no next anchor
# pinned by: tests/Feature/AssetCycleNavigationTest.php

Scenario: A neighbour on another page gets its page number recalculated (REQ-4)
  Given a result set larger than per_page and the user on the last asset of page 1
  Then the next entry's URL carries page=2
# pinned by: tests/Feature/AssetCycleNavigationTest.php

Scenario: Filters narrow the cycled set to exactly what the index showed (REQ-2)
  Given assets where only some carry the filtered tag
  When the user opens a tagged asset with that tag filter (or a search term) in context
  Then the cycle total counts only the matching assets
# pinned by: tests/Feature/AssetCycleNavigationTest.php

Scenario: No cycle nav for an excluded asset or a single-asset result (REQ-3)
  Given a context whose filters exclude the viewed asset, or that matches exactly one asset
  Then cycleNav is null
# pinned by: tests/Feature/AssetCycleNavigationTest.php

Scenario: The back link round-trips the context, or falls back without it (REQ-6)
  Given a show page opened with context params
  Then the back URL carries those same params
  But opened with no context it falls back to the assets index
# pinned by: tests/Feature/AssetCycleNavigationTest.php

Scenario: The summary never leaks another user's name to a non-admin (REQ-5)
  Given an admin filtering by an uploader
  Then the summary names that uploader
  But a non-admin filtering by a different user gets no name in the summary
# pinned by: tests/Feature/AssetCycleNavigationTest.php

Scenario: Card links and the return URL carry only context params (REQ-6, REQ-7)
  Given the index rendered with ?sort=name_asc&search=a and a guided demo armed in the URL
  Then the card links and the stamped assets_return_url carry sort and search
  And neither carries the demo parameters
# pinned by: tests/Feature/AssetCycleNavigationTest.php

Scenario: The block that builds $showSuffix never leaks into the page (REQ-7)
  Given the index and the embed page rendered with a non-empty result set
  When the response body is inspected
  Then it carries no Blade directive text and no raw $showSuffix
# pinned by: tests/Feature/AssetTest.php, tests/Feature/EmbedTest.php, tests/e2e/asset-grid.spec.js
```

## Tests & verification

- Feature: `tests/Feature/AssetCycleNavigationTest.php` (flushes the cache in `beforeEach`),
  `tests/Feature/AssetTest.php`, `tests/Feature/EmbedTest.php`
- Feature: `tests/Feature/BladeRawBlockTest.php` — repo-wide guard against directive literals
  inside Blade raw blocks, where `$showSuffix` is built.
- Unit: `tests/Unit/AssetSortScopeTest.php` — the ordering the cycle relies on.
- E2E: `tests/e2e/asset-grid.spec.js`
- Run: `php artisan config:clear && php artisan test tests/Feature/AssetCycleNavigationTest.php`

## Open questions / future

- No E2E coverage of the cycling itself (keyboard + prefetch in `asset-detail.js`).
- The 60s ID-list cache can show a stale `total` for up to a minute after an upload or trash.
- The full ID list is cached with no explicit cap; folder scoping bounds it in practice.
