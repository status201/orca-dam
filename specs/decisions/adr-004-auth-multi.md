# ADR-004 — Four auth mechanisms behind `auth.multi`, not one unified guard

```yaml
id: adr-004-auth-multi
status: accepted
date: 2026-07-22
deciders: core
related:
  - ../architecture
  - ../features/authentication
  - ../features/jwt-auth
  - ../features/api-tokens-sanctum
  - ../features/passkeys
```

## Context / Forces

ORCA serves three genuinely different clients: humans in a browser (session, possibly
with TOTP or a passkey), long-lived backend integrations (WordPress, RTE) that need
a stable token, and short-lived programmatic callers that prefer a self-contained
JWT. Each has a different credential lifetime and trust model, but they all hit the
same asset routes and must resolve to the same `User` and role checks.

Three clients, but **four** mechanisms — the browser client alone accounts for two,
since a passkey is a distinct credential type that authenticates into the same
session guard (session/Breeze, Sanctum, JWT, passkeys; TOTP is a second factor on
the session, not a mechanism of its own).

## Decision

Keep the mechanisms **separate** and select between them per request with one
middleware, `AuthenticateMultiple` (`auth.multi:web,sanctum,jwt`): try each named
guard in order, authenticate on the first that succeeds, 401 if none do. Web routes
use the session guard; API routes use `auth.multi:sanctum,jwt`. Passkeys and TOTP
layer onto the web guard. Downstream, everything is just an authenticated `User`.

## Alternatives considered

- **One unified custom guard** that sniffs the credential type — rejected: it would
  re-implement what Sanctum and the JWT guard already do, in one brittle place, and
  couple four independent lifetimes together.
- **Separate route groups per mechanism (no multi-guard)** — rejected: it forces
  clients into different URLs for the same resource and duplicates route
  definitions.
- **JWT (or Sanctum) only, everywhere** — rejected: sessions + passkeys are the
  right fit for the browser UI, and long-lived integration tokens shouldn't be
  short-lived JWTs.

## Consequences

- **Good:** each mechanism uses its idiomatic Laravel implementation; adding/removing
  one (JWT is off by default) is a config change, not a rewrite.
- **Good:** authorization is uniform — policies see a `User` regardless of how it
  authenticated.
- **Trade-off:** four mechanisms are more attack surface and more to document/test;
  guard **order** matters and must be kept deliberate.
