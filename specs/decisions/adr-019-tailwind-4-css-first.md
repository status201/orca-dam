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
  - A base layer restores v3's border colour (gray-200) and pointer cursor on buttons.
    Placeholders need nothing: `@tailwindcss/forms` sets their colour after preflight, as in v3.
  - `--default-ring-width: 3px` and `--default-ring-color` (blue-500) restore v3's rings.
  - **`space-x-*` / `space-y-*` keep v3's selector.** v4 puts the gap on the end of every child
    but the last, at zero specificity. That leaves a stray gap after the last visible child
    whenever later siblings are hidden by `x-show` or are an `<x-modal>`. It also drops the gap
    v3 put before an `x-for` `<template>`'s first item, and lets a child's own `mt-*` add to the
    gap. A same-name `@utility` re-adds v3's start margin with v3's selector and specificity, and
    a zero-specificity rule emitted after the generated utilities cancels v4's end margin.
  - **`transform` stays a real identity transform.** In v3 it was `translate(0,0) rotate(0) …`,
    and templates rely on what any non-`none` transform does: a stacking context, painting in
    the positioned layer, and being the containing block for `fixed` children. v4's `transform`
    composes rotate-x/y/z and skew variables, which nothing here sets, so it computes to `none`.
    The replace page's confirmation panel then fell behind its own `fixed` backdrop, which the
    E2E suite caught and no static screenshot could. A plain `.transform` rule after the
    generated utilities restores the identity transform for all 67 uses.
  - **Hand-written CSS sits last in `@layer utilities`**, not unlayered. In v4, unlayered CSS
    outranks every layer regardless of specificity. Last in the utilities layer is where v3's
    specificity-and-source-order contest with the utilities happened.
  - **Font Awesome is bundled from the npm package into `@layer fontawesome`, ordered below all
    of Tailwind's layers.** Loaded unlayered from cdnjs, its `line-height: 1` on icons beat the
    line-height that `text-2xl` and similar classes set, shrinking icon boxes in 29 templates. A
    `<link>` cannot be put in a layer, and `@import` of the CDN URL would lose the SRI hash, so
    the font is now served by the app (6.7.2, from the existing `@fortawesome/fontawesome-free`
    dependency, in place of the CDN's 6.5.1).
  - Renamed classes are rewritten to their v4 names (`rounded` → `rounded-sm` and so on) by
    `@tailwindcss/upgrade`, with every change reviewed. It had to run from a tree still on v3,
    because it skips the v3 renames once `package.json` says v4.
- **ORCA grayscale theme:** the inline `addUtilities` plugin becomes one `@utility` per colour
  class, named after the core utility, applying `grayscale(0.65)` to the same 96 classes. Sharing
  the name merges the filter into that utility, so variants keep it (`hover:bg-green-700` greys
  on hover too, 135 such uses), as the v3 plugin did.
- **Accepted from v4:** `hover:` applies only on devices that can hover, and the OKLCH palette.
- **Supported browsers:** Safari 16.4+, Chrome and Edge 111+, Firefox 128+.
- **Verification:** before/after full-page screenshots of every page and key UI state at 1440px and
  390px, diffed pixel by pixel against a baseline taken on Tailwind v3. 81 of 85 came out
  pixel-identical. The other four differ only by live data, the Font Awesome 6.7.2 Laravel glyph,
  palette shifts of at most 9 per colour channel, and a 1px table-column rounding.

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
  - Font Awesome no longer needs a third-party request: no cdnjs dependency, no SRI hash to
    maintain, and the E2E suite renders real icons instead of a size stub.
- **Trade-off:**
  - Browsers older than the supported baseline get broken styling. That includes editors who load
    the embedded asset picker inside a CMS on an old browser.
  - The compatibility layer is v3 behaviour that new templates inherit until it is removed. The
    `space-x/y` port in particular means `space-*` does not behave as v4's documentation says.
    New code that wants a gap should prefer `flex`/`grid` with `gap-*`.
  - The CSS bundle grows from about 86 KB to 164 KB (39 KB gzipped), because Font Awesome's
    stylesheet is now inside it, and its fonts are served by the app.
  - Touch devices lose `hover:` styles, and colours shift slightly.
  - `translate-*`, `scale-*` and `rotate-*` set the individual `translate`, `scale` and `rotate`
    properties, not `transform`. Hand-written CSS that cancels one of them must name that
    property. The guided demo's nav pin said `transform: none` and stopped pinning. Its E2E test
    passed locally by timing alone and failed on CI; it now scrolls during the nav step and checks
    the computed `translate`.
  - Tailwind v4's engine is a native binary, so the build host needs a supported platform and Node
    20 or newer. CI runs Node 22.
