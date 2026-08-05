<!--
  Interactive onboarding walkthroughs that spotlight real elements on real pages.
  Not to be confused with the dashboard's feature *tour* (a carousel) — see
  dashboard.md. Everything here is a "demo".
-->

# Guided Demos

```yaml
id: guided-demos
status: implemented
version: 1
owner: core
related:
  - architecture
  - dashboard
  - localization
  - user-preferences
  - authorization-policies
  - asset-search           # what the library steps explain
  - asset-upload           # what the upload steps explain
  - chunked-upload
  - e2e-testing
  - ../decisions/adr-007-blade-alpine-over-spa
  - ../decisions/adr-015-guided-demos-server-declared
source:
  - app/Demos/
  - app/Http/Controllers/GuidedDemoController.php
  - resources/js/alpine/guided-demo.js
  - resources/js/alpine/demo-geometry.js
  - resources/views/layouts/guided-demo.blade.php
```

## Background / Why

ORCA had no onboarding. A new user landed on `/dashboard` and got statistics plus the
feature **tour** — a carousel that *describes* capabilities and links to them
([`dashboard.md`](dashboard.md)) — but nothing that showed them *where things are* on the
page they were looking at. Reading "you can filter by tag" is not the same as being shown
the tag filter.

A **guided demo** is a walkthrough that anchors to the live DOM: it dims the page, cuts a
hole around one real element, explains it in an anchored popover, and moves on. It can
cross pages (the Welcome demo starts on `/dashboard` and continues on `/assets`), it can
ask the user to actually *do* the thing before advancing, and it can hand off to another
demo when it finishes. Demos are declared server-side so their copy goes through `__()`,
their links through `route()`, and their availability through a role gate — see
[ADR-015](../decisions/adr-015-guided-demos-server-declared.md).

**Naming.** "Tour" was already taken by the dashboard carousel (`featureTour`, the `tour-*`
testids, `tests/e2e/dashboard-tour.spec.js`). This feature is a **demo** throughout: `?demo=`,
`demo-*` testids, `app/Demos/`.

## Requirements

- **REQ-1** — A demo is a PHP class implementing `App\Demos\Demo`, registered explicitly in
  the `App\Demos\DemoRegistry` singleton. Adding one is a new class plus one registry line;
  it must require no edit to any existing demo.
- **REQ-2** — A step targets an element by its **`data-testid` value**, never by a CSS
  selector. `target` accepts a single value or an ordered list, in which case the first
  *visible* candidate wins — that is how one step covers the desktop/mobile duplicate pair
  and the three asset-grid view modes, which differ by `localStorage` and are unknowable
  server-side.
- **REQ-3** — Step copy is `__()`-translated and step links are resolved with `route()` at
  request time, so a demo renders in `en` and `nl` ([`localization.md`](localization.md)) and
  survives `route:cache`. Neither may be resolved at definition time.
- **REQ-4** — `Demo::isAvailableTo(User)` gates a whole demo. An unavailable demo is not
  listed, does not boot when named in the URL, and cannot be marked complete. A step may
  therefore only target elements every role the demo is available to can actually see.
- **REQ-5** — A demo never starts on its own. It runs only when the URL carries `?demo=<id>`
  or the user activates a launcher. There is no auto-start and no "you haven't done this yet"
  nag.
- **REQ-6** — Position is held in the URL (`?demo=<id>&demoStep=<n>`), which is therefore
  also a shareable "start here" link. The server clamps `demoStep` into range and renders the
  payload already positioned, so the first paint is the correct step. Within a page,
  navigation rewrites the URL with `history.replaceState` — never `pushState`, so Back costs
  one press per page rather than one per step.
- **REQ-6a** — `demo` and `demoStep` are the demo's own state, and no other feature may
  propagate them. A link, redirect or session stamp built from "the current query string"
  must exclude them. `stop()` scrubs the two parameters from the address bar, but it cannot
  scrub links the server already rendered: a page that copies its query string wholesale into
  its own outgoing links hands a *finished* demo straight back to the user on whatever they
  open next, where the step almost certainly belongs to another route and so surfaces as a
  REQ-7 hand-off card. The asset grid's card links and `assets_return_url` are the concrete
  case ([`asset-cycle-navigation.md`](asset-cycle-navigation.md) REQ-7). This is not a ban on
  `?demo=` in a URL — arming a demo that way is REQ-5/REQ-6, and a completed demo stays
  replayable — only on carrying it somewhere nobody asked for.
