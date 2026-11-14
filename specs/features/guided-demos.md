# Guided Demos

```yaml
id: guided-demos
status: implemented
version: 3
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

The dashboard's feature **tour** ([`dashboard.md`](dashboard.md)) *describes* capabilities;
nothing showed a user *where things are* on the page in front of them. A **guided demo**
anchors to the live DOM: it dims the page, cuts a hole around one real element, explains it
in an anchored popover, and moves on. It can cross pages (Welcome starts on `/dashboard` and
continues on `/assets`), ask the user to actually do the thing, and hand off to another demo.
Demos are declared server-side so copy goes through `__()`, links through `route()`, and
availability through a role gate ([ADR-015](../decisions/adr-015-guided-demos-server-declared.md)).
"Tour" is taken by the carousel; this feature is a **demo** throughout (`?demo=`, `demo-*`
testids, `app/Demos/`).

## Requirements

- **REQ-1** — A demo is a PHP class implementing `App\Demos\Demo`, registered explicitly in
  the `App\Demos\DemoRegistry` singleton. Adding one is a new class plus one registry line;
  it must require no edit to any existing demo.
- **REQ-2** — A step targets an element by its **`data-testid` value**, never by a CSS
  selector. `target` accepts a single value or an ordered list; the first *visible*
  candidate wins. Why: desktop/mobile duplicates and the three grid view modes are
  unknowable server-side.
- **REQ-3** — Step copy is `__()`-translated and step links are resolved with `route()` at
  request time, so a demo renders in `en` and `nl` ([`localization.md`](localization.md)) and
  survives `route:cache`. Neither may be resolved at definition time.
- **REQ-4** — `Demo::isAvailableTo(User)` gates a whole demo. An unavailable demo is not
  listed, does not boot when named in the URL, and cannot be marked complete. A step may
  only target elements every role the demo is available to can see.
- **REQ-5** — A demo never starts on its own. It runs only when the URL carries `?demo=<id>`
  or the user activates a launcher. No auto-start, no nag.
- **REQ-6** — Position is held in the URL (`?demo=<id>&demoStep=<n>`), which is also a
  shareable "start here" link. The server clamps `demoStep` into range and renders the
  payload already positioned. Within a page, navigation uses `history.replaceState`, never
  `pushState`, so Back costs one press per page rather than one per step.
- **REQ-6a** — `demo` and `demoStep` are the demo's own state; no other feature may
  propagate them. A link, redirect or session stamp built from "the current query string"
  must exclude them (concrete case: the grid's card links and `assets_return_url`,
  [`asset-cycle-navigation.md`](asset-cycle-navigation.md) REQ-7). Why: `stop()` cannot
  scrub server-rendered links, so a finished demo would reappear as a REQ-7 hand-off card.
- **REQ-7** — When the resolved step belongs to a different route than the current one, the
  overlay renders a centered hand-off card whose primary action navigates there. This covers
  cross-page continuation, a shared link landing on the wrong page, and Back-button drift.
- **REQ-7a** — A demo that leaves its starting page announces it on the step anchored to the
  control leading there, and ends where the reader can carry on working. Welcome's dashboard
  block ends on the Assets nav item; it announces the upload screen on the step that opens it
  and closes back on the library, stating that nothing was uploaded.
- **REQ-8** — A step may require a real user action (`advanceOn`) and advance when it happens.
  The engine observes DOM events on the target in the **capture** phase only and knows nothing
  of the owning Alpine module. `Next` stays enabled, so declining never traps the user.
- **REQ-8a** — A step may put the page into the state it describes (`reveal`). The library's
  three view modes are one step each, each switching the grid into its own view.
- **REQ-9** — A missing target is not an error. The engine waits briefly for hydration, then
  skips the step (`fallback: skip`) or renders it as a centered card (`fallback: center`).
  Nothing in the resolve/position path may throw.
- **REQ-10** — A demo may name a successor (`nextDemoId`). The successor is gated through
  REQ-4, so the offer appears only for a user who can play it; otherwise the demo just ends.
- **REQ-11** — Finishing or dismissing a demo is persisted per user under `guided_demos` in
  `users.preferences` ([`user-preferences.md`](user-preferences.md)). The write is
  fire-and-forget: a failed save ends the demo normally and reports nothing. Step *position*
  is never persisted server-side.
- **REQ-12** — The overlay ships on every authenticated page from the base layout, but renders
  **nothing** — no DOM node, no script — when no demo is armed.
- **REQ-13** — A demo is steppable from the keyboard alone, across page boundaries. Arrow keys
  step it from anywhere; on *entering* a page the overlay focuses the step's primary action
  (Next, Done, or the REQ-7 hand-off button) so `Enter` continues. Focus is taken **once per
  document**, never on a step awaiting a user action (REQ-8).

## Technical design

### Contract / public interface

- `App\Demos\Demo` — `id()`, `title()`, `description()`, `isAvailableTo(User)`, `steps(User)`,
  `nextDemoId()`.
- `App\Demos\DemoStep` — a `final readonly class` (a mistyped key is a PHP error);
  `toArray()` resolves `route()` into the wire `url`.
- `App\Demos\DemoRegistry` — `find(id)`, `get(id, User)` (null when gated out), `all(User)`,
  `next(Demo, User)`, `payload(id, User, step, currentRoute)`. Built with an explicit array in
  `AppServiceProvider::register()` — no auto-discovery.
- `App\Demos\WelcomeDemo` — the first demo; available to every known role.
- `GuidedDemoController::complete` — `POST /demos/{demo}/complete` (`demos.complete`, `auth`
  group). Unknown → 404; not available to caller → 403; else 200 `{message, completed: [ids]}`.
- `guidedDemo()` (`resources/js/alpine/guided-demo.js`) — reads `window.__pageData.guidedDemo`.
  Methods: `next()`, `prev()`, `goToStep(n)`, `goToPage()`, `skip()`, `finish()`,
  `startNext()`, `stop()`, `reposition()`, `focusPrimary()`.
- `demo-geometry.js` — a pure-function mixin (not a registered module): `holeRect`,
  `ringStyle`, `popoverStyle`, `shutterStyle`, `isVisible`, `fixedAncestor`.
- Testids: the overlay owns the `demo-` prefix (`demo-overlay`, `demo-spotlight`,
  `demo-popover`, `demo-title`, `demo-body`, `demo-step`, `demo-steps`, `demo-prev`,
  `demo-next`, `demo-skip`, `demo-finish`, `demo-goto`, `demo-outro`, `demo-next-demo`,
  `demo-start`). `demo-overlay` exposes string-valued state: `data-active`, `data-demo`,
  `data-step`, `data-target` (the testid spotlit), `data-awaiting`, `data-placement`,
  `data-missing`, `data-settled` (false until the step's target is resolved and positioned).

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
guidedDemo.ui: map                     # translated chrome, registry-owned, shared by every demo
guidedDemo.steps: list                 # DemoStep::toArray(), plus a resolved 'url'

# users.preferences['guided_demos'] — one entry per completed demo
guided_demos.<demo-id>.completed_at: string   # ISO-8601
guided_demos.<demo-id>.dismissed: bool        # true ⇒ skipped rather than finished
```

