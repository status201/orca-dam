# ADR-010 — Services swallow + log and return `null`/`[]`; controllers map to status codes

```yaml
id: adr-010-services-swallow-controllers-map
status: accepted
date: 2026-07-22
deciders: core
related:
  - ../architecture
  - ../features/rest-api
  - ../features/s3-storage
```

## Context / Forces

Services talk to fallible externals — S3, Rekognition, Cloudflare, the filesystem.
Those failures shouldn't crash a request or leak stack traces / bucket names /
internal detail to an untrusted API caller. But they also must not vanish silently:
an operator needs the detail to debug, and the HTTP layer needs to return the right
status.

## Decision

**Services** catch their own errors, **log** the detail, and return a benign value
(`null` / `[]` / `false`) rather than throwing across the boundary. **Controllers**
decide the HTTP outcome from that return value and validation, mapping to the
appropriate status code. Message detail is **role-aware**: API-role users get a
generic message via `Controller::clientError()`; admins/editors see exception detail.
Domain conditions that *are* control flow (e.g. a duplicate upload) use a typed
exception like `DuplicateAssetException`.

## Alternatives considered

- **Let exceptions bubble to a global handler** — rejected: loses the per-operation
  context for logging, and risks leaking internals to API callers unless every
  handler is perfectly configured.
- **Return Result/Either types everywhere** — rejected: un-idiomatic in this Laravel
  codebase and heavier than the null/`[]` + log convention the team already reads.
- **Uniform generic errors for everyone** — rejected: admins/editors debugging their
  own instance benefit from seeing the actual failure; only untrusted API callers
  need the generic message.

## Consequences

- **Good:** failures are always logged (`storage/logs/laravel.log`), never leak to
  API callers, and one request's external hiccup doesn't 500 the app.
- **Good:** typed exceptions keep genuine control-flow (duplicates) explicit.
- **Trade-off:** a `null` return is easy to ignore at the call site, so controllers
  must check; and the log is the only trace of a swallowed error, so log hygiene
  matters.