- **REQ-7** — When the resolved step belongs to a different route than the current one, the
  overlay renders a centered hand-off card whose primary action navigates there. This single
  rule covers the cross-page continuation, a shared link that lands on the wrong page, and
  Back-button drift.
- **REQ-7a** — A demo that leaves the page it started on says so before it goes, and ends
  where the reader can carry on working rather than stranding them on a form. The Welcome
  demo announces the upload screen on the step that opens it and closes with a step back on
  the library, which states that it has returned and that nothing was uploaded.
- **REQ-8** — A step may require a real user action (`advanceOn`) and advance when it happens.
  The engine observes DOM events on the target in the **capture** phase only; it must not know
  anything about the Alpine module that owns the target. `Next` stays enabled on such a step,
  so declining the action never traps the user.
- **REQ-8a** — A step may put the page into the state it is describing (`reveal`), so that a
  step about a view or a panel is read while that view or panel is on screen rather than
  described in the abstract. The asset library's three view modes are one step each for this
  reason: each switches the grid into its own view, so the same assets visibly rearrange.
- **REQ-9** — A missing target is not an error. The engine waits briefly for hydration, then
  either skips the step silently (`fallback: skip`) or renders it as a centered card
  (`fallback: center`). Nothing in the resolve/position path may throw: a broken demo must
  never break the page it is running on.
- **REQ-10** — A demo may name a successor (`nextDemoId`). The successor is gated through
  REQ-4, so the offer appears only for a user who can actually play it; otherwise the demo
  simply ends.
- **REQ-11** — Finishing or dismissing a demo is persisted per user under the `guided_demos`
  key of `users.preferences` ([`user-preferences.md`](user-preferences.md)). The write is
  fire-and-forget: a failed save ends the demo normally and reports nothing, because a
  storage error must not be a new user's first ORCA notification. Step *position* is never
  persisted server-side.
- **REQ-12** — The overlay ships on every authenticated page from the base layout, but renders
  **nothing** — no DOM node, no script — when no demo is armed.

## Technical design

### Contract / public interface

- `App\Demos\Demo` — the interface every demo implements: `id()`, `title()`, `description()`,
  `isAvailableTo(User)`, `steps(User)`, `nextDemoId()`.
- `App\Demos\DemoStep` — a `final readonly class` (not an array, so a mistyped key is a PHP
  error rather than a silently missing popover). `DemoStep::toArray()` is where `route()` is
  resolved into the wire `url`.
- `App\Demos\DemoRegistry` — `find(id)`, `get(id, User)` (null when ungated fails),
  `all(User)`, `next(Demo, User)`, `payload(id, User, step, currentRoute)`. Constructed with
  an explicit array of demos in `AppServiceProvider::register()` — no glob auto-discovery, so
  order is deterministic, mirroring how `resources/js/app.js` registers Alpine modules.
- `App\Demos\WelcomeDemo` — the first demo; available to every known role.
- `GuidedDemoController::complete` — the only server action. `POST /demos/{demo}/complete`,
  route name `demos.complete`, inside the `auth` group. Unknown demo → 404; demo not
  available to the caller → 403; otherwise 200 with `{message, completed: [ids]}`.
- `guidedDemo()` (`resources/js/alpine/guided-demo.js`) — the Alpine factory, no arguments;
  it reads `window.__pageData.guidedDemo`. State: `demo`, `steps`, `ui`, `index`, `running`,
  `settled`, `awaiting`, `missing`, `finished`, `placement`, `targetKey`. Getters the
  overlay binds to: `total`, `step`, `isLast`, `onThisPage`, `hasTarget`. Methods:
  `next()`, `prev()`, `goToStep(n)`, `goToPage()`, `skip()`, `finish()`, `startNext()`,
  `stop()`, `reposition()`.
- `demo-geometry.js` — a mixin (not a registered module): `holeRect`, `ringStyle`,
  `popoverStyle` (placement maths plus the viewport clamp and flip), `shutterStyle` (the four
  click-blocking panels), `isVisible`, `fixedAncestor`. Pure functions, no Alpine, no DOM
  writes — which is also why it is a mixin: only a registered module costs a doc-count bump.

### Data shapes

