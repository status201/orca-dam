# Error handling & the database backstop

```yaml
id: error-handling
status: implemented
version: 1
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
```

## Background / Why

`withExceptions()` in `bootstrap/app.php` was empty and nothing in the repo handled a
`QueryException`. ADR-010 is the reason: it makes *services* swallow-and-log so controllers can map
a failure to a status code, and it explicitly rejected a global handler. That reasoning holds for
service failures — but a driver rejection is not one. MariaDB refuses the write inside
`$model->update()`, in the controller's own frame, with no service to swallow anything. So an
over-length copyright surfaced as `errors/500.blade.php` — "Something went wrong on our end" — with
nothing for the user to act on and nothing to quote in a bug report. ADR-016 records the amendment.

Two things follow. A DB rejection is usually a *user* error wearing a server error's clothes, so it
should read exactly like the validation message that should have caught it. And when an error
genuinely is ours, the user needs a reference to quote. Preventing the bad value from reaching the
driver at all is [`input-validation.md`](input-validation.md); this spec is the backstop behind it.

## Requirements

- **REQ-1** — A `QueryException` is classified from `$e->errorInfo` — `[SQLSTATE, driverCode,
  driverMessage]` — and **never** from `getMessage()`, whose Laravel tail embeds the query bindings
  substituted into the SQL. Recognised kinds: `too_long`, `duplicate`, `missing_required`,
  `stale_reference`, `still_referenced`, `busy`, `unavailable`, `unknown`, across MySQL/MariaDB,
  SQLite and PostgreSQL phrasings.
- **REQ-2** — A classified error that names a column becomes a **keyed validation failure** — a 422
  for a JSON caller and a redirect-back-with-errors for a form post — produced by re-entering
  Laravel's own `invalid()` / `invalidJson()`. A DB rejection is therefore indistinguishable from the
  rule that should have caught it: same status, same body shape, same rendered field error, same
  corrective action. `too_long` / `missing_required` / `duplicate` map to 422;
  `still_referenced` / `busy` to 409; `unavailable` to 503.
- **REQ-3** — Messages reuse the framework's already-translated validation strings
  (`validation.max.string`, `validation.unique`, `validation.required`) with the column's displayable
  attribute name, so the backstop carries no separate vocabulary and no new translation debt for the
  common cases.
- **REQ-4** — The classifier **degrades, never guesses**. Column and limit known → the counted
  message. Column known, limit unknown → a message naming the field without a number. Neither known
  → a generic "one of the values you entered is too long". Where the driver reports only an index
  name, the derived column is verified with `Schema::hasColumn()` before use and dropped to `null`
  if it does not resolve — the handler never invents a field name.
- **REQ-5** — An **unclassified** `QueryException` returns `null` from the renderer when
  `config('app.debug')` is on, so local debugging keeps the whoops page and stack trace. In
  production it becomes a friendly failure carrying the error reference. Only this branch is
  debug-dependent; a classified error behaves identically in every environment so tests are
  deterministic.
- **REQ-6** — There is **no catch-all `Throwable` renderer**. Laravel already renders a friendly
  page for every web 5xx in production; the defect was that the page said nothing and gave no
  reference, which REQ-7 fixes. A catch-all would run before the `app.debug` check, killing the
  whoops page in development, and is the widest possible surface for masking a genuine bug.
- **REQ-7** — Every request carries an `App\Support\ErrorId` reference — six uppercase hex
  characters, generated with `random_bytes` and memoised for the request. It appears on the log
  line, as an `X-Orca-Error-Id` response header on error responses, as `error_id` in a 5xx JSON body,
  and on the 500 page, so a user can quote one string and an operator can find that request.
- **REQ-8** — A `QueryException` is logged once, with the parameterised SQL, SQLSTATE, driver code,
  table, column, route and the **lengths** of the bindings — never their values. The framework's
  default report for this exception type is suppressed, because its message embeds those values into
  a log that `/system`'s viewer renders to any admin.
