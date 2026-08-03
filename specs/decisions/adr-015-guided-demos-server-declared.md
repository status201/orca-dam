<!--
  Why guided demos are declared in PHP and rendered by our own code rather than
  defined in JS on top of a third-party tour library.
-->

# ADR-015 — Guided demos are declared server-side and rendered by hand

```yaml
id: adr-015-guided-demos-server-declared
status: accepted
date: 2026-08-03
deciders: core
related:
  - ../features/guided-demos
  - ../features/localization
  - ../architecture
```

## Context / Forces

Onboarding needed interactive walkthroughs that highlight real controls on real pages
([`guided-demos.md`](../features/guided-demos.md)). Two questions had to be settled before any
code: **who declares a demo**, and **who draws the spotlight**.

Three existing constraints pulled the answer. `TranslationIntegrityTest` extracts every `__()`
literal from `app/` and `resources/views/` and requires a `lang/nl.json` entry — it does *not*
scan `resources/js/**` or `config/**`, so copy declared there escapes the only check that keeps
the Dutch UI honest ([ADR-009](adr-009-project-owns-nl-json.md)). Demo links must not point a
role at a page it will be refused from, which needs the authenticated user
([`authorization-policies.md`](../features/authorization-policies.md)). And the pages a demo
anchors to are hostile to a generic overlay: the nav is fixed, auto-hides on scroll and owns its
own Alpine state; submenus are hover-driven; dark mode is a global `filter: invert(1)` with a
hand-curated counter-invert allowlist; and every browser assertion in the repo locates by
`data-testid`.

## Decision

**Demos are declared as PHP classes in `app/Demos/` behind the `Demo` interface, resolved by an
explicitly-constructed `DemoRegistry`; the spotlight and popover are our own Alpine module plus a
geometry mixin, with no tour library.**

A step names a `data-testid` value and a route name; `DemoStep::toArray()` resolves `route()` at
request time. `resources/views/layouts/guided-demo.blade.php` serialises the active demo into
`window.__pageData.guidedDemo` and renders the overlay root; `resources/js/alpine/guided-demo.js`
drives it. This keeps `__()`, `route()` and the role gate on the server, and keeps the renderer
somewhere we can make it obey the nav, the invert filter and the testid convention.

## Alternatives considered

- **`config/demos.php`** — rejected outright, not merely preferred against. `config:cache`
  evaluates the file once and serialises the result, so `__()` freezes one locale into the cache
  and the Dutch UI would silently render English demo copy; a role gate needs a closure, which
  `config:cache` refuses to serialise; and `config/` is outside the paths
  `TranslationIntegrityTest` scans, so the omission would never be caught.
- **Defining demos in JS** (`resources/js/demos/*.js`) — rejected: no `__()`, no `route()`, no
  access to the user, so paths would be hardcoded and a step could point a role at a page it is
  refused from. `resources/js/**` is also unscanned for translations.
- **`app/Services/DemoRegistry.php`** — rejected: a registry is not a service in this repo's
  sense ([ADR-001](adr-001-service-layer.md), [ADR-010](adr-010-services-swallow-controllers-map.md)
  — services wrap external integrations and swallow + log), and `app/Services/` carries a
  hand-counted total plus a mandatory entry in the one file tree the spec lint validates.
- **driver.js** (and shepherd.js, intro.js) — rejected: it creates its own DOM with its own
  stylesheet, which has to be fought through the global invert and the grayscale plugin; it
  offers no `data-testid` hooks, so they would have to be bolted on from render callbacks; its
  step copy is passed as strings, so `__()` has to be plumbed through a data channel anyway,
  meaning the library saves no translation work; and none of them resume across a page
  navigation, which is the feature's central requirement. Our version is smaller than driver.js
  once written.
- **`@floating-ui/dom` for positioning only** — rejected: four placements plus a viewport clamp
  is a short pure function, and `computePosition` being async is awkward inside an Alpine style
  binding. Not worth a dependency whose version the spec lint would then police in every doc
  that mentions it.
- **A policy class for demo availability** — rejected: this repo's policies are model-shaped and
  a demo is not a model. Eligibility is a property of the demo, so it lives on the demo as
  `Demo::isAvailableTo()`.

## Consequences

- **Good:** demo copy is caught by the existing translation gate; links cannot 403 because they
  are built from `route()` for a known user; a new demo is one class and one registry line and
  touches no existing demo; the renderer can be made to obey the auto-hiding nav, the invert
  filter and the testid convention because we own it; and no dependency version enters the docs.
- **Good:** because the definition lives on the server, position can live in the URL — which
  makes a demo link shareable, makes the first paint already correct, and lets the browser suite
  jump straight to any step.
- **Trade-off:** the spotlight geometry, reveal primitives and reposition observers are ours to
  maintain, including the browser-quirk corners a library would have absorbed.
- **Trade-off:** a step's `data-testid` is a *reference* to a string that is authoritative in
  Blade. Nothing in the type system connects them, so `tests/Feature/GuidedDemoTest.php` has to
  assert the correspondence — the same "two copies, one check" pattern
  `TranslationIntegrityTest` and `scripts/spec-lint.mjs` already use.
- **Trade-off:** demo availability is gated outside `app/Policies/`, so the role matrix in
  [`authorization-policies.md`](../features/authorization-policies.md) is not the whole story for
  this feature.