```yaml
# App\Demos\DemoStep — the definition shape
DemoStep.target: string|list|null      # data-testid value(s); null ⇒ unanchored card
DemoStep.title: string                 # __()
DemoStep.body: string                  # __()
DemoStep.routeName: string             # 'dashboard' | 'assets.index'
DemoStep.routeParams: map              # passed to route()
DemoStep.placement: string             # top|bottom|left|right|center
DemoStep.reveal: string|map|null       # 'scroll-top' | {hover: testid} | {click: testid, until?: testid}
DemoStep.advanceOn: map|null           # {event, on, minLength} | {appear: testid}
DemoStep.fallback: string              # 'skip' (default) | 'center'

# window.__pageData.guidedDemo — present only while a demo is armed
guidedDemo.id: string
guidedDemo.title: string
guidedDemo.step: int                   # server-clamped resume index, 0-based
guidedDemo.currentRoute: string        # Route::currentRouteName()
guidedDemo.completeUrl: string         # route('demos.complete', id)
guidedDemo.next.id: string|null        # the gated successor, or null
guidedDemo.next.title: string|null
guidedDemo.ui: map                     # translated chrome, shared by every demo
guidedDemo.steps: list                 # DemoStep::toArray(), plus a resolved 'url'

# users.preferences['guided_demos'] — one entry per completed demo
guided_demos.<demo-id>.completed_at: string   # ISO-8601
guided_demos.<demo-id>.dismissed: bool        # true ⇒ skipped rather than finished
```

`ui` is demo-agnostic and owned by the registry, so a new demo adds no chrome strings.

### Layer touchpoints & ordering

`resources/views/layouts/guided-demo.blade.php` is included once by `layouts/app.blade.php`
(deliberately **not** by `layouts/embed.blade.php`, which is iframe chrome with no nav). The
partial asks `DemoRegistry::payload()` directly — the same shape as
`layouts/navigation.blade.php`, which reads `Setting::get` inline; the repo uses no view
composers. When the payload is null it emits nothing at all (REQ-12).

The payload is written with the **namespaced** merge idiom
(`window.__pageData = window.__pageData || {}` then `window.__pageData.guidedDemo = …`) as
`tags/index.blade.php` does. This matters concretely: `dashboard.blade.php` assigns the whole
`window.__pageData` object, which would clobber a root-level write regardless of push order.

Cross-page continuation is a real navigation, so each page boots independently: server renders
the payload positioned → Alpine starts on `DOMContentLoaded` → the engine resolves the step's
target. Some *app* controls navigate on their own (`assetGrid`'s filter selects set
`window.location.href`) and drop the query string; against that the engine writes a
`sessionStorage` breadcrumb **synchronously** inside its capture-phase handler, and on a boot
that finds a breadcrumb but no `?demo=` it re-arms the URL with a single `location.replace`.

### Persistence

- **Persisted:** completion only — `users.preferences['guided_demos']` (see the shape above).
  Written by `GuidedDemoController::complete` via a whole-map rebuild followed by one
  `User::setPreference('guided_demos', …)`, because `setPreference` is a shallow top-level
  write and cannot set a nested path. Reads work dotted, because `getPreference` uses
  `data_get`.
- **Deliberately not persisted:** the step cursor. Position is ephemeral and lives in the URL
  (REQ-6), with the `sessionStorage` breadcrumb as a same-tab backstop only. Persisting it
  server-side would resurrect an abandoned demo weeks later on another device.
- **Deliberately no `Setting` row.** There is no org-wide "demos off" switch: a demo only runs
  when asked for, so there is nothing to disable.

### Rendering

Nothing about the target element is ever mutated to make it stand out. That constraint is
forced, not stylistic: the nav carries a `transition-transform` and sets `-translate-y-full`
when hiding, which makes it a stacking context, so raising a nav link's `z-index` would still
paint it *below* the dimming.

**Dimming and click-blocking are separate jobs, done by different elements.**

- **Dimming is one element** — the spotlight's own outer `box-shadow`, spread far enough
  (`100vmax`) to reach every corner from any hole position. It must be a single element:
  tiled panels around the hole have exact seams once settled, but each animates a different
  property from a different start value, so mid-morph they drift apart and leak a visible
  line along the hole. One element cannot seam against itself. An unanchored step has no
  hole to cut, so a single full-viewport veil dims it instead.
