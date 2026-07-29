# Decisions — Architecture Decision Records

`decisions/` records the **why** behind cross-cutting architectural choices — and,
crucially, *which alternatives were rejected and the trade-off accepted* — so a
deliberate constraint isn't mistaken for an accident. Each ADR is one short,
immutable record structured **Context → Decision → Alternatives → Consequences**
(see [`../README.md`](../README.md) → "Decisions (ADRs)").

ADRs are **append-only**: to change a decision, write a *new* ADR that supersedes
the old one rather than rewriting it. An ADR's `status` is its own lifecycle —
`proposed | accepted | superseded by adr-XXX | deprecated` — not the
`draft | active | implemented` of a feature spec.

## Index

| ADR | Title | Status |
| --- | ----- | ------ |
| [000](adr-000-spec-driven-development.md) | Spec-Driven Development as the working method (enforced) | accepted |
| [001](adr-001-service-layer.md) | Service layer in `app/Services/` over fat controllers | accepted |
| [002](adr-002-explicit-policy-roles.md) | Policies encode role lists explicitly — no `return true` stubs | accepted |
| [003](adr-003-soft-delete-keeps-s3.md) | Soft delete keeps S3 objects; only hard delete clears storage | accepted |
| [004](adr-004-auth-multi.md) | Four auth mechanisms behind `auth.multi`, not one unified guard | accepted |
| [005](adr-005-chunked-above-10mb.md) | Chunked S3 multipart above 10 MB, direct upload below | accepted |
| [006](adr-006-immutable-s3-key.md) | `s3_key` is immutable — cache-bust via Cloudflare purge, never key rewrite | accepted |
| [007](adr-007-blade-alpine-over-spa.md) | Blade + Alpine modules over an SPA framework | accepted |
| [008](adr-008-sqlite-tests.md) | In-memory SQLite for tests against MariaDB in production | accepted |
| [009](adr-009-project-owns-nl-json.md) | The project owns `lang/nl.json` — `lang:safe-update`, never raw `lang:update` | accepted |
| [010](adr-010-services-swallow-controllers-map.md) | Services swallow + log and return `null`/`[]`; controllers map to status codes | accepted |
| [011](adr-011-settings-in-db.md) | Runtime settings live in the DB (`Setting`, 1 h cache), not `.env` | accepted |
| [012](adr-012-reference-tags-api-only.md) | Reference tags are API-created only (external-system usage tracking) | accepted |
| [013](adr-013-wordpress-plugin-separate-stream.md) | The WordPress plugin is a separate release stream (`wp-v*` tags) | accepted |
| [014](adr-014-playwright-e2e-real-stack.md) | Browser E2E with Playwright against a real stack (MinIO for S3) | accepted |

New ADRs copy [`_adr-template.md`](_adr-template.md) to `adr-NNN-<slug>.md`, numbered
contiguously. Keep this index and the folder map in [`../README.md`](../README.md) in
sync when adding one.
