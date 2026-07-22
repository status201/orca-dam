# ADR-012 — Reference tags are API-created only (external-system usage tracking)

```yaml
id: adr-012-reference-tags-api-only
status: accepted
date: 2026-07-22
deciders: core
related:
  - ../architecture
  - ../features/tags
  - ../features/reference-tags-api
```

## Context / Forces

Tags in ORCA serve two audiences: humans organising the library (`user` tags), the
AI tagger (`ai` tags), and external systems that want to record *where an asset is
used* — e.g. WordPress writing `wp:<site>/post/<id>` when an asset is embedded in a
post. Usage-tracking marks are created by machines at integration time, not typed by
a person browsing the UI, and mixing them into the human tag-entry flow would let
users hand-fabricate "usage" that never happened.

## Decision

Introduce a third tag `type`, **`reference`**, that can only be **created via the
API** (`POST /api/reference-tags`, keyed by `asset_id`/`asset_ids`/`s3_key`/`s3_keys`).
They are still **editable/deletable in the web UI** (an admin can clean them up) and
appear in their own CSV export column, but the web tag-input paths never mint them.

## Alternatives considered

- **One undifferentiated tag type** — rejected: loses the provenance distinction
  (human vs AI vs external-usage) that makes the CSV export and cleanup meaningful,
  and would let users fabricate usage marks.
- **A separate `asset_usages` table** — rejected as heavier than needed; reference
  tags reuse the existing tag + `asset_tag` machinery (search, export, UI) for free.
- **Allow web creation of reference tags too** — rejected: their whole point is that
  they record *actual* external usage reported by an integration, not intent typed by
  a user.

## Consequences

- **Good:** provenance is explicit; integrations get a simple idempotent endpoint;
  the existing tag UI/search/export handle the new type with minimal new code.
- **Good:** admins can still correct bad data via the web UI.
- **Trade-off:** the "API-only create" rule is a convention the endpoints must
  enforce (not a DB constraint); a third type is more to reason about in tag flows.
