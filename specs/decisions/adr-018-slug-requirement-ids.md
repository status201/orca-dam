# ADR-018 — New requirements get a kebab-case slug ID; numeric IDs are frozen

```yaml
id: adr-018-slug-requirement-ids
status: accepted
date: 2026-09-24
deciders: core
related:
  - ../README
  - ../architecture
  - adr-000-spec-driven-development
```

## Context / Forces

Every requirement in a feature spec (and the system invariants in `architecture.md`)
has been numbered: `REQ-1`, `REQ-2`, and so on, 336 of them across 50 specs. Specs,
code comments and tests cite them about 200 times, often from another file
(`input-validation.md REQ-11`). A number carries no meaning, so each citation is
unreadable until you open the spec. Two branches that each add "the next" requirement
to the same spec both pick the same number, and the collision only shows up after the
merge. A requirement that belongs between two others turns into `REQ-3a`, because
renumbering would break every citation. Several specs already have `a` inserts.

## Decision

A **new** requirement is identified by a kebab-case slug that summarises it, for example
`REQ-example-quoted-phrases-extracted-before-splitting`. Existing numeric IDs stay as they
are, and no new numeric ID may be added.

- **Grammar.** `REQ-<slug>`, where the slug is `[a-z][a-z0-9]*(-[a-z0-9]+)+`: lowercase,
  at least two words, starts with a letter, at most 60 characters. The leading letter keeps
  slugs apart from legacy `REQ-\d+[a-z]?`. The two-word minimum means prose placeholders
  like `REQ-n` never look like a reference. The `example-` prefix is reserved for
  illustrations like the one above: it can't be defined and is never resolved.
- **Frozen manifest.** `specs/.legacy-req-ids.json` lists every numeric ID that existed on
  this date, per spec. It was generated once. `scripts/spec-lint.mjs` deliberately has no
  mode that regenerates it, because such a mode would let anyone freeze a new numeric ID.
  The list only shrinks: an entry whose requirement is gone is an error, so a deleted
  `REQ-7` can't come back later with a new meaning.
- **Lint** (`scripts/spec-lint.mjs` → `checkRequirementIds`, which runs in CI and as the
  local Stop hook):
  - Every bullet under `## Requirements` has the form `- **REQ-<id>** — …`.
  - A numeric ID must appear in the manifest; anything else must be a valid slug.
  - No ID is defined twice within a spec.
  - No slug is defined twice anywhere in the repo.
  - Every `REQ-<slug>` token in `specs/`, the root docs, `app/`, `routes/`, `config/`,
    `database/`, `resources/`, `tests/` and `scripts/` resolves to a defined slug.

Renaming a slug edits `## Requirements`, so the version rule in `scripts/sdd-guard.mjs`
already requires a `version:` bump. The reference check then points at every citation
that still uses the old name.

## Alternatives considered

- **Renumber everything to slugs now** — rejected. It would rewrite 336 definitions and
  about 200 citations in code, tests and specs in one change, for IDs that are stable
  enough as they are. Specs that get revised will pick up slugs for their new
  requirements naturally.
- **Detect "new" by diffing against the base ref** (like the version rule) — rejected. It
  needs a base, so it fails open in a shallow clone and never runs on a direct push to
  `main`. The CI job only runs `sdd-guard` on pull requests. The manifest makes the check
  stateless, so it runs everywhere `spec-lint` runs.
- **Keep numbers, add a per-spec "next ID" counter** — rejected. It fixes neither the
  merge collision (both branches bump the same counter) nor the unreadable citations.
- **Slugs unique per spec only** — rejected. A code comment citing a bare `REQ-<slug>`
  should name exactly one requirement without needing the spec file next to it.
  Uniqueness across the repo is what makes such a citation checkable.

## Consequences

- **Good:** citations explain themselves, parallel branches don't collide, a new
  requirement can go wherever it belongs without an `a` suffix, and a dangling citation
  fails the lint instead of rotting.
- **Trade-off:** two ID styles coexist indefinitely. Legacy numeric citations are **not**
  resolved by the lint; only slug references are. Cleaning those up would be a separate
  job.
- **Trade-off:** slugs are longer than numbers, and a slug describes the requirement as
  first written. If the requirement changes meaning, rename the slug; the lint then
  finds every citation to update.