- **REQ-9** — `App\Support\ErrorAudience` owns the role rule that decides who sees exception detail:
  an `api`-role caller gets none. `Controller::clientError()` delegates to it, so the rule lives in
  one place reachable from `bootstrap/app.php`. For a `QueryException`, even a trusted operator
  receives the driver's sentence rather than the SQL-with-bindings.
- **REQ-10** — A 5xx JSON body is scrubbed for an `api`-role caller regardless of `app.debug`, since
  `APP_DEBUG=true` is this repository's default in `.env`, `.env.example` and `.env.e2e` and Laravel
  would otherwise ship file, line and stack trace.
- **REQ-11** — Client-side, the **server's message is the actionable one**. `errorMessageFrom()` in
  `resources/js/http-errors.js` resolves a failed `fetch` to the best available text — a 422's first
  field error, then `message`, then `error` — and a hardcoded string is the fallback of last resort,
  not the first choice. Handlers that read the server's message and then discard it in favour of a
  generic toast are the defect this requirement exists to remove.
- **REQ-12** — A message that reaches a toast is inserted as text, never as HTML. `window.showToast`
  has exactly one definition (`resources/js/app.js`); the two inline copies in the layouts that
  interpolated into `innerHTML` are removed, because surfacing server strings would otherwise turn
  a dead code path into a live injection sink one script-ordering change away.

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
```

`bootstrap/app.php` registers exactly four things, in this order:

```
$exceptions->context(...)                  // error id + DB facts on every log line
$exceptions->render(QueryException ...)     // REQ-1..REQ-5
$exceptions->report(QueryException ...)     // REQ-8; returns false to replace the default line
$exceptions->respond(...)                   // REQ-7 header, REQ-10 scrubbing
```

### Data shapes

```yaml
# DatabaseErrorHint — the classifier's output
kind: too_long|duplicate|missing_required|stale_reference|still_referenced|busy|unavailable
column: string|null      # verified against the schema, never guessed
limit: int|null          # from ColumnLimits when the column is known
status: int              # 422 | 409 | 503
message: string          # translated, user-facing, free of SQL and of binding values

# a keyed rejection, JSON — byte-identical in shape to a FormRequest failure
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
FormRequest 422 ────────────────────────────┐
                                            ├─ same status, same body, same @error block
