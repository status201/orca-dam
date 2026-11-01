# Error handling & the database backstop

```yaml
id: error-handling
status: implemented
version: 3
owner: core
related:
  - architecture
  - input-validation
  - rest-api
  - localization
  - system-admin
source:
  - bootstrap/app.php
  - app/Support/DatabaseError.php
  - app/Support/DatabaseErrorResponder.php
  - app/Support/ErrorAudience.php
  - app/Support/ErrorId.php
  - app/Http/Controllers/Controller.php
  - resources/js/http-errors.js
  - resources/views/errors/500.blade.php
  - resources/views/errors/4xx.blade.php
  - resources/js/http.js
  - resources/js/format.js
```

## Background / Why

ADR-010 makes *services* swallow-and-log, but a driver rejection happens inside `$model->update()`
in the controller's own frame, so it surfaced as a bare 500 with nothing to act on. ADR-016 amends
ADR-010 with a narrow `QueryException` handler: a DB rejection is usually a user error and should
read exactly like the validation message that should have caught it; when an error genuinely is
ours, the user gets a reference to quote. This spec is the backstop behind
[`input-validation.md`](input-validation.md), and the owner of who sees exception detail.

## Requirements

- **REQ-1** — A `QueryException` is classified from `$e->errorInfo` (`[SQLSTATE, driverCode,
  driverMessage]`), **never** from `getMessage()` (which embeds the bindings). Kinds: `too_long`,
  `duplicate`, `missing_required`, `stale_reference`, `still_referenced`, `busy`, `unavailable`,
  `unknown`, across MySQL/MariaDB, SQLite and PostgreSQL phrasings.
- **REQ-2** — A classified error naming a column becomes a **keyed validation failure** (422 JSON,
  or redirect-back-with-errors for a form post) by re-entering Laravel's `invalid()` /
  `invalidJson()`, so it is indistinguishable from the rule that should have caught it.
  `too_long` / `missing_required` / `duplicate` → 422; `still_referenced` / `busy` → 409;
  `unavailable` → 503.
- **REQ-3** — Messages reuse the framework's translated strings (`validation.max.string`,
  `validation.unique`, `validation.required`) with the column's display name — no separate
  vocabulary.
- **REQ-4** — The classifier **degrades, never guesses**: column + limit → counted message; column
  only → names the field without a number; neither → generic "one of the values you entered is too
  long". A column derived from an index name is verified with `Schema::hasColumn()` and dropped to
  `null` if it does not resolve.
- **REQ-4a** — A **derived column is reported against the field it stands for** via
  `DatabaseError::COLUMN_ALIASES`, applied inside `verifyColumn()` *before* the `Schema::hasColumn()`
  check. Today: `assets.s3_key_hash → s3_key` ([`input-validation.md`](input-validation.md)
  REQ-11). Why: the surrogate is a real column, so REQ-4 alone would key the error on a field no
  form has and the user would see nothing.
- **REQ-5** — An **unclassified** `QueryException` returns `null` from the renderer when
  `config('app.debug')` is on (keeps the debug page); in production it becomes a friendly failure
  with the error reference. Only this branch is debug-dependent.
- **REQ-6** — There is **no catch-all `Throwable` renderer**. Why: it would run before the
  `app.debug` check (killing the debug page) and is the widest surface for masking a real bug;
  Laravel already renders a friendly page for web 5xx, which REQ-7 adds a reference to.
- **REQ-7** — Every request carries an `App\Support\ErrorId` reference — six uppercase hex chars,
  `random_bytes`, memoised per request. It appears on the log line, as an `X-Orca-Error-Id` header on
  error responses, as `error_id` in a 5xx JSON body, and on the 500 page.
- **REQ-8** — A `QueryException` is logged once, with parameterised SQL, SQLSTATE, driver code,
  table, column, route and the **lengths** of the bindings — never their values. The framework's
  default report for this type is suppressed (its message embeds values into a log `/system` shows
  to admins).
- **REQ-9** — `App\Support\ErrorAudience` owns the rule for who sees exception detail: an
  `api`-role caller gets none. `Controller::clientError()` delegates to it. For a `QueryException`,
  even a trusted operator gets the driver's sentence, not SQL-with-bindings.
- **REQ-10** — A 5xx JSON body is scrubbed for an `api`-role caller regardless of `app.debug`
  (`APP_DEBUG=true` is the default in `.env`, `.env.example` and `.env.e2e`).
