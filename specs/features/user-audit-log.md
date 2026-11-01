# User audit log

```yaml
id: user-audit-log
status: implemented
version: 1
owner: core
related:
  - architecture
  - user-management
  - authentication
  - authorization-policies
  - maintenance-commands
source:
  - app/Models/UserAuditLog.php
  - app/Observers/UserObserver.php
  - app/Console/Commands/UsersAuditCommand.php
  - app/Providers/AppServiceProvider.php
  - database/migrations/2026_07_30_000000_create_user_audit_logs_table.php
```

## Background / Why

A `users` row carries `created_at`, but an `UPDATE` that flips `role` left no trace, so the most
security-relevant mutation in the system was invisible after the fact (see the registration incident
in [authentication.md](authentication.md) REQ-8). This spec adds an append-only trail of who created,
re-roled, renamed, re-addressed or deleted an account, and who did it. Scope is deliberately `users`
only — not a general activity log.

## Requirements

- **REQ-1** — Every `created`, `updated` and `deleted` event on `User` appends a row to
  `user_audit_logs`. The trail is **append-only**: no application code updates or
  deletes rows, and `UserAuditLog` has no `update`/`delete` path of its own.
- **REQ-2** — On `updated`, only the **watched attributes** are recorded: `role`, `email`,
  `name`. A change to anything else (password, `last_login_at`, `preferences`, 2FA columns,
  `remember_token`) writes **no row**, so logins do not bury the events that matter.
- **REQ-3** — `changes` stores the before/after of the watched attributes that actually
  moved. Secrets (`password`, `remember_token`, `two_factor_*`, `jwt_secret`, `preferences`) are
  excluded by the explicit allowlist, not a denylist, so a new `users` column cannot leak by default.
