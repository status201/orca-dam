# Asset cycle navigation

```yaml
id: asset-cycle-navigation
status: implemented
version: 2
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
```

## Background / Why

The asset library is a filtered, sorted, paginated grid; the detail page is a separate
route. Without help, opening an asset means losing the result set — the user goes back,
re-finds their place, and clicks the next card. Cycle navigation reconstructs the index's
result set on the detail page so prev/next walks the *same* filtered, sorted list the
user was looking at, across pagination boundaries, and "back" returns to the exact view
they left. It is deliberately derived from the request's query string rather than from
server-side session state, so a shared or bookmarked URL either carries its context or
degrades cleanly to a plain deeplink.

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
  appended (the REQ-1 `CONTEXT_KEYS` allowlist, canonical order, empties dropped), which is
  what supplies the context in REQ-1. Deliberately *not* the whole query string: the show
  page reads nothing outside that allowlist, so any other parameter is at best carried for
  no reason and at worst reactivates an unrelated feature on the detail page — a finished
  guided demo re-arming from a leaked `?demo=` is the case that forced this
  ([`guided-demos.md`](guided-demos.md) REQ-6a).

## Technical design

### Contract / public interface

```yaml
routes:
  GET /assets/{asset}   AssetController::show    # authorize('view', $asset)

controller (app/Http/Controllers/AssetController.php, all private):
  extractContextParams(Request): array           # CONTEXT_KEYS present & non-empty, canonical order
  buildCycleNav(Request, Asset): ?array          # the payload below, or null per REQ-1/REQ-3
  buildCycleEntry(array $context, int $neighbourId, int $neighbourIndex,
                  int $perPage, ?Asset): array   # one prev/next entry, page recalculated
  buildBackUrl(Request): string                  # REQ-6
  buildContextSummary(Request): string           # REQ-5, localized via __()
  sortLabel(string): string                      # sort key → translated label
  buildFilteredAssetQuery(Request): Builder      # shared with index/embed — REQ-2

view data (buildIndexData, so index and embed alike):
  $showSuffix → '' or '?'+http_build_query(extractContextParams())  # REQ-7; the grid
                card links append it, and index/embed stamp assets_return_url from it

view data (resources/views/assets/show.blade.php):
  $cycleNav  → <x-asset-cycle-nav :nav="$cycleNav" />, and Js::from($cycleNav)
               into the assetDetail() Alpine component for keyboard/prefetch
  $backUrl   → the back link
```

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

```
GET /assets/{id}?<context>
  → authorize('view', $asset)                     # AssetPolicy, see authorization-policies.md
  → load tags (withCount assets), user, parent, children
  → buildCycleNav()
      → extractContextParams()                    # empty ⇒ null, stop
      → Cache::remember(cacheKey, 60s, buildFilteredAssetQuery()->pluck('id'))
      → total ≤ 1 ⇒ null; asset not in list ⇒ null
      → resolvePerPage(), pick prev/next ids
      → one Asset::whereIn() for both neighbours   # never N+1 per neighbour
      → buildCycleEntry() ×2, buildContextSummary()
  → buildBackUrl()
  → view('assets.show')
```

The ordering constraint that matters: the ID list is resolved **before** paging is
considered, because prev/next must cross page boundaries. `perPage` is then used only to
recompute each neighbour's `page` (REQ-4), never to bound the list.

### Persistence

Nothing is persisted. Two pieces of transient state:

```
asset-cycle:{userId}:{sha1(json(context))}   # Cache, 60s TTL — the ordered ID list
session('assets_return_url')                 # stamped by index/embed, used by REQ-6
```