- **REQ-11** — Client-side, the **server's message is the actionable one**. `errorMessageFrom()` in
  `resources/js/http-errors.js` resolves a failed `fetch` to a 422's first field error, then
  `message`, then `error`; a hardcoded string is the last-resort fallback.
- **REQ-request-headers** — Every same-origin `fetch()` takes its headers from `requestHeaders()` in
  `resources/js/http.js`: the CSRF token, `Accept` (JSON unless the caller names another type) and,
  for a JSON body, `Content-Type`. The token is read from `<meta name="csrf-token">` **at call time**
  (`bootstrap.js` refreshes it every 15 minutes).
- **REQ-12** — A toast message is inserted as text, never HTML. `window.showToast` has exactly one
  definition (`resources/js/app.js`); no layout may define an `innerHTML`-interpolating copy.

## Technical design

### Contract / public interface

```
App\Support\DatabaseError::info(QueryException $e): array          // [sqlstate, driverCode, driverMessage]
App\Support\DatabaseError::driverMessage(QueryException $e): string
App\Support\DatabaseError::classify(QueryException $e): ?DatabaseErrorHint
App\Support\DatabaseErrorResponder::respond(QueryException $e, Request $request): ?Response
App\Support\ErrorAudience::detail(Throwable $e): ?string          // null for an api-role caller
App\Support\ErrorId::current(): string                            // memoised per request
App\Http\Controllers\Controller::clientError(Throwable $e, string $generic): string   // delegates to ErrorAudience
resources/js/http-errors.js  →  errorMessageFrom(response, fallback), throwHttpError(response, fallback)
resources/js/http.js         →  csrfToken(), requestHeaders({ json = true, accept = 'application/json' })   // REQ-request-headers
resources/js/format.js       →  formatBytes(bytes, decimals = 2)
```

`bootstrap/app.php` registers exactly four things, in this order:

```
$exceptions->context(...)                  // error id + DB facts on every log line
$exceptions->render(QueryException ...)     // REQ-1..REQ-5
$exceptions->report(QueryException ...)     // REQ-8; returns false to replace the default line
$exceptions->respond(...)                   // REQ-7 header, REQ-10 scrubbing
```

`resources/js/http-errors.js` is imported by its consumers, not by `app.js` (not an Alpine module).

### Data shapes

```yaml
# DatabaseErrorHint — the classifier's output
kind: too_long|duplicate|missing_required|stale_reference|still_referenced|busy|unavailable
column: string|null      # verified against the schema, never guessed
limit: int|null          # from ColumnLimits when the column is known
status: int              # 422 | 409 | 503
message: string          # translated, user-facing, free of SQL and of binding values

# a keyed rejection, JSON — same shape as a FormRequest failure
message: string
errors: { <column>: [string] }

# a 5xx JSON body
message: string
error_id: string         # 6 uppercase hex chars

# the log context for a QueryException
error_id / sqlstate / driver_code / kind / table / column / route: scalar
sql: string              # parameterised, values not interpolated
binding_lengths: array   # int per string binding, null otherwise — never the values
```

### Layer touchpoints & ordering

```
                         ┌── classified + column ──→ ValidationException ──→ 422 / redirect+errors
QueryException ──────────┤     (re-enters the handler's invalid()/invalidJson())
  (errorInfo, not        ├── classified, no column ─→ 422 / 409 / 503 + message + error id
   getMessage)           └── unclassified ─────────→ app.debug ? null (debug page) : friendly 5xx + error id

every error response → respond() → X-Orca-Error-Id, api-role scrubbing
every log line       → context() → error id + DB facts
```

Re-entering the handler with a `ValidationException` is deliberate: it inherits `dontFlash`,
`_error_bag`, the redirect target and the JSON shape. It terminates because `renderViaCallbacks`
dispatches on the closure's parameter type. Existing `@error('…')` / `old('…')` in forms render the
result with no view change.

### Persistence

None. `ErrorId` lives in the container for one request; it correlates a report with a log line and
is deliberately not an audit record.

## Scenarios (BDD)

