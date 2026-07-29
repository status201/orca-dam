<!--
  The landing page after login: library statistics plus the feature tour.
-->

# Dashboard

```yaml
id: dashboard
status: implemented
version: 1
owner: core
related:
  - architecture
  - authorization-policies
  - passkeys
  - user-preferences
source:
  - app/Http/Controllers/DashboardController.php
  - resources/js/alpine/dashboard.js
  - resources/views/dashboard.blade.php
```

## Background / Why

`GET /dashboard` is where every authenticated session lands, so it does two jobs:
report the state of the library in numbers, and point people at the features they
have not found yet. The tour exists because ORCA's capabilities (discovery, CSV
export, the tools) are spread across the nav and were going unused — it is a
carousel of one slide per feature, built from the viewer's role so nobody is shown
a link they will get a 403 from.

This spec was written after the fact, when the tour gained browser coverage: the
behaviour predates it and had no governing spec.

## Requirements

- **REQ-1** — Statistics are computed for the viewer, not globally cached: total
  assets, total/user/AI tags, the viewer's own asset count, and total storage in
  human-readable units. Soft-deleted assets are excluded from every count except
  `trashed_assets`.
- **REQ-2** — Admins additionally see total users and trashed assets. Non-admins
  instead see their own tag counts and their effective results-per-page, flagged
  as default or custom.
- **REQ-3** — The tour renders one slide per feature the viewer can reach: five
  base slides for everyone, seven more for admins, and a passkey-promotion slide
  first for a user who *may* register a passkey but has none. So the slide count is
  role-dependent by design, and nothing may assume a fixed number.
- **REQ-4** — The tour autoplays, advancing every 7s, and any manual navigation
  (next, previous, or a dot) stops it. The play/pause control reports and toggles
  that state.
- **REQ-5** — Navigation wraps in both directions: next from the last slide
  returns to the first, previous from the first goes to the last.
- **REQ-6** — Slide copy comes from `window.__pageData.translations`, so the tour
  renders in `en` and `nl` like the rest of the UI
  ([`localization.md`](localization.md)).

## Technical design

### Contract / public interface

- `DashboardController::index` — the only action. Returns view `dashboard` with
  `stats`, `isAdmin`, `showPasskeyPromo`. Route `dashboard`
  (`routes/web.php`), behind `auth` + `verified`.
- `featureTour(isAdmin, showPasskeyPromo)` (`resources/js/alpine/dashboard.js`) —
  the Alpine factory. State: `features`, `currentSlide`, `prevSlide`,
  `isTransitioning`, `isPlaying`. Methods: `goToSlide(index)`, `nextSlide()`,
  `previousSlide()`, `startAutoPlay()`, `pauseAutoPlay()`, `toggleAutoPlay()`.
- `showPasskeyPromo` is `User::canEnablePasskeys() && ! User::hasPasskeysEnabled()`
  — admins and editors only, so the `api` role never sees it.

### Data shapes

```yaml
stats:
  total_assets: int         # excludes soft-deleted
  total_tags: int
  user_tags: int
  ai_tags: int
  my_assets: int            # owned by the viewer
  total_users: int
  trashed_assets: int
  total_storage: string     # formatted, e.g. "1.42 MB"
  # non-admins only:
  my_tags: int
  my_user_tags: int
  my_ai_tags: int
  items_per_page: int
  items_per_page_is_default: bool
```

### Layer touchpoints & ordering

Two consolidated `DB::selectOne` aggregates (assets, tags) rather than six
`count()` queries — the numbers are shown together and the page is hit on every
login. `User::count()` and `Asset::onlyTrashed()->count()` stay separate. Nothing
is cached: the counts are cheap and a stale dashboard reads as a bug.

## Scenarios (BDD)

```gherkin
Scenario: The dashboard reports the library's totals
  Given assets and tags exist
  When an authenticated user opens /dashboard
  Then asset, tag and storage totals are rendered
  And soft-deleted assets are not counted among the totals

Scenario: An admin sees the admin-only statistics
  Given an admin
  When they open /dashboard
  Then total users and trashed assets are shown

Scenario: A non-admin sees their own statistics instead
  Given an editor
  When they open /dashboard
  Then their own tag counts and results-per-page are shown
  And total users is not

# — browser-level (see e2e-testing.md for the harness) —

Scenario: The tour renders one dot per slide
  Given the dashboard
  When the tour has hydrated
  Then there is exactly one dot per slide it reports
# pinned by: tests/e2e/dashboard-tour.spec.js

Scenario: Navigating the tour steps through the slides and wraps at the ends
  Given the tour parked on its first slide
  When next is clicked, then previous
  Then the counter goes forward one and back one
  And previous from the first slide shows the last
# pinned by: tests/e2e/dashboard-tour.spec.js

Scenario: Manual navigation stops the autoplay
  Given the tour autoplaying on load
  When any slide control is used
  Then autoplay reports itself stopped, so the slide no longer changes underneath
    the reader
# pinned by: tests/e2e/dashboard-tour.spec.js

Scenario: The autoplay control toggles both ways
  Given the tour autoplaying
  When the control is clicked twice
  Then it stops and then resumes
# pinned by: tests/e2e/dashboard-tour.spec.js
```

## Tests & verification

- E2E: `tests/e2e/dashboard-tour.spec.js` — the tour's navigation, wrapping and
  autoplay. Slide counts are asserted relative to what the page reports, never
  hardcoded, because they are role-dependent (REQ-3).
- `tests/e2e/role-matrix.spec.js` — that each role lands on a dashboard naming
  them.
- `npm run test:e2e`; `php artisan config:clear && php artisan test`.

## Open questions / future

- The statistics have no Pest coverage of their own; the counts are only asserted
  through the browser. A Feature test asserting `stats` for a seeded library would
  pin REQ-1/REQ-2 far more cheaply than the E2E suite can.
- The tour has no "seen it" state, so it restarts from slide one on every login.
