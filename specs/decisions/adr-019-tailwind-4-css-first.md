# ADR-019 — Tailwind v4 with CSS-first configuration, keeping v3's visual defaults

```yaml
id: adr-019-tailwind-4-css-first
status: accepted
date: 2026-10-14
deciders: core
related:
  - ../architecture
  - ../features/security-invariants
  - adr-007-blade-alpine-over-spa
```

## Context / Forces

The UI is Blade + Alpine styled with Tailwind v3.4 ([ADR-007](adr-007-blade-alpine-over-spa.md)).
Tailwind v3 has stopped being a place to stay. Two advisories reached the build only through it:
`postcss-selector-parser` < 7.1.6, now forced through an npm override, and `braces` ≤ 3.0.3
(GHSA-vfj7-8cjw-p6xm), which has **no patched release**. `braces` is ignored in the CI npm audit
under [security-invariants.md](../features/security-invariants.md) REQ-8, and that ignore is
explicitly temporary. Dependabot's own fix for the first advisory was a bare `tailwindcss` bump to v4
(#81, closed), which broke the build: v4 is not a drop-in upgrade.

Tailwind v4 changes how a project is configured and what several classes mean:

- **Configuration:** the theme moves from `tailwind.config.js` into CSS (`@theme`), plugins load with
  `@plugin`, and the PostCSS integration moved to separate packages.
- **Renames:** `rounded`, `shadow`, `blur`, `outline-none`, bare `ring` and others now mean
  something different.
- **Defaults:** a bare `border` becomes the text colour instead of gray-200, `ring` becomes 1px
  `currentColor` instead of 3px blue-500, placeholders and button cursors change, and `hover:`
  applies only on devices that can hover.
- **Palette:** colours are redefined in OKLCH.
- **Browsers:** v4 relies on cascade layers, `@property` and `color-mix()`.

Measured before deciding, across 243 templates and scripts: about 430 uses of renamed classes,
474 bare `border`s in 43 files, 176 `ring-*`s and 556 `hover:`s.

## Decision

Move to Tailwind v4, configured entirely in CSS, and keep Tailwind v3's visual defaults wherever
templates depend on them, so the migration changes no pixels it does not have to.

- **Build:** `@tailwindcss/vite` in `vite.config.js`. `tailwind.config.js`, `postcss.config.js`,
  `autoprefixer` and the direct `postcss` dependency go; Lightning CSS, inside Tailwind, handles
  prefixing.
- **Configuration:** all of it lives in `resources/css/app.css`.
  - `@import "tailwindcss" source(none)` with explicit `@source` lines mirroring v3's `content`
    globs (`resources/views`, `resources/js`, `app/Models`), so detection does not widen to
    Markdown, tests or vendor files.
  - `@plugin "@tailwindcss/forms"`.
  - `@theme` holds the tokens: Figtree as `--font-sans`, the six `orca-*` colours, and
    `--breakpoint-xxl` at 1680px.
- **Compatibility with v3:**
  - A base layer restores v3's border colour (gray-200), placeholder colour (gray-400) and pointer
    cursor on buttons.
  - `--default-ring-width: 3px` and `--default-ring-color` (blue-500) restore v3's rings.
  - Renamed classes are rewritten to their v4 names (`rounded` → `rounded-sm` and so on) by
    `@tailwindcss/upgrade`, with every change reviewed.
- **ORCA grayscale theme:** the inline `addUtilities` plugin becomes a plain CSS rule applying
  `grayscale(0.65)` to the same 96 colour classes.
- **Accepted from v4:** `hover:` applies only on devices that can hover, and the OKLCH palette.
- **Supported browsers:** Safari 16.4+, Chrome and Edge 111+, Firefox 128+.
- **Verification:** before/after full-page screenshots of every page and key UI state at 1440px and
  390px, diffed pixel by pixel against a baseline taken on Tailwind v3.

## Alternatives considered

- **Stay on Tailwind v3, with `braces` ignored indefinitely.** Rejected because no fix will ever
  arrive on that path: v3's dependency tree pins the vulnerable packages and will collect more like
  them. REQ-8 lets an advisory be ignored only with a reason and a way out, and this is the way out.
- **Tailwind v4 with the legacy JS config (`@config "tailwind.config.js"`).** Rejected because it
  keeps two sources of configuration and leaves the theme out of the CSS variables v4 exposes. The
  upgrade guide presents it as a stepping stone, not a destination.
- **`@tailwindcss/postcss` instead of the Vite plugin.** Rejected because the Vite plugin is faster
  and is the recommended integration for a Vite build. It also removes the PostCSS config and
  `autoprefixer`, while the PostCSS route would keep both.
- **Accept v4's defaults wholesale.** Rejected for now because 474 bare `border`s and every
  colourless `ring` would change colour in one sweep, mixed in with the renames. Keeping v3's
  defaults makes the screenshot diff readable, and individual elements can drop them later.
- **Pin v3's hex palette in `@theme`.** Rejected unless the screenshot diff shows an unacceptable
  shift. It means maintaining a copy of 22 colour scales, and the grayscale filter already mutes
  most of the difference on the affected hues.
- **Desaturate the colour tokens instead of filtering elements.** Deferred, not rejected. It is
  cleaner, because a filter also greys an element's children, but it changes the look, so it does
  not belong in a migration meant to change nothing visible.

## Consequences

- **Good:**
  - `braces` leaves the dependency tree, so the npm audit allowlist empties and its stale-entry
    check forces the entry out.
  - The `postcss-selector-parser` override, `autoprefixer` and `postcss` go too.
  - Builds get faster, and the theme becomes CSS variables that `app.css` can use directly.
- **Trade-off:**
  - Browsers older than the supported baseline get broken styling. That includes editors who load
    the embedded asset picker inside a CMS on an old browser.
  - The compatibility layer is v3 behaviour that new templates inherit until it is removed.
  - Touch devices lose `hover:` styles, and colours shift slightly.
  - Tailwind v4's engine is a native binary, so the build host needs a supported platform and Node
    20 or newer. CI runs Node 22.
