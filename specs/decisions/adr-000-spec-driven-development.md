# ADR-000 — Spec-Driven Development as the working method (enforced)

```yaml
id: adr-000-spec-driven-development
status: accepted
date: 2026-07-22
deciders: core
related:
  - ../README
  - ../architecture
```

> ADR `status` is its own decision lifecycle and is **not** the feature-spec
> `draft | active | implemented`.

## Context / Forces

ORCA had strong *reference* documentation (`CLAUDE.md`, `USER_MANUAL`,
`DEPLOYMENT`, `QUICK_REFERENCE`) but **no per-feature behavioural contracts and no
ADRs** — the *why* behind load-bearing choices lived only in people's heads and in
prose that drifts from the code. Increasingly the code is read and written by AI
agents, which re-derive intent from whatever code snapshot they see; a deliberate
constraint is indistinguishable from an accident, and "fixes" re-litigate settled
decisions. The sibling repo `vast-websynth` had already proven an enforced SDD loop
works in practice.

## Decision

Adopt **Spec-Driven Development**: `specs/` is the architectural/behavioural source
of truth, written spec-before-code for new work. Enforce it with a zero-dep guard
(`scripts/sdd-guard.mjs`) wired as Claude Code hooks and a CI job — a change that
edits gated production code (`app/**`, `routes/**`, `database/migrations/**`,
`config/**`, `resources/js/**`) must carry a spec change or an explicit bypass. A
companion `scripts/spec-lint.mjs` checks spec *structure* and index completeness.

## Alternatives considered

- **Keep prose docs only (status quo)** — rejected: nothing keeps them in sync with
  the code, and they don't record rejected alternatives, so drift is inevitable.
- **A heavyweight RFC / design-doc process** — rejected: too much ceremony for a
  small team and for the AI-assisted edit loop; it lives outside the repo and isn't
  enforceable on every change.
- **Tests as the only contract** — rejected: tests encode *how* a thing behaves but
  not *why* it was chosen, and offer no home for the "alternatives considered" that
  stop re-litigation. Specs *reference* the tests (`# pinned by:`) instead.
- **Enforce only in CI (no local hooks)** — rejected as insufficient alone: local
  hooks give the agent immediate feedback at edit time; CI is the cross-tool
  backstop. We do both.

## Consequences

- **Good:** a living, agent-readable contract; the *why* is captured with its
  rejected alternatives; drift is caught mechanically at edit time and in CI.
- **Good:** the gate fails **open** on its own errors and exempts non-production
  paths, so it rarely gets in the way.
- **Trade-off:** every gated change now needs a spec touch (or a justified
  `.sdd-skip`/`[skip-sdd]` bypass), and the initial corpus had to be **backfilled**
  for a system that already shipped — a one-time cost paid to get the baseline.
