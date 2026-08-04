# System admin dashboard

```yaml
id: system-admin
status: implemented
version: 1
owner: core
related:
  - architecture
  - authorization-policies
source:
  - app/Http/Controllers/SystemController.php
  - app/Services/SystemService.php
  - app/Services/QueueService.php
  - app/Services/TestRunnerService.php
  - app/Jobs/RunTestSuiteJob.php
  - app/Policies/SystemPolicy.php
  - routes/web.php
```

## Background / Why

Admins need a single operational surface — settings, queue health, logs, ad-hoc
artisan commands, S3 connectivity, integrity checks, and a full Pest run — without
shelling into the server. `/system/*` is that surface: one controller delegating to
three narrow services, gated entirely by `SystemPolicy::access` (admin only, see
[authorization-policies.md](authorization-policies.md)).

## Requirements

- **REQ-1** — Every `SystemController` action calls
  `$this->authorize('access', SystemController::class)` first; the route group also
  wraps them in `can:access,App\Http\Controllers\SystemController` middleware
  (belt-and-braces).
- **REQ-2** — Ad-hoc command execution (`executeCommand`, and the retry/flush/
  restart/process-queue shortcuts) only ever runs commands on
  `SystemService::ALLOWED_COMMANDS` — never arbitrary shell input. Non-whitelisted
  commands are logged as a warning and rejected with `success: false`.
- **REQ-3** — Setting updates (`updateSetting`) validate known keys against
  per-key closures (ranges, regex, enum membership) before writing through
  `Setting::set()`; unknown keys skip validation and are written as-is.
- **REQ-4** — The web test runner never blocks the HTTP request: `runTests` seeds a
  `queued` cache entry and dispatches `RunTestSuiteJob` on the queue; the browser
  polls `runTestsStatus` and can call `runTestsAbort` to SIGTERM/`taskkill` the
  underlying PHP CLI subprocess.
- **REQ-5** — Test-run progress/results live only in cache
  (`TestRunnerService::CACHE_PREFIX`, 1h TTL) — nothing is persisted to the DB.
- **REQ-6** — **The PHP CLI binary is resolved through config, never `env()`.**
  `PHP_BINARY` can point at php-fpm or CGI, which cannot run `artisan test`, so an operator
  must be able to override it — `PHP_CLI_PATH` in `.env`, surfaced as
  `config('orca.php_cli_path')`. `findPhpCliBinary()` reads **only** that config key and casts
  the result to `string`; failing that it uses `PHP_BINARY` unless the path contains `fpm` or
  `cgi`, and finally bare `php` on `getExtendedPath()`.

  Reading it with `env()` is the bug this requirement exists to prevent. `DEPLOYMENT.md` runs
  `php artisan config:cache`, after which `env()` returns `null` — and the
  `Artisan::call('config:clear')` at the top of `runStreaming()` does not save it, because
  clearing the compiled file does not repopulate `$_ENV` in an already-booted process. The
  override was therefore inert in exactly the environment that needs it (Plesk), while the
  companion `config('app.php_cli_path')` read a key that was never defined in `config/app.php`
  and so was always null. Same failure mode as
  [security-invariants.md](security-invariants.md) REQ-9's seeder credentials, and the reason
  `config/orca.php` exists.

## Technical design

### Contract / public interface

```yaml
SystemController:
  index()                        # GET  system                       — dashboard (settings, queue stats, disk, DB counts, suggested commands)
  queueStatus()                  # GET  system/queue-status           — {stats, pending_jobs, failed_jobs}
  logs(Request)                  # GET  system/logs?lines=            — tail laravel.log, clamped 10-500
  executeCommand(Request)        # POST system/execute-command        — {command} -> whitelisted Artisan::call
  testS3()                       # GET  system/test-s3                — S3Service::listObjects('', 1) smoke test
  cacheStats()                   # GET  ...not routed directly; used internally
  retryJob(Request)              # POST system/retry-job              — {job_id} -> queue:retry <id>
  flushQueue()                   # POST system/flush-queue            — queue:flush
  processQueue()                 # POST system/process-queue          — queue:work --max-jobs=50 --tries=3 --stop-when-empty
  restartQueue()                 # POST system/restart-queue          — queue:restart
  supervisorStatus()             # GET  system/supervisor-status      — parses `supervisorctl status`
  updateSetting(Request)         # POST system/settings               — {key, value} -> validate -> Setting::set()
  regenerateResizedImages()      # POST system/regenerate-resized-images — dispatches RegenerateResizedImage per image asset
  integrityStatus()              # GET  system/integrity-status       — {missing, total}
  verifyIntegrity()              # POST system/verify-integrity       — Artisan::call('assets:verify-integrity')
  runTests(Request)              # POST system/run-tests              — {suite?, filter?} -> {run_id, status: queued}
  runTestsStatus(string $runId)  # GET  system/run-tests/{runId}/status
  runTestsAbort(string $runId)   # DELETE system/run-tests/{runId}
  documentation(Request)         # GET  system/documentation?file=    — renders an allow-listed *.md as sanitized HTML

SystemService:
  executeCommand(string, array): array           # whitelist-checked Artisan::call wrapper
  getSystemInfo/getDiskUsage/getDatabaseStats/getCacheStats(): array
  testS3Connection(): array
  getSupervisorStatus(): array                    # Windows -> {available: false}; else parses supervisorctl
  getSuggestedCommands(): array
  getSettings(): array
  updateSetting(string, mixed): bool

QueueService:
  getQueueStats(): array                          # {pending, failed, batches}
  getFailedJobs(int $limit = 20): array            # exception truncated to 500 chars + '...'
  getPendingJobs(int $limit = 20): array

TestRunnerService:
  seedQueued(string $runId, string $suite, ?string $filter): void
  runStreaming(string $runId, string $suite, ?string $filter): void   # owns the whole subprocess lifecycle
  status(string $runId): ?array
  abort(string $runId): bool
  markFailed(string $runId, string $message): void
  parseTestOutput(string $output): array           # regex-parsed passed/failed/skipped/assertions/tests[]

RunTestSuiteJob(runId, suite='all', filter=null): tries=1, timeout=900
  handle(TestRunnerService)   # delegates to runStreaming(); on Throwable: markFailed() then re-throw
  failed(Throwable)           # markFailed() (queue-level failure hook)
```

