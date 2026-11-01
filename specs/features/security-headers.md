# Security Headers

```yaml
id: security-headers
status: implemented
version: 2
owner: core
related:
  - architecture
  - features/iframe-embedding
source:
  - app/Http/Middleware/SecurityHeaders.php
  - config/session.php
```

## Background / Why

Every web response gets a baseline of defensive headers (clickjacking, MIME sniffing, referrer
leakage, HSTS over HTTPS). `SecurityHeaders` applies them in the web group, ordered so
`AllowEmbedding` ([`iframe-embedding.md`](iframe-embedding.md)) can relax the clickjacking header
when embedding is configured.

## Requirements

- **REQ-1** — Every web response gets, unless already set: `X-Content-Type-Options:
  nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy:
  strict-origin-when-cross-origin`.
- **REQ-2** — `Strict-Transport-Security: max-age=31536000; includeSubDomains` is
  added **only** when the request is served over HTTPS (`$request->isSecure()`), so a local
  http session is never pinned to HTTPS.
- **REQ-3** — `SecurityHeaders` is appended **after** `AllowEmbedding` in the web
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
Web middleware group (bootstrap/app.php, appended in request order):
  SetLocale → AllowEmbedding → SecurityHeaders
```

Response unwinding reverses this, so `AllowEmbedding` can `remove()` the baseline
`X-Frame-Options` ([`architecture.md`](../architecture.md#middleware-stack)).

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

- Feature: `tests/Feature/SecurityRemediationTest.php` — `php artisan config:clear && php artisan test`

## Open questions / future

- The HSTS branch and the `SESSION_SECURE_COOKIE` production default are untested (needs a forced
  HTTPS request, e.g. `$request->server->set('HTTPS', 'on')`).