- **Click-blocking is four transparent panels** around the hole, because a `box-shadow` is
  not hit-tested. They give REQ-8 its pointer semantics — clicks outside the hole are
  swallowed, clicks inside reach the real element — and being invisible they need no
  transition, so nothing can drift.
- Hole edges are rounded to whole pixels **before** width and height are derived from them,
  so the panels and the spotlight cannot disagree about where the boundary is. Rounding each
  of top/left/width/height independently instead leaves them a pixel apart whenever the
  target sits on a fractional offset — which, with rem-based spacing, is most of the time.

Geometry is emitted only as inline `:style` in pixels; chrome is literal Tailwind classes in the
Blade partial. No class name is ever computed, so nothing depends on the purge scanner.

Robustness rules the engine must hold:

- Visibility of the overlay is `x-show` plus an inline `display: none`, **never `x-cloak`**.
  The partial lives in the base layout, and the E2E harness waits for `[x-cloak]` to reach zero
  — an `x-data` that throws would otherwise hang the entire browser suite rather than one spec.
  `layouts/navigation.blade.php` already uses this pattern.
- `reveal` speaks only DOM: `'scroll-top'`; `{hover: testid}` dispatched as
  `pointerover`/`mouseover`/`mouseenter` constructed with `bubbles: true`, because `mouseenter`
  does not bubble natively and must reach the wrapper that owns the submenu state; and
  `{click: testid, until?: testid}`.
- **A click reveal needs a postcondition**, because the controls it drives are toggles — a
  blind click closes what the previous step opened. `until` names what the click should
  produce, and the click is skipped when that is already visible. Omitted, the step's own
  `target` is assumed, which is right when the reveal opens the very thing being pointed at
  (a collapsed panel). It is *wrong* when a step points at the control it also presses: a
  view-mode button is always visible, so the check would pass and the click would never
  fire. That is the case the three view-mode steps need, and why `until` exists.
- The nav is pinned while a demo runs by a body class in `resources/css/app.css`, not by
  reaching into the nav's own Alpine state.
- Repositioning is one requestAnimationFrame-coalesced pass fed by `ResizeObserver`,
  `IntersectionObserver`, passive capture `scroll`/`resize`, a `MutationObserver` on the
  target's parent (Alpine `x-for` can replace the node, so the target is re-resolved by testid),
  and a capture-phase `load` listener for lazy images.
- Dark mode is a global `filter: invert(1)` on `<html>` with a counter-invert allowlist.
  Inversion commutes with compositing, so translucent black dimming would render as a white
  wash: the spotlight (whose shadow *is* the dimming) and the veil join that allowlist —
  inverting twice is identity, shadow included. The popover deliberately does **not**, so it
  inverts into a dark card like every other panel and needs no dark-mode-specific CSS.
- No focus trap. A trap would make "type in the real search box" impossible; instead the
  popover takes focus on passive steps and the target takes it on interactive ones, with
  `role="dialog"`, `aria-modal`, and `aria-live="polite"` on the body. `Escape` skips; arrow
  keys step but are ignored while an input, textarea, select or contenteditable has focus;
  `Enter` is never bound, because the grid's search box already owns it.

### Testids

The overlay owns the `demo-` prefix and shares nothing with the carousel's `tour-*` family:
`demo-overlay`, `demo-spotlight`, `demo-popover`, `demo-title`, `demo-body`, `demo-step`,
`demo-steps`, `demo-prev`, `demo-next`, `demo-skip`, `demo-finish`, `demo-goto`,
`demo-outro`, `demo-next-demo`, `demo-start`.

`demo-overlay` also carries the state channels, all **string**-valued because Alpine removes an
attribute bound to `false`: `data-active`, `data-demo`, `data-step`, `data-target`,
`data-awaiting`, `data-placement`, `data-missing`, `data-settled`. Two of them carry the weight:

- **`data-target`** — the testid currently spotlit. It lets a reader (or a browser test) know
  *what* is highlighted without pixel maths or a hardcoded step number.
- **`data-settled`** — false from entering a step until its target is resolved and the geometry
  written. Resolution is asynchronous (`reveal`, then the hydration poll), so without this
  there is no way to distinguish "still looking for the target" from "this step has none", and
  anything reading `data-target` too early sees the previous step's value.

## Scenarios (BDD)