```gherkin
Scenario: An over-length value rejected by the driver becomes a keyed 422
  Given a write that the driver rejects with SQLSTATE 22001 naming a column
  When the request expects JSON
  Then the response is 422 with a validation error on that column
  And the body contains no SQL, no SQLSTATE and no binding values
# pinned by: tests/Feature/DatabaseErrorBackstopTest.php

Scenario: The same rejection on a form post redirects back with the field error
  Given the same rejection on a classic form submission
  When the handler renders it
  Then the response redirects back with an error on that column and the submitted input intact
# pinned by: tests/Feature/DatabaseErrorBackstopTest.php

Scenario: A DB rejection is indistinguishable from the rule that should have caught it
  Given one request that fails validation and one that the driver rejects on the same column
  When both are made as JSON
  Then both are 422 with the same body shape and the same error key
# pinned by: tests/Feature/DatabaseErrorBackstopTest.php

Scenario Outline: Real constraint violations become a keyed 422
  Given an insert that <violates>
  When the handler classifies it
  Then the response is 422 keyed on the offending column
  Examples:
    | violates                 |
    | violates a unique index  |
    | omits a NOT NULL column  |
# pinned by: tests/Feature/DatabaseErrorBackstopTest.php

Scenario: A classified message never contains SQL, SQLSTATE or a binding value
  Given every recognised driver error across MySQL, MariaDB, SQLite and PostgreSQL
  When each is classified
  Then no resulting message contains "SQLSTATE", "select ", "update " or "Connection:"
# pinned by: tests/Unit/DatabaseErrorTest.php

Scenario: An index name that does not resolve to a column degrades instead of guessing
  Given a duplicate-key error naming an index whose derived column does not exist
  When the classifier runs
  Then the hint carries a null column and the unkeyed message
# pinned by: tests/Unit/DatabaseErrorTest.php

Scenario: An unclassified database error keeps the stack trace in development
  Given an unrecognised driver error and app.debug enabled
  When the renderer runs
  Then it declines to handle the exception so the default debug page is shown
# pinned by: tests/Feature/DatabaseErrorBackstopTest.php

Scenario: An api-role caller never sees internals in a 5xx body
  Given an api-role token and an unclassified server error with app.debug disabled
  When the response is rendered
  Then the body carries only a generic message and the error reference
# pinned by: tests/Feature/DatabaseErrorBackstopTest.php

Scenario: An error carries a reference the user can quote
  Given any handler-rendered error response, and the 500 page with app.debug disabled
  When it is returned
  Then it carries an X-Orca-Error-Id header matching the id in the log context
  And the 500 page shows the same reference
# pinned by: tests/Feature/DatabaseErrorBackstopTest.php

Scenario: An api-role caller sees no exception detail through clientError
  Given a controller-mapped failure and an api-role caller
  When clientError() builds the message
  Then it returns only the generic text
# pinned by: tests/Unit/DatabaseErrorTest.php, tests/Feature/SecurityRemediationTest.php
```

## Tests & verification

- Unit: `tests/Unit/DatabaseErrorTest.php` — classifier dataset (MySQL 22001 synthesised via
  `PDOException::$errorInfo`), degradation, leak assertions, `ErrorAudience`.
- Feature: `tests/Feature/DatabaseErrorBackstopTest.php` — end to end, including real SQLite `23000`
  errors; `tests/Feature/SecurityRemediationTest.php` — role-aware detail.
- `php artisan config:clear && php artisan test tests/Unit/DatabaseErrorTest.php tests/Feature/DatabaseErrorBackstopTest.php`
- Style: `./vendor/bin/pint --test`; JS: `npm run build`.

## Open questions / future

- **No E2E coverage of the friendly error page** (`.env.e2e` has `APP_DEBUG=true`; e2e SQLite
  cannot produce a `22001`). Needs a dedicated env with debug off.
- `$exceptions->respond()` is a **single slot** — a second registration silently replaces it and
  the error reference disappears. No framework guard.
- Re-entering the handler relies on framework-internal `renderViaCallbacks` dispatch; the fallback
  is a hand-built `back()->withInput()->withErrors()` that must re-derive `dontFlash`.
- The REQ-10 scrubbing only bounds a misconfigured production `APP_DEBUG`; the fix is
  `APP_DEBUG=false`.
- `edit.blade.php` re-seeds tag chips from `window.__pageData`, not `old()`, so any redirect-back
  loses unsaved tag edits (tracked in `tag-input.md`).
- Two read-path loads (`tags.js`, `export.js` fetching the tag list) still discard the error
  response; low value, no field-level cause.
