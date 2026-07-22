# ADR-007 — Blade + Alpine modules over an SPA framework

```yaml
id: adr-007-blade-alpine-over-spa
status: accepted
date: 2026-07-22
deciders: core
related:
  - ../architecture
  - ../recipes/add-an-alpine-module
```

## Context / Forces

ORCA is a server-rendered Laravel app whose UI is mostly forms, an asset grid, and
admin dashboards — CRUD with pockets of interactivity (upload progress, tag inputs,
bulk selection), not a highly stateful client application. The team is backend-
leaning. A full SPA would add a build-time framework, a client router, an API-for-
everything obligation, and a second place for auth/state to live.

## Decision

Render HTML with **Blade** and add interactivity with **Alpine.js** modules
(currently ~25) registered in `resources/js/app.js`, bundled by Vite. Blade views
stay slim; behaviour lives in the Alpine modules. Shared client logic is factored
into mixins/helpers (e.g. `tag-input-core`, `thumbnail-generator`, `upload-metadata`).

## Alternatives considered

- **A React/Vue SPA** — rejected: heavy for a form-and-grid app, forces every view
  behind a JSON API, and splits auth/session handling across two layers.
- **Livewire / Inertia** — reasonable and Laravel-native, but adds a stateful
  server-round-trip model (Livewire) or an adapter layer (Inertia) that the current
  interactivity doesn't need; Alpine covers it with far less machinery.
- **Vanilla JS, no framework** — rejected: the reactive binding and component
  lifecycle Alpine gives (`x-data`, `x-model`, stores) is exactly what the tag
  inputs, bulk bar, and uploader need, and hand-rolling it would drift per view.

## Consequences

- **Good:** minimal build surface, progressive enhancement, one auth/session model,
  views that map directly to routes.
- **Good:** each interactive concern is an isolated, testable-in-browser module.
- **Trade-off:** highly dynamic future UI would strain Alpine; module registration is
  a manual step (see the recipe); no client-side type checking of the JS.
