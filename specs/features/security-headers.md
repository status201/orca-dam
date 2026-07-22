# Security Headers

```yaml
id: security-headers
status: implemented
version: 1
owner: core
related:
  - architecture
  - features/iframe-embedding
source:
  - app/Http/Middleware/SecurityHeaders.php
  - config/session.php
```

## Background / Why

Every web response needs a baseline set of defensive HTTP headers regardless of
route — clickjacking protection, MIME-sniffing protection, referrer leakage
control, and transport security once served over HTTPS. `SecurityHeaders` applies
these unconditionally in the web middleware group, and is ordered so that
`AllowEmbedding` (see [`features/iframe-embedding.md`](iframe-embedding.md)) can
selectively relax the clickjacking header for the specific case where embedding is
explicitly configured.

## Requirements

- **REQ-1** — Every web response gets, unless already set: `X-Content-Type-Options:
  nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy:
  strict-origin-when-cross-origin`.
- **REQ-2** — `Strict-Transport-Security: max-age=31536000; includeSubDomains` is
  added **only** when the request is already served over HTTPS (`$request->isSecure()`)
  — never on an http-only dev/test setup, so a local `php artisan serve` session
  isn't pinned to HTTPS.
- **REQ-3** — `SecurityHeaders` is registered **before** `AllowEmbedding` in the web
  group, so on the response (which middleware unwinds in reverse) `AllowEmbedding`
  runs *after* `SecurityHeaders` and can remove `X-Frame-Options` in favor of a
  `frame-ancestors` CSP when embedding is configured.
- **REQ-4** — Headers are only set if not already present (`! $headers->has(...)`) —
  a more specific controller/response is never silently overridden.
- **REQ-5** — `SESSION_SECURE_COOKIE` defaults to `true` in production so the
  session cookie itself requires HTTPS, complementing the HSTS header.

## Technical design

### Contract / public interface

```yaml
SecurityHeaders::handle(Request, Closure): Response
```

No routes, no config keys of its own — this is a response-shaping middleware only.

### Layer touchpoints & ordering

```
Web middleware group (bootstrap/app.php):
  SecurityHeaders → AllowEmbedding → SetLocale → auth.multi
```

Response unwinding is the reverse of the request order, so on the way *out*
`AllowEmbedding` executes after `SecurityHeaders` has already set the baseline
`X-Frame-Options` — giving `AllowEmbedding` the chance to `remove()` it. See
[`architecture.md`](../architecture.md#middleware-stack).

### Persistence

None — purely a per-response header transform, no DB/cache state.

## Scenarios (BDD)

```gherkin
Scenario: A standard web response carries the baseline security headers
  Given an authenticated user
  When they GET a web route (e.g. the assets index)
  Then the response has X-Content-Type-Options: nosniff
  And X-Frame-Options: SAMEORIGIN
  And Referrer-Policy: strict-origin-when-cross-origin
# pinned by: tests/Feature/SecurityRemediationTest.php

Scenario: A downloaded asset still carries nosniff and forces attachment
  Given an existing asset
  When a user downloads it
  Then the response has X-Content-Type-Options: nosniff
  And Content-Disposition contains "attachment"
# pinned by: tests/Feature/SecurityRemediationTest.php
```

## Tests & verification

- Feature: `tests/Feature/SecurityRemediationTest.php` (`web responses carry
  baseline security headers`, `asset download forces attachment and nosniff`)
- Run: `php artisan config:clear && php artisan test`

## Open questions / future

- No test currently exercises the HSTS branch (`$request->isSecure()`) or asserts
  `SESSION_SECURE_COOKIE`'s production default — both would require simulating an
  HTTPS request/production environment, which the current Pest suite (plain HTTP,
  `testing` env) doesn't do. Worth a dedicated test that forces
  `$request->server->set('HTTPS', 'on')` before asserting the header.