- **REQ-4** — Each row attributes the change to an **actor**: `actor_id` is the
  authenticated user when one exists, and `null` for console commands, seeders, queued
  jobs and the observer's own system writes. `actor_label` carries a human-readable
  fallback (`console`, `system`, or the actor's email at the time of writing) so the
  trail stays readable after the actor's own row is deleted. `actor_id` is
  `nullOnDelete` for the same reason.
- **REQ-5** — A change that grants the `admin` role — a new `admin` account, or a
  promotion to `admin` — additionally emits a `warning` to the application log
  (`storage/logs/laravel.log`).
- **REQ-6** — Recording an audit row must never break the operation being audited. The
  observer wraps its work in `try`/`catch` and logs failures ([CLAUDE.md](../../CLAUDE.md)
  swallow + log), so a missing or full audit table never fails user administration.
- **REQ-7** — `php artisan users:audit` reads the trail, filterable by `--user=<email>` and `--event=`, `--limit=` (default 50, newest first).
  See [maintenance-commands.md](maintenance-commands.md).
- **REQ-8** — The trail is **not** exposed in the web UI in this version. `users:audit`
  is admin-only by being a console command; no route, controller or policy ability exists.

## Technical design

### Contract / public interface

```yaml
model: App\Models\UserAuditLog
  $casts: changes => array
  relations:
    user()   -> belongsTo(User)          # subject; nullOnDelete
    actor()  -> belongsTo(User, actor_id) # who did it; nullOnDelete
  scopes:
    scopeForUser(Builder, User|int)      # subject filter
    scopeOfEvent(Builder, string)        # created | updated | deleted
  const EVENTS = ['created', 'updated', 'deleted']
  const WATCHED = ['role', 'email', 'name']

observer: App\Observers\UserObserver     # registered in AppServiceProvider::boot()
  created(User)   -> record('created', full watched snapshot as "after")
  updated(User)   -> record('updated', only watched attributes that changed; no-op if none)
  deleted(User)   -> record('deleted', last watched snapshot as "before")

command: php artisan users:audit [--user=email] [--event=created|updated|deleted] [--limit=50]
  exit 0 always (a read-only report); prints a table, or "No audit entries" when empty
```

### Data shapes

```yaml
user_audit_logs:
  id: bigint
  user_id: bigint|null        # subject, FK users nullOnDelete
  user_label: string          # subject's email at write time, survives deletion
  actor_id: bigint|null       # FK users nullOnDelete; null for console/system
  actor_label: string         # 'console' | 'system' | actor email at write time
  event: string               # created | updated | deleted
  changes: json|null          # { role: {from: 'editor', to: 'admin'}, ... }
  ip: string|null             # request()->ip(); null under artisan
  user_agent: string|null     # truncated to 255; null under artisan
  created_at: timestamp       # no updated_at — rows are immutable (REQ-1)

indexes:
  [user_id, created_at]       # the users:audit --user query
  [event]
  [created_at]
```

### Layer touchpoints & ordering

```
UserController::store/update/destroy   (or token:create, or a seeder)
  → Eloquent save/delete
    → UserObserver::created|updated|deleted        (model event, after the write)
       → resolve actor: Auth::user() ?: (app()->runningInConsole() ? 'console' : 'system')
       → diff watched attributes via $user->getChanges() ∩ WATCHED
       → if empty on `updated`: return (REQ-2)
       → UserAuditLog::create([...])                (inside try/catch — REQ-6)
       → if the change grants admin: Log::warning(...)  (REQ-5)
```

The observer runs **after** the user write: a failed write files nothing, and a crash between the
two loses the audit row rather than rolling back the change (REQ-6).

### Persistence

- `user_audit_logs` — append-only, no `updated_at`, no retention window (volume is negligible).
- Deliberately **not** persisted: secrets and volatile columns (REQ-3), asset activity, and login
  events (`users.last_login_at` holds the last one).

## Scenarios (BDD)

```gherkin
Scenario: Provisioning a user files a created entry attributed to the admin (REQ-1, REQ-4)
  Given an authenticated admin
  When they POST /users with a new editor
  Then a user_audit_logs row exists with event 'created'
  And its actor_id is the admin
  And its changes record the role 'editor'
# pinned by: tests/Feature/UserAuditLogTest.php

Scenario: A role change is recorded with both the old and new value (REQ-2, REQ-3)
  Given an existing editor
  When an admin updates them to admin
  Then a row exists with event 'updated'
  And changes.role is {from: 'editor', to: 'admin'}
# pinned by: tests/Feature/UserAuditLogTest.php

Scenario: Granting admin also emits a warning to the application log (REQ-5)
  Given an existing editor
  When an admin promotes them to admin
  Then a warning is logged naming the subject and the actor
# pinned by: tests/Feature/UserAuditLogTest.php

Scenario: A login does not file an audit entry (REQ-2)
  Given a registered user
  When they log in, stamping last_login_at
  Then no user_audit_logs row is written
# pinned by: tests/Feature/UserAuditLogTest.php

Scenario: A password change does not file an audit entry, and never records the hash (REQ-2, REQ-3)
  Given an authenticated user
  When they PUT /password with a new password
  Then no user_audit_logs row is written
  And no audit row anywhere contains the password hash
# pinned by: tests/Feature/UserAuditLogTest.php

Scenario: Deleting a user keeps their trail readable (REQ-1, REQ-4)
  Given a user with audit entries
  When an admin deletes them
  Then a row exists with event 'deleted'
  And their earlier rows survive with user_id null and user_label still set
# pinned by: tests/Feature/UserAuditLogTest.php

Scenario: A console-created user is attributed to the console, not to a user (REQ-4)
  Given no authenticated user
  When php artisan token:create provisions an api user
  Then the row's actor_id is null and actor_label is 'console'
# pinned by: tests/Feature/UserAuditLogTest.php

Scenario: A failing audit write does not break user administration (REQ-6)
  Given the user_audit_logs table has been dropped
  When an admin creates a user
  Then the user is still created
  And the failure is logged
# pinned by: tests/Feature/UserAuditLogTest.php

Scenario: users:audit reports the trail newest-first and filters by user and event (REQ-7)
  Given audit entries for two users
  When php artisan users:audit --user=<email> runs
  Then only that user's entries are listed, newest first
  And --event=updated narrows further
  And an empty result says so instead of printing an empty table
# pinned by: tests/Feature/UserAuditLogTest.php
```

## Tests & verification

- Feature: `tests/Feature/UserAuditLogTest.php` — `php artisan config:clear && php artisan test tests/Feature/UserAuditLogTest.php`
- Style: `./vendor/bin/pint --test`
- Manual: `php artisan users:audit --limit=10` after changing a role in `/users`.

## Open questions / future

- **No UI (REQ-8).** A read-only per-user history on `/users` needs a controller, policy ability,
  view and translations.
- **Nothing pushes.** REQ-5 only logs a `warning`; see [authentication.md](authentication.md) "Open
  questions".
- **`users` only.** Auditing assets would need the same shape plus retention, at far higher volume.
- **Actor is the token owner, not the token** — `personal_access_tokens` is not threaded through.