### Layer touchpoints & ordering

- `layouts/guided-demo.blade.php` is included by `layouts/app.blade.php` only (not
  `layouts/embed.blade.php`). It calls `DemoRegistry::payload()` directly and emits nothing
  when that is null (REQ-12). It writes the payload namespaced
  (`window.__pageData = window.__pageData || {}; window.__pageData.guidedDemo = …`), because
  `dashboard.blade.php` assigns the whole `window.__pageData` object.
- Each page boots independently: server renders the payload positioned → Alpine starts → the
  engine resolves the target. Against app controls that navigate on their own and drop the
  query string, the engine writes a `sessionStorage` breadcrumb synchronously in its
  capture-phase handler and, on a boot with a breadcrumb but no `?demo=`, re-arms the URL with
  one `location.replace`.
- Rendering rules: the target element is never mutated (the nav is a stacking context). Dimming
  is one element (the spotlight's `box-shadow`, or a full-viewport veil when unanchored);
  click-blocking is four transparent panels around the hole; hole edges are rounded to whole
  pixels before width/height are derived. Geometry is inline `:style` only; no computed class
  names. Overlay visibility is `x-show` plus inline `display: none`, **never `x-cloak`** (the
  E2E harness waits for `[x-cloak]` to reach zero). A click `reveal` is skipped when its `until`
  testid (default: the step's target) is already visible. The nav is pinned by a body class in
  `resources/css/app.css`. In dark mode the spotlight and veil join the counter-invert
  allowlist; the popover does not. No focus trap; `Escape` skips; arrow keys are ignored while a
  form field has focus; `Enter` is never bound by the engine.

### Persistence

- **Persisted:** completion only — `users.preferences['guided_demos']`, written by
  `GuidedDemoController::complete` as a whole-map rebuild plus one `User::setPreference`
  (shallow write).
- **Not persisted:** the step cursor (URL only, with the `sessionStorage` breadcrumb as a
  same-tab backstop). No `Setting` row — there is no org-wide "demos off" switch.

## Scenarios (BDD)

```gherkin
Scenario: Every registered demo is internally consistent
  Given every demo registered in the DemoRegistry
  When each step's target/reveal testids, routeName and nextDemoId are resolved
  Then every testid is rendered by a Blade file, every route resolves,
    and every named successor is a registered demo
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

Scenario: Completing a demo records it and leaves other preferences alone
  Given a user with a home folder, results-per-page, dark mode and locale set
  When they post to demos.complete for the Welcome demo
  Then the response is 200 and guided_demos.welcome.completed_at is stored
  And all four other preferences keep their original values
# pinned by: tests/Feature/GuidedDemoTest.php

Scenario: An unknown or ungated demo cannot be marked complete
  Given an authenticated user
  When they post to demos.complete for an unregistered id
  Then the response is 404
  And for a demo they may not play the response is 403
# pinned by: tests/Feature/GuidedDemoTest.php

# — browser-level (see e2e-testing.md for the harness) —

Scenario: The launcher starts the demo and the counter tracks stepping
  Given the dashboard
  When the demo launcher is activated, then next and back are clicked
  Then the overlay spotlights the first step's target, centred on its bounding box,
    and the reported step goes forward one and back one
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: The demo crosses pages and keeps the keyboard working
  Given the Welcome demo on its last dashboard step, anchored to the Assets nav item
  When the hand-off is taken
  Then the browser is on the assets index, running the same demo on the first library step
  And the step's primary action holds focus, so Enter alone advances the demo
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: Reveal steps put the page in the state they describe
  Given the Welcome demo on the assets index
  When it reaches the grid, masonry and list steps, then the upload steps
  Then the library shows each view in turn, with the list's inline controls on screen
  And the upload screen's folder, keep-filename and metadata steps follow,
    returning to the library on the closing step
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: An act-to-advance step waits for the real action without taking focus
  Given a link opening the demo on the step anchored to the grid search box
  When the page hydrates and the user types into the real search box
  Then the demo did not take focus, and the step stops awaiting and advances
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: Deep links open part-way, or hand off when on the wrong page
  Given a link naming a demo step on the assets index
  When it is opened directly, and again on the tags page
  Then the first opens on that step; the second shows a centered hand-off card
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: Escape abandons and Done records the demo
  Given a running demo
  When Escape is pressed, the overlay stops and a reload does not bring it back
  And when Done is clicked on the last step, the completion is stored against the user
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: A finished demo does not follow the reader onto the next page (REQ-6a)
  Given the Welcome demo finished on its last step, on the assets index
  When an asset is opened from the grid
  Then the asset detail page has no demo parameters and no overlay
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: Absent targets and hidden nav do not break the demo
  Given the library filtered so no asset cards render, or the nav hidden by scrolling
  When the demo reaches a card step or a nav step
  Then the card step is skipped with no page error, and the nav is pinned back into view
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: The overlay is inert when no demo is running
  Given any page opened without a demo parameter
  When it has hydrated
  Then the overlay reports itself inactive, the page is interactive, and nothing threw
# pinned by: tests/e2e/guided-demo.spec.js

Scenario: The Welcome demo is playable by a non-admin and offered by the carousel
  Given an editor session
  When they play the Welcome demo to the end
  Then every step resolves and none points at an admin-only control
  And the dashboard tour's demo slide links to the dashboard with the demo armed
# pinned by: tests/e2e/guided-demo.spec.js
```

## Tests & verification

- Feature: `tests/Feature/GuidedDemoTest.php` — `php artisan config:clear && php artisan test`
- E2E: `tests/e2e/guided-demo.spec.js` — `npm run build && npm run test:e2e -- tests/e2e/guided-demo.spec.js`
- Style: `./vendor/bin/pint --test`
- Manual: walk `/dashboard?demo=welcome` in both locales and all three dark-mode preferences
  (the global `filter: invert` is not covered by assertions).

## Open questions / future

- No admin-only demo exists yet; `WelcomeDemo::nextDemoId()` names an unregistered demo, so
  nothing is offered.
- Auto-start for new users would need a suppression check and seeded preferences in
  `database/seeders/E2eSeeder.php`.
- Demo copy quotes facts owned elsewhere (500 MB limit, 10 MB chunking, filename collisions —
  [`asset-upload.md`](asset-upload.md), [`chunked-upload.md`](chunked-upload.md),
  [`upload-policy.md`](upload-policy.md)); nothing checks the prose. Worth automating?
- The `sessionStorage` breadcrumb recovery has no test; an `advanceOn` step on the grid's
  folder/type filter would cover it.
- Script-driven navigations (including the hand-off) log "Transition was skipped" from
  cross-document view transitions (Chromium 159+: "Transition was aborted because of invalid
  state. ViewTransition opt-in disabled"); `guided-demo.spec.js` and `tools.spec.js` filter both
  wordings. App-wide, not demo-specific.
- `reveal` has no primitive for multi-step disclosures (a modal inside a panel).
- On mobile, desktop nav steps resolve to mobile counterparts only when the menu is open.
