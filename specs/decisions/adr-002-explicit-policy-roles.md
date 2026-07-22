# ADR-002 — Policies encode role lists explicitly — no `return true` stubs

```yaml
id: adr-002-explicit-policy-roles
status: accepted
date: 2026-07-22
deciders: core
related:
  - ../architecture
  - ../features/authorization-policies
```

## Context / Forces

ORCA has three roles (`admin`, `editor`, `api`) with a deliberately uneven ability
matrix — e.g. API tokens may read and upload but must **not** delete assets, and
destructive bulk operations require admin *and* `maintenance_mode`. A permissive
default (`return true`, or "admin can do anything") makes it far too easy for a new
ability or a new role to silently grant access nobody intended.

## Decision

Every ability in `AssetPolicy`, `SystemPolicy` and `UserPolicy` **names the roles
allowed to perform it explicitly**. There are no `return true` stubs and no
catch-all admin bypass. Adding a role means opting it into each ability
deliberately; a new ability starts closed and is opened per role on purpose.

## Alternatives considered

- **`return true` / trust-the-UI** — rejected: authorization then lives only in
  route wiring and Blade conditionals, which the REST API bypasses entirely.
- **A `Gate::before` admin superuser bypass** — rejected: it hides the real matrix
  and would, for example, let an admin-scoped token skip the intended
  `maintenance_mode` guard on `move`/`bulkForceDelete`.
- **A permissions/roles package (e.g. spatie/laravel-permission)** — rejected as
  overkill for three fixed roles; a package's dynamic permissions are more surface
  to secure than a handful of explicit `in_array($role, [...])` checks.

## Consequences

- **Good:** the matrix is auditable in one place per policy and pinned by
  `tests/Unit/Policies/`; a mistake is a visible omission, not a silent grant.
- **Good:** `AssetApiController::destroy` routing through `authorize('delete')` means
  the API role gets a 403 for free.
- **Trade-off:** adding a role or ability is more manual work — every ability must
  be revisited rather than inherited.