### Data shapes

```yaml
# TestRunnerService cache entry (key: "test_run:{runId}", TTL 3600s)
status: queued|running|completed|failed|aborted
completed: int
passed: int
failed: int
skipped: int
current_suite: string|null
estimate: float|null        # last successful run's duration, keyed by suite+filter, cached 30 days
started_at: float           # microtime(true)
duration: float
pid: int|null                # subprocess PID, used by abort()
output_tail: string          # last OUTPUT_TAIL_BYTES (16 KiB) of ANSI-stripped output, updated every 250ms tick
stats: object|null            # set on completion — parseTestOutput() result + exit_code + success
exit_code: int|null
error: string|null

# SystemService::ALLOWED_COMMANDS (executeCommand whitelist)
cache: [clear, config:clear, route:clear, view:clear, optimize:clear, optimize, config:cache, route:cache, view:cache]
tags: [reference-tag:create]
storage: [storage:link]
uploads: [uploads:cleanup]
queue: [queue:retry, queue:flush, queue:restart, queue:work]
migrations: [migrate:status, migrate:rollback, migrate, "migrate --force"]
2fa: [two-factor:status, two-factor:disable]
passkeys: [passkeys:list, passkeys:revoke]
assets: [assets:backfill-etags, "assets:deduplicate", "assets:deduplicate --force", assets:verify-integrity]
```

### Layer touchpoints & ordering

