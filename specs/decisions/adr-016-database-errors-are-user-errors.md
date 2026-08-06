# ADR-016 — A driver rejection is a user error: one global backstop behind the controllers

```yaml
id: adr-016-database-errors-are-user-errors
status: accepted
date: 2026-08-05
deciders: core
amends: adr-010-services-swallow-controllers-map
related:
  - ../features/error-handling
  - ../features/input-validation
  - ../features/rest-api
  - ../architecture
```

## Context / Forces

ADR-010 decided that services swallow-and-log and controllers map failures to status codes, and it
explicitly rejected "let exceptions bubble to a global handler". That left `withExceptions()` in
`bootstrap/app.php` empty.

A driver rejection does not fit the shape ADR-010 describes. When MariaDB refuses a write — a value
wider than the column, a unique index, a stale foreign key — the `QueryException` is thrown by
`$model->update()` **inside the controller's own frame**. There is no service boundary to swallow it
and no benign value to return: the controller called Eloquent directly, as every controller in this
codebase does. So the exception went to Laravel's default handler and the user got the generic 500
page: no field named, no corrective action, and no reference to quote in a bug report. That is how a
300-character copyright against a `varchar(255)` column presented itself.

The forces in tension:

- Most driver rejections are **user** errors that a validation rule should have caught. Presented as
  a 500 they are unactionable; presented as a keyed validation error they are trivially actionable.
- ADR-010's two objections to a global handler are real and must be answered, not waved away:
  losing per-operation logging context, and leaking internals to untrusted API callers.
- `QueryException::getMessage()` embeds the bindings substituted into the SQL, so the naive "show the
  admin the exception message" habit leaks user data into both responses and logs.
- A handler broad enough to catch everything is also broad enough to hide genuine bugs, and would
  pre-empt the `app.debug` stack trace that makes local development workable.

## Decision

**A `QueryException` is handled globally and translated into the validation error it should have
been.** ADR-010 continues to govern *service* failures unchanged; this ADR adds a backstop for the
one class of failure that has no service to swallow it.

Concretely (contract in [`../features/error-handling.md`](../features/error-handling.md)):

- Classify from `$e->errorInfo` — `[SQLSTATE, driverCode, driverMessage]` — never from
  `getMessage()`.
- A classified error that names a column re-enters Laravel's own `invalid()` / `invalidJson()` as a
  `ValidationException`, so a DB rejection is byte-identical in shape to the `FormRequest` failure
  that should have caught it: same status, same JSON, same rendered `@error` block.
- The classifier **degrades rather than guesses**: a column derived from an index name is verified
  against the schema before use, and an unresolvable one drops to a generic message.
- Scope is `QueryException` only. **No catch-all `Throwable` renderer.**
- An unclassified `QueryException` declines to render when `app.debug` is on, preserving the stack
  trace; in production it becomes a friendly failure carrying an error reference.
- Every request carries a short `ErrorId`, surfaced on the log line, an `X-Orca-Error-Id` header, a
  5xx JSON body and the error page.
- `Controller::clientError()`'s role rule moves to `App\Support\ErrorAudience`, reachable from
  `bootstrap/app.php`, and for a `QueryException` returns the driver's sentence rather than the
  SQL-with-bindings.

**Answering ADR-010's two objections directly.** *Context loss*: the handler logs SQLSTATE, driver
code, kind, table, column, route, the parameterised SQL and the binding **lengths** — strictly more
context than a service's `Log::error('… failed: '.$e->getMessage())`, and without the values.
*Leaking internals*: a classified response is a field name plus a translated character count, with
no SQL to leak; the unclassified branch goes through `ErrorAudience`, the same rule as before but now
shared instead of duplicated; and a 5xx JSON body is scrubbed for `api`-role callers even when
`APP_DEBUG` is wrongly on.

The primary defence remains **prevention** — [`../features/input-validation.md`](../features/input-validation.md)
makes a rule that outruns its column a test failure. This backstop is what catches the case nobody
predicted, not a licence to skip validation.

## Alternatives considered

- **Leave `withExceptions()` empty and add a `try/catch` per controller action** — the strict
  ADR-010 reading. Rejected: every write path in the app would need the same catch, the mapping logic
  would be copy-pasted (`ChunkedUploadController` already proved how that ends by hand-copying a
  rules array), and any path someone forgot would still 500 silently.
- **`$exceptions->map(QueryException → ValidationException)`** — the shortest diff. Rejected on two
  counts: `mapException()` runs in **both** `report()` and `render()`, so the mapping executes twice
  per request; and a mapped `ValidationException` lands in the handler's internal don't-report list,
  so the database failure would never be logged at all. Silently losing the log is worse than the
  500.
- **A `CatchDatabaseErrors` middleware** — idiomatic and locally scoped. Rejected: it is blind to
  exceptions raised outside its own `handle()` frame — route-model binding, earlier middleware,
  terminating callbacks — so it would be a backstop with holes in it.
- **A catch-all `Throwable` renderer** — would satisfy "never a bare 500" most directly. Rejected:
  it runs before the `app.debug` check and would replace the whoops page in development, and it is
  the widest possible surface for turning a real bug into a friendly message nobody investigates.
  Laravel already renders a friendly page for a production 5xx; the missing piece was a reference,
  not a page.
- **Widen every string column to `TEXT`** — makes `22001` unreachable. Rejected: it trades a
  diagnosable error for unbounded growth, throws away the column width as documentation, and does
  nothing for unique, not-null or foreign-key rejections.

## Consequences

- **Good:** the reported class of bug — an over-length field — now produces the message the user
  needed in the first place, on the form field they typed it into, with no view changes.
- **Good:** unique, not-null, foreign-key, deadlock and connection failures all gained an actionable
  message and a status code, not just the one that was reported.
- **Good:** DB detail stopped leaking. Binding values no longer reach responses or
  `storage/logs/laravel.log` (which `/system`'s viewer renders to any admin).
- **Good:** every error is quotable. An operator can find one request from six characters.
- **Trade-off:** a `ValidationException` synthesised from a driver error is *indistinguishable* from
  a real one by design, so a missing validation rule no longer announces itself with a 500. The
  audit in `input-validation.md` and the `kind: too_long` log line are what keep it visible —
  without them this ADR would trade a loud bug for a quiet one.
- **Trade-off:** the classifier owns a table of per-driver message patterns, which is exactly the
  kind of thing that rots when a driver rephrases an error. It degrades to a generic message rather
  than misfiring, and the unit dataset pins each phrasing.
- **Trade-off:** `$exceptions->respond()` is a single-slot registration — a future second
  registration silently drops the error reference from every response, with no framework guard.