QueryException → DatabaseError::classify()  │
              → DatabaseErrorResponder      │
              → ValidationException ────────┘  (re-enters the handler's invalid()/invalidJson())
              → unclassified → app.debug ? null (whoops) : friendly 5xx + error id

every error response → respond() → X-Orca-Error-Id, api-role scrubbing
every log line       → context() → error id + DB facts
```

Re-entering `ExceptionHandler::render()` with a `ValidationException` terminates: `renderViaCallbacks`
dispatches on the closure's first parameter type, so a callback typed `QueryException` cannot match
it. That reuse is deliberate — it inherits the `dontFlash` list, the `_error_bag` handling, the
redirect target and the JSON shape instead of reimplementing four things that must match exactly.

The classic asset edit form needs **no view change**: `edit.blade.php`'s existing `@error('copyright')`
block renders the keyed message and `old('copyright')` repopulates the field.

`resources/js/http-errors.js` is deliberately **not** imported by `resources/js/app.js` — like
`tag-input-core.js` it is a shared helper pulled in by its consumers, so the module count
`app.js` documents is unaffected.

### Persistence

None. `ErrorId` is memoised in the container for the life of one request and is deliberately not
persisted: it correlates a user report with a log line, it is not an audit record. Under FPM the
container is rebuilt per request, so ids do not leak between requests; within a single Pest test
two errors share one id, which is what makes the header assertable.

## Visual aids

```
                         ┌── classified + column ──→ ValidationException ──→ 422 / redirect+errors
QueryException ──────────┤
  (errorInfo, not        ├── classified, no column ─→ 422 / 409 / 503 + message + error id
   getMessage)           │
                         └── unclassified ─────────→ app.debug ? whoops : friendly 5xx + error id
```

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

Scenario: A real unique-constraint violation becomes a keyed 422
  Given an insert that violates a unique index
  When the handler classifies it
  Then the response is 422 keyed on the offending column
# pinned by: tests/Feature/DatabaseErrorBackstopTest.php

Scenario: A not-null violation names the missing field
  Given an insert omitting a NOT NULL column
  When the handler classifies it
  Then the response is 422 keyed on that column
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

Scenario: An error response carries a reference the user can quote
  Given any handler-rendered error response
  When it is returned
  Then it carries an X-Orca-Error-Id header matching the id in the log context
# pinned by: tests/Feature/DatabaseErrorBackstopTest.php

Scenario: The error page shows the reference
  Given app.debug disabled and a request that fails
  When the 500 page renders
  Then it shows the same reference
# pinned by: tests/Feature/DatabaseErrorBackstopTest.php

Scenario: An api-role caller sees no exception detail through clientError
  Given a controller-mapped failure and an api-role caller
  When clientError() builds the message
  Then it returns only the generic text
# pinned by: tests/Unit/DatabaseErrorTest.php, tests/Feature/SecurityRemediationTest.php
```

## Tests & verification

- Unit: `tests/Unit/DatabaseErrorTest.php` — the classifier over a keyed dataset of driver errors
  (MySQL/MariaDB/SQLite/PostgreSQL), the degradation ladder, the leak assertions, `ErrorAudience`.
  A MySQL 22001 is synthesised by setting `PDOException::$errorInfo` (a public property) and
  wrapping it in a `QueryException` — SQLite cannot produce one.
- Feature: `tests/Feature/DatabaseErrorBackstopTest.php` — end to end through the handler, including
  **real** SQLite `23000` errors (unique, not-null, foreign key) so the pipeline is proven against a
  genuine PDO error and not only a synthetic one.
- Feature: `tests/Feature/SecurityRemediationTest.php` — the existing role-aware detail assertions,
  which must stay green through the `ErrorAudience` extraction.
- `php artisan config:clear && php artisan test tests/Unit/DatabaseErrorTest.php tests/Feature/DatabaseErrorBackstopTest.php`
- Style: `./vendor/bin/pint --test`; JS: `npm run build`.

## Open questions / future

- **No E2E coverage of the friendly error page.** `.env.e2e` sets `APP_DEBUG=true`, so the
  production 500 page never renders in the browser suite; and `database/e2e.sqlite` cannot produce a
  `22001` at all. Covering it would need a dedicated env with debug off.
- `$exceptions->respond()` is a **single slot** — a second registration anywhere silently replaces
  the decorator and the error reference disappears from every response. There is no framework guard
  for this; the only defence is knowing it.
- Re-entering `ExceptionHandler::render()` relies on `renderViaCallbacks` dispatching on the
  closure's parameter type. Stable across the current handler generation but framework-internal. The fallback if it ever
  changes is a hand-built `back()->withInput()->withErrors()`, which must then re-derive the
  `dontFlash` list the framework applies for free.
- The `api`-role scrubbing in `respond()` is a safety net for a misconfigured production
  `APP_DEBUG`. The real fix is `APP_DEBUG=false` in production; this only bounds the damage.
- `edit.blade.php` re-seeds the Alpine tag chips from `window.__pageData` rather than `old()`, so any
  redirect-back — including the one this spec adds for DB errors — loses unsaved tag edits. Tracked
  in `tag-input.md`.
- The remaining admin-tooling handlers (`tags.js`, `discover.js`, `export.js`, `system-admin.js`,
  `api-docs.js`) already read `data.message` on a non-ok response, so what is left is their
  `catch` blocks — genuine network or parse failures, where there is no server message to surface
  and a generic string is the honest answer. Two read-path loads (`tags.js` and `export.js`
  fetching the tag list) still discard the response entirely; a failure there has no field-level
  cause, so it is low value rather than wrong.