`routes/web.php` wraps the whole `/system/*` group in
`can:access,App\Http\Controllers\SystemController` middleware; each controller
action additionally calls `$this->authorize()` itself. `runTests` writes the
`queued` cache entry synchronously (so an immediate poll never 404s) *before*
dispatching `RunTestSuiteJob`; the job's `handle()` then calls
`TestRunnerService::runStreaming()`, which re-clears `config:clear` (so the
subprocess doesn't inherit a stale compiled config) and drives a `proc_open()`
subprocess, ticking the cache every 250ms by re-reading a temp file (chosen over
non-blocking pipes because `stream_set_blocking()` doesn't work as expected for
`proc_open` pipes on Windows, and Pest/PHPUnit buffer stdout fully until exit
regardless of OS).

### Persistence

Nothing System-admin-specific is persisted to the DB beyond `Setting` rows
(`updateSetting`) and whatever the executed command itself does. Test-run state
lives only in the cache store (`test_run:{runId}`, 1h TTL) and a long-lived
duration estimate (`test_run_estimate:{suite}:{md5(filter)}`, 30 days) used to
drive the progress bar's wall-clock estimate on Windows where per-test streaming
isn't possible.

## Scenarios (BDD)

```gherkin
Scenario: Only admins can reach the system dashboard
  Given a guest, an editor, and an admin
  When each requests GET /system
  Then the guest is redirected to login, the editor gets 403, the admin gets 200
# pinned by: tests/Feature/SystemTest.php

Scenario: Admin updates a range-validated setting
  Given an admin
  When they POST system/settings with key=rekognition_min_confidence, value=85
  Then the response is 200 with success true
  And Setting::get('rekognition_min_confidence') is 85
# pinned by: tests/Feature/SystemTest.php

Scenario: Range-validated setting rejects an out-of-bounds value
  Given an admin
  When they POST system/settings with key=rekognition_min_confidence, value=50
  Then the response is 422 with success false
# pinned by: tests/Feature/SystemTest.php

Scenario: Editors cannot update settings
  Given an editor
  When they POST system/settings
  Then the response is 403
# pinned by: tests/Feature/SystemTest.php

Scenario: Admin dispatches a test run and gets a run id back immediately
  Given an admin and a faked Bus
  When they POST system/run-tests with suite=unit, filter=SettingTest
  Then the response is 200 with status "queued" and a non-empty run_id
  And RunTestSuiteJob is dispatched with that run_id, suite, and filter
# pinned by: tests/Feature/SystemTest.php

Scenario: Editors cannot dispatch a test run
  Given an editor and a faked Bus
  When they POST system/run-tests
  Then the response is 403 and nothing is dispatched
# pinned by: tests/Feature/SystemTest.php

Scenario: Polling an unknown test run returns 404
  Given an admin
  When they GET system/run-tests/{unknown}/status
  Then the response is 404 with success false
# pinned by: tests/Feature/SystemTest.php

Scenario: Admin aborts a running test run
  Given an admin and a cached run in status "running"
  When they DELETE system/run-tests/{runId}
  Then the response is 200
  And the cached run's status becomes "aborted"
# pinned by: tests/Feature/SystemTest.php

Scenario: RunTestSuiteJob delegates to TestRunnerService::runStreaming
  Given a run id, suite, and filter
  When the job handles
  Then TestRunnerService::runStreaming is called with exactly those arguments
# pinned by: tests/Unit/Jobs/RunTestSuiteJobTest.php

Scenario: RunTestSuiteJob marks the run failed and re-throws on exception
  Given TestRunnerService::runStreaming throws
  When the job handles
  Then TestRunnerService::markFailed is called with the run id and message
  And the exception propagates
# pinned by: tests/Unit/Jobs/RunTestSuiteJobTest.php

Scenario: parseTestOutput extracts pass/fail/skip/assertion counts and per-test results
  Given raw Pest console output with PASS/FAIL suite headers and checkmark/x-mark test lines
  When parseTestOutput is called
  Then passed/failed/skipped/assertions are extracted and each test is attributed to its suite
# pinned by: tests/Unit/TestRunnerServiceTest.php

Scenario: toUtf8 scrubs a truncated multi-byte tail so JSON encoding never fails
  Given a string ending in a lone UTF-8 lead byte (a 16 KiB tail-read artifact)
  When toUtf8 is applied
  Then the result encodes to valid JSON
# pinned by: tests/Unit/TestRunnerServiceTest.php

Scenario: Admin can regenerate resized images for all image assets only
  Given 3 image assets and 1 PDF asset
  When the admin POSTs system/regenerate-resized-images
  Then exactly 3 RegenerateResizedImage jobs are dispatched
# pinned by: tests/Feature/SystemTest.php

Scenario: getFailedJobs truncates long exceptions and orders by failed_at desc
  Given failed_jobs rows with exceptions over 500 chars
  When getFailedJobs is called
  Then each exception is capped at 500 chars plus an ellipsis, newest first
# pinned by: tests/Unit/QueueServiceTest.php

Scenario: Only admins can access SystemController per SystemPolicy
  Given an admin, editor, and api user
  When SystemPolicy::access is checked for each
  Then only the admin is allowed
# pinned by: tests/Unit/Policies/SystemPolicyTest.php

# — browser-level (see e2e-testing.md for the harness) —

Scenario: The system page renders for an admin
  Given a session for admin@e2e.test
  When /system is opened
  Then the dashboard renders
# pinned by: tests/e2e/system-settings.spec.js

Scenario: The queue-status and log-viewer endpoints answer
  Given the system page as an admin
  When the queue status and log tail endpoints are called
  Then each responds with a payload
# pinned by: tests/e2e/system-settings.spec.js
```

## Tests & verification

- Feature: `tests/Feature/SystemTest.php` — routes, setting validation matrix, test-run
  dispatch/status/abort — `php artisan config:clear && php artisan test`
- Unit: `tests/Unit/TestRunnerServiceTest.php` (output parsing, UTF-8 scrubbing),
  `tests/Unit/Jobs/RunTestSuiteJobTest.php`, `tests/Unit/QueueServiceTest.php`,
  `tests/Unit/Policies/SystemPolicyTest.php`
- E2E: `tests/e2e/system-settings.spec.js` — the settings UI and the queue/log endpoints.

## Open questions / future

- `SystemService::getSupervisorStatus()`, `testS3Connection()`, `getLogTail()`,
  `getSystemInfo()`/`getDiskUsage()`/`getDatabaseStats()`, and the
  `documentation()` markdown-rendering endpoint have no direct test coverage
  (SystemTest.php exercises settings/regenerate/test-run flows but not these
  read-only diagnostics endpoints). Low risk (read-only, no state mutation) but
  worth a light smoke test if this controller is touched again.