The cache key is **per user** (folder scoping and the `user` filter are viewer-dependent,
so one user's ID list must never be served to another) and deliberately excludes `page`
and `per_page` — the ordered list is identical across pages, so paging through a result
set reuses one cache entry. The remaining context keys are `ksort`ed so parameter order
in the URL cannot fragment the cache.

## Scenarios (BDD)

```gherkin
Scenario: A deeplink with no context renders no cycle navigation
  Given three assets and a signed-in user
  When they GET /assets/{id} with no query parameters
  Then the response has no cycle-nav markup
# pinned by: tests/Feature/AssetCycleNavigationTest.php

Scenario: Opening an asset in context shows its position in the result set
  Given five assets named a.jpg … e.jpg
  When the user opens the third with ?sort=name_asc
  Then the cycle nav renders with prev and next anchors
  And it reports position 3 of 5
# pinned by: tests/Feature/AssetCycleNavigationTest.php

Scenario: The first and last assets have no prev / next anchor respectively
  Given an ordered result set
  When the user opens the first asset in context
  Then there is no prev anchor
  And opening the last asset yields no next anchor
# pinned by: tests/Feature/AssetCycleNavigationTest.php

Scenario: A neighbour on another page gets its page number recalculated (REQ-4)
  Given a result set larger than per_page
  And the user is on the last asset of page 1
  When the cycle nav builds the next entry
  Then that URL carries page=2, not the current page
# pinned by: tests/Feature/AssetCycleNavigationTest.php

Scenario: Filters narrow the cycled set to exactly what the index showed (REQ-2)
  Given assets where only some carry the filtered tag
  When the user opens a tagged asset with that tag filter in context
  Then the cycle total counts only the tagged assets
  And the same holds for a search-term context
# pinned by: tests/Feature/AssetCycleNavigationTest.php

Scenario: An asset outside the reconstructed result set gets no cycle nav (REQ-3)
  Given a context whose filters exclude the asset being viewed
  When the show page renders
  Then cycleNav is null
# pinned by: tests/Feature/AssetCycleNavigationTest.php

Scenario: A single-asset result set gets no cycle nav (REQ-3)
  Given a context matching exactly one asset
  When the show page renders
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

Scenario: Grid cards carry the current query string into the show URL (REQ-7)
  Given the index rendered with ?sort=name_asc&search=a
  When the grid markup is inspected
  Then the card links embed that query string
# pinned by: tests/Feature/AssetCycleNavigationTest.php

Scenario: A non-context parameter does not ride the card links or the return URL (REQ-6, REQ-7)
  Given the index rendered with ?sort=name_asc and a guided demo armed in the URL
  When the grid markup and the stamped assets_return_url are inspected
  Then both still carry sort=name_asc
  And neither carries the demo parameters
# pinned by: tests/Feature/AssetCycleNavigationTest.php

Scenario: The block that builds $showSuffix never leaks into the page (REQ-7)
  Given the index and the embed page rendered with a non-empty result set
  When the response body is inspected
  Then it carries no Blade directive text and no raw $showSuffix
# pinned by: tests/Feature/AssetTest.php, tests/Feature/EmbedTest.php, tests/e2e/asset-grid.spec.js
```

## Tests & verification

- Feature: `tests/Feature/AssetCycleNavigationTest.php` — all of the above; flushes the
  cache in `beforeEach` so the 60s ID-list cache cannot leak between cases.
- Feature: `tests/Feature/AssetTest.php`, `tests/Feature/EmbedTest.php` — the rendered index
  and embed pages carry no Blade directive text and no raw `$showSuffix`. Both assert a
  filename first, because the grid partial renders only for a non-empty result set and the
  check would otherwise pass on a page that never included it.
- Feature: `tests/Feature/BladeRawBlockTest.php` — repo-wide, and the reason the above is
  more than a one-off: it applies Blade's own non-greedy raw-block pattern to every view and
  fails on a directive literal written inside a block, or on one left closing nothing.
  `$showSuffix` lives in such a block, and v1.6.0 shipped it printing its own source.
- E2E: `tests/e2e/asset-grid.spec.js` — the same absence, in a browser.
- Unit: `tests/Unit/AssetSortScopeTest.php` — the `applySort` ordering the cycle relies
  on ([asset-model.md](asset-model.md)).
- Run: `php artisan config:clear && php artisan test tests/Feature/AssetCycleNavigationTest.php`
- Style: `./vendor/bin/pint --test`
- Manual check: open the library, apply a tag filter and a non-default sort, click an
  asset near a page boundary, and cycle forward past it — the back link should return to
  the correct page of the still-filtered grid.

## Open questions / future

- No E2E coverage of the cycling itself (the grid's rendered output is covered). The
  behaviour is keyboard- and prefetch-driven in
  `resources/js/alpine/asset-detail.js`, which a browser test would exercise better than
  a Feature test can; the Feature suite asserts the rendered payload, not the arrow-key
  handling. See [e2e-testing.md](e2e-testing.md).
- The 60s ID-list cache means an asset uploaded (or trashed) mid-browse can leave a
  stale `total` for up to a minute. Acceptable for navigation; it would not be for
  anything the user acts on.
- `buildCycleNav()` caches the full ID list, so a very large unfiltered result set caches
  a correspondingly large array. In practice folder scoping bounds it, but there is no
  explicit cap.
