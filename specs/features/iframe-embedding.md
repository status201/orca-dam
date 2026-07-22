# Iframe Embedding

```yaml
id: iframe-embedding
status: implemented
version: 1
owner: core
related:
  - architecture
  - features/security-headers
  - features/settings
source:
  - app/Http/Middleware/AllowEmbedding.php
  - app/Http/Controllers/AssetController.php
  - resources/views/assets/partials/grid.blade.php
```

## Background / Why

External systems (an RTE, a CMS) may want to embed ORCA's asset browser in an
`<iframe>`. Browsers block framing by default (`X-Frame-Options: SAMEORIGIN`, set
by [`features/security-headers.md`](security-headers.md)); ORCA needs an
explicit, admin-controlled allow-list of frame ancestors rather than opening
framing to everyone. `GET /assets/embed` is the embeddable browser itself — the
same asset grid as the index page, minus navigation/footer chrome, driven by the
same query params.

## Requirements

- **REQ-1** — When `Setting::get('embed_allowed_domains')` is a non-empty array,
  the response gets `Content-Security-Policy: frame-ancestors 'self' <domains>` and
  `X-Frame-Options` is removed (a CSP `frame-ancestors` directive supersedes it in
  modern browsers, but leaving both would be contradictory).
- **REQ-2** — When the list is empty, neither header is touched — the
  `SecurityHeaders` baseline (`X-Frame-Options: SAMEORIGIN`) stands, so framing is
  blocked by default.
- **REQ-3** — Each configured domain is validated against a host/origin regex
  (`AllowEmbedding::isValidAncestor()`) before being interpolated into the CSP
  directive. A malformed entry (whitespace, quotes, semicolons, or anything that
  doesn't match an optional-scheme + hostname + optional-wildcard-label +
  optional-port shape) is silently dropped rather than injected — a bad DB value
  can't be used to smuggle extra CSP directives.
- **REQ-4** — `embed_allowed_domains` may be stored as a JSON-encoded string or a
  native array (`Setting::get`'s `json` type decodes it, but the middleware
  defensively `json_decode`s again if it comes back as a string).
- **REQ-5** — Any exception while reading the setting (e.g. `settings` table not
  yet migrated) is swallowed — embedding is simply not applied, the request
  proceeds with default headers.
- **REQ-6** — `GET /assets/embed` (`AssetController::embed`) renders the same asset
  grid partial as the index, without the app layout's nav/footer, and honors the
  same query params (`type`, `search`, pagination) via the embed-specific route for
  filter navigation.

## Technical design

### Contract / public interface

```yaml
AllowEmbedding::handle(Request, Closure): Response
AllowEmbedding::isValidAncestor(string $domain): bool   # private; regex gate
GET /assets/embed   -> AssetController::embed   # web, session-auth required, no nav/footer chrome
```

### Data shapes

```yaml
# settings row
key: "embed_allowed_domains"
type: "json"
value: ["https://rte.example.com", "https://cms.example.com"]   # array of origin/host strings
```

### Layer touchpoints & ordering

Runs in the web group after `SecurityHeaders`, before `SetLocale` — see
[`architecture.md`](../architecture.md#middleware-stack). It only acts on the
*response* (reads the setting after `$next($request)`), so it can override a header
`SecurityHeaders` already set on the way out. `GET /assets/embed` shares
`AssetController`'s `buildIndexData()` with the regular index — the grid partial
(`resources/views/assets/partials/grid.blade.php`) is the shared markup between the
two.

### Persistence

- No dedicated table. Reads `Setting::get('embed_allowed_domains', [])` — see
  [`features/settings.md`](settings.md) for the cache/typing contract.

## Scenarios (BDD)

```gherkin
Scenario: An empty allow-list keeps default clickjacking protection
  Given embed_allowed_domains is empty
  When any authenticated user loads a web route
  Then no Content-Security-Policy header is set
# pinned by: tests/Feature/EmbedTest.php, tests/Feature/Middleware/AllowEmbeddingTest.php

Scenario: A configured allow-list sets frame-ancestors and drops X-Frame-Options
  Given embed_allowed_domains contains two valid https origins
  When an authenticated user loads a web route
  Then Content-Security-Policy is "frame-ancestors 'self' <domain1> <domain2>"
  And X-Frame-Options is absent
# pinned by: tests/Feature/EmbedTest.php, tests/Feature/Middleware/AllowEmbeddingTest.php

Scenario: Malformed domains are dropped, valid ones survive
  Given embed_allowed_domains mixes a valid https URL with a quote-injection attempt and a domain containing spaces
  When an authenticated user loads a web route
  Then the CSP contains the valid domain
  And does not contain the injection payload or the malformed entry
# pinned by: tests/Feature/SecurityRemediationTest.php

Scenario: The setting may be stored as a JSON string
  Given embed_allowed_domains is stored as a json_encode()'d string rather than a native array
  When an authenticated user loads a web route
  Then the domains are still decoded and applied to the CSP
# pinned by: tests/Feature/Middleware/AllowEmbeddingTest.php

Scenario: Guests cannot reach the embed browser
  Given no authenticated session
  When a request hits GET /assets/embed
  Then it redirects to login
# pinned by: tests/Feature/EmbedTest.php

Scenario: The embed browser omits nav/footer chrome but honors filters
  Given an authenticated user
  When they load /assets/embed with a type or search query param
  Then matching assets are shown, non-matching ones are not, and no footer/nav markup is present
# pinned by: tests/Feature/EmbedTest.php
```

## Tests & verification

- Feature: `tests/Feature/EmbedTest.php` (embed route access, filters, no
  nav/footer, CSP presence/absence), `tests/Feature/Middleware/AllowEmbeddingTest.php`
  (CSP construction, JSON-string domains, X-Frame-Options removal),
  `tests/Feature/SecurityRemediationTest.php` (malformed-domain rejection)
- Run: `php artisan config:clear && php artisan test`

## Open questions / future

- None open — allow-list construction, malformed-input rejection, empty-list
  default, and the embed route itself all have direct test coverage.