```gherkin
Scenario: A demo step names a target that exists in the views
  Given every demo registered in the DemoRegistry
  When each step's target and reveal testids are looked up in resources/views
  Then every one of them is rendered somewhere by a Blade file
# pinned by: tests/Feature/GuidedDemoTest.php

Scenario: A demo step names a route that exists
  Given every demo registered in the DemoRegistry
  When each step's routeName is resolved
  Then the route is registered and route() returns a URL
# pinned by: tests/Feature/GuidedDemoTest.php

Scenario: A named successor resolves to a registered demo
  Given a demo whose nextDemoId is not null
  When the registry looks the successor up
  Then a registered demo is found
# pinned by: tests/Feature/GuidedDemoTest.php

Scenario: No demo in the URL ships no payload
  Given an authenticated user
  When they open a page with no demo parameter
  Then the response contains no guided-demo payload and no overlay element
# pinned by: tests/Feature/GuidedDemoTest.php

Scenario: An out-of-range step is clamped rather than rejected
  Given the Welcome demo
  When the payload is built for step 999 and then for step -1
  Then the resume index is the last step and then the first
# pinned by: tests/Feature/GuidedDemoTest.php

Scenario: A demo the viewer may not play does not boot
  Given a demo whose isAvailableTo returns false for the viewer
  When they open a page naming that demo
  Then no payload is rendered
# pinned by: tests/Feature/GuidedDemoTest.php

Scenario: Completing a demo records it against the user
  Given an authenticated user who has not finished the Welcome demo
  When they post to demos.complete for it
  Then the response is 200
  And guided_demos.welcome.completed_at is stored in their preferences
# pinned by: tests/Feature/GuidedDemoTest.php

Scenario: Completing a demo leaves the user's other preferences alone
  Given a user with a home folder, results-per-page, dark mode and locale set
  When they complete a demo
  Then all four of those preferences still hold their original values
# pinned by: tests/Feature/GuidedDemoTest.php

Scenario: An unknown or ungated demo cannot be marked complete
  Given an authenticated user
  When they post to demos.complete for an unregistered id
  Then the response is 404
  And for a demo they may not play the response is 403
# pinned by: tests/Feature/GuidedDemoTest.php

# — browser-level (see e2e-testing.md for the harness) —

Scenario: The launcher starts the Welcome demo on its first step
  Given the dashboard
  When the demo launcher is activated
  Then the overlay reports itself active and spotlights the first step's target
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: Stepping forwards and backwards tracks the counter
  Given the Welcome demo on its first step
  When next is clicked and then back
  Then the reported step goes forward one and back one, against the total the page reports
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: The spotlight follows the real element
  Given a demo step anchored to a grid control
  When the step is shown
  Then the highlight is centred on that element's bounding box
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: The demo crosses from the dashboard to the library and resumes there
  Given the Welcome demo on its last dashboard step
  When the hand-off is taken
  Then the browser is on the assets index
  And the overlay is still running the same demo, on the first library step
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: The three view-mode steps switch the library into each view in turn
  Given the Welcome demo on the assets index
  When it reaches the grid, masonry and list steps
  Then the library is showing that view as each step is read
  And the list step's inline tag and licence controls are on screen when it claims them
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: The demo covers the upload screen and brings the reader back
  Given the Welcome demo on the library's upload step
  When it continues
  Then it is on the upload screen, pointing at the folder, the keep-filename choice and
    the batch metadata panel in turn
  And continuing from the last of those returns to the library on the closing step
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: An interactive step advances when the user performs the action
  Given the step anchored to the grid search box
  When the user types into the real search box, touching no demo control
  Then the step stops awaiting and the demo advances
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: A shared link opens the demo part-way through
  Given a link naming a demo and a step on the assets index
  When it is opened directly
  Then the overlay opens on that step without replaying the earlier ones
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: A link that lands on the wrong page offers to go to the right one
  Given a link naming a step that belongs to the assets index
  When it is opened on the tags page instead
  Then a centered hand-off card is shown instead of a spotlight
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: Escape abandons the demo
  Given a running demo
  When Escape is pressed
  Then the overlay stops, and reloading the page does not bring it back
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: Finishing the demo records it
  Given the Welcome demo on its last step
  When done is clicked
  Then the overlay closes and the completion is stored against the user
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: A finished demo does not follow the reader onto the next page (REQ-6a)
  Given the Welcome demo finished on its last step, on the assets index
  When an asset is opened from the grid the demo just closed on
  Then the browser is on the asset detail page with no demo parameters
  And no overlay is rendered at all
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: A step whose target is absent is skipped without breaking the page
  Given the library filtered so no asset cards are rendered
  When the demo reaches the step anchored to a card
  Then the demo continues and no page error is raised
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: A nav step stays visible even though the nav auto-hides on scroll
  Given the page scrolled far enough that the nav has hidden itself
  When the demo reaches a step anchored to a nav item
  Then the nav is pinned back into view and the highlight matches the item
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: The overlay is inert when no demo is running
  Given any page opened without a demo parameter
  When it has hydrated
  Then the overlay reports itself inactive, the page is fully interactive, and nothing threw
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: The Welcome demo is playable by a non-admin
  Given an editor session
  When they play the Welcome demo to the end
  Then every step resolves and no step points at an admin-only control
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: The dashboard carousel offers the Welcome demo
  Given the dashboard feature tour
  When its demo slide is shown
  Then its call to action links to the dashboard with the Welcome demo armed
# pinned by: tests/e2e/guided-demo.spec.js
```

