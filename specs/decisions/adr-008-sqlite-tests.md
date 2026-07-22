# ADR-008 — In-memory SQLite for tests against MariaDB in production

```yaml
id: adr-008-sqlite-tests
status: accepted
date: 2026-07-22
deciders: core
related:
  - ../architecture
  - ../recipes/write-a-test
```

## Context / Forces

The suite is large (~957 tests) and every test uses `RefreshDatabase`, so DB
setup/teardown speed dominates wall-clock. Production runs MariaDB. Running the suite
against a real MariaDB means migrating/truncating a server database on every run —
slow, stateful, and CI-hostile.

## Decision

Tests run against **in-memory SQLite** (`DB_DATABASE=:memory:` in `phpunit.xml`),
with array cache/session and the `sync` queue, so each run is fast and hermetic.
**Because** the test DB is selected by config, the suite is only safe if config cache
is fresh — hence the hard rule: **always `php artisan config:clear` before
`php artisan test`** (a stale `bootstrap/cache/config.php` can point `RefreshDatabase`
at the dev MariaDB and wipe it). A PreToolUse hook enforces this.

## Alternatives considered

- **MariaDB in tests (prod parity)** — rejected as the default: far slower, requires
  a running server, and pollutes a real database; kept as an option only for
  debugging a genuine dialect-specific issue.
- **SQLite file (not `:memory:`)** — rejected: on-disk I/O is slower and leaves a
  file to clean up; in-memory is faster and self-cleaning per process.
- **No `config:clear` guard, just discipline** — rejected: the failure mode
  (wiping the dev DB) is catastrophic and easy to hit; a hook makes it mechanical.

## Consequences

- **Good:** the suite is fast, hermetic, and CI-friendly with sync queue removing
  async flakiness.
- **Trade-off:** SQLite ≠ MariaDB — a small class of dialect/behaviour differences
  (JSON functions, strictness, collations) can pass in tests yet differ in prod, so
  genuinely DB-specific logic needs manual verification.
- **Trade-off:** the mandatory `config:clear` is an easy step to forget without the
  guard; it is load-bearing, not cosmetic.