## Tests & verification

- Feature: `tests/Feature/GuidedDemoTest.php` — the registry's integrity (every target and
  reveal testid exists in the views, every route resolves, every successor is registered),
  payload gating and step clamping, and the completion endpoint including the regression that
  it must not disturb the user's other preferences.
- E2E: `tests/e2e/guided-demo.spec.js` — the engine in a browser: launch, stepping,
  spotlight geometry, the cross-page hand-off, act-to-advance, deep links, the nav pin, the
  absent-target path, the REQ-6a regression that a finished demo does not reappear on the
  next page opened, and the inert-when-idle boot check that protects the rest of the suite.
- Style: `./vendor/bin/pint --test`
- `php artisan config:clear && php artisan test`, then `npm run build` and
  `npm run test:e2e -- tests/e2e/guided-demo.spec.js`, then the full `npm run test:e2e`.
- Manual check: walk `/dashboard?demo=welcome` in both locales and all three dark-mode
  preferences — the global `filter: invert` is the one thing no assertion covers well.

## Open questions / future

- Admin-only demos are the reason `isAvailableTo` and `nextDemoId` exist, but none is written
  yet. `WelcomeDemo::nextDemoId()` names a demo that is not registered, which the registry
  handles by offering nothing.
- No demo auto-starts for a new user. Doing so would need a suppression check *and* seeded
  preferences in `database/seeders/E2eSeeder.php`, or the overlay would cover the dashboard and
  grid for the whole browser suite.
- There is no org-wide switch to disable demos. If one is ever wanted, the hook is
  `DemoRegistry::all()` plus a `Setting` row.
- **Demo copy restates behaviour other specs own, and nothing checks it.** The upload steps
  quote real thresholds and consequences — 500 MB per file, chunking above 10 MB, an
  overwritten object when a kept filename collides — which belong to
  [`asset-upload.md`](asset-upload.md), [`chunked-upload.md`](chunked-upload.md) and
  [`upload-policy.md`](upload-policy.md). This is product prose rather than documentation, so
  the "one home per fact" rule does not forbid it, but a change to any of those limits makes
  the demo quietly wrong. The integrity test proves a step's *target* and *route* exist; it
  cannot prove its sentences are still true.
- **The `sessionStorage` breadcrumb has no test.** No step in the Welcome demo currently
  advances on a control that navigates by itself, so the recovery path — breadcrumb written
  in the capture phase, then one `location.replace` to re-arm the URL — is exercised by
  nothing. A step with `advanceOn` on the grid's folder or type filter would cover it, and
  would be worth adding before relying on that path.
- **A script-driven navigation logs "Transition was skipped".** `app.css` opts into
  cross-document view transitions, and a navigation that does not come from a user
  activating a link has its transition skipped; Chromium reports the rejected promise as an
  unhandled error. The demo hand-off is one of nine such navigations in the app
  (`assetGrid.applyFilters` and the asset-detail prev/next among them), so this is app-wide
  and predates demos — but it is console noise on every cross-page hand-off, and
  `tests/e2e/guided-demo.spec.js` has to filter it to assert a clean console at all.
- `reveal` covers scroll, hover and click. A step targeting something behind a multi-step
  disclosure (a modal inside a panel) has no primitive yet.
- The mobile nav gained testids for this feature, but the demo says less on a phone: the
  desktop nav steps resolve to their mobile counterparts only when the responsive menu is open.
