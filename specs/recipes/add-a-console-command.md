<!--
  Recipe: add an artisan console command.
-->

# Recipe — Add an artisan console command

```yaml
id: add-a-console-command
status: implemented
version: 1
owner: core
related:
  - architecture
  - ../features/maintenance-commands
source:
  - app/Console/Commands/
```

A repeatable **playbook**, not a feature. ORCA's 17 console commands are thin
wrappers over existing services (no new business logic lives in the command
class) and every destructive one prompts for confirmation unless `--force` is
passed — the CLI is the emergency-recovery path when the web UI itself is
inaccessible, so its confirmation/force discipline has to be consistent. The
concrete worked instances are `app/Console/Commands/DeduplicateAssets.php`
(dry-run-by-default, `--force` to act) and `TokenRevokeCommand.php`
(interactive `confirm()`, `--force` to skip).

## Background / Why

A command that silently no-ops on a bad identifier, or that mutates data
without confirmation, is dangerous precisely because it's often reached for
under time pressure (revoking a compromised token, disabling 2FA for a locked-
out admin). Fixing the shape once — clear failure on a bad lookup, explicit
`--force` gate on anything destructive — means every command in the list
behaves predictably without re-deriving the convention per command.

## Steps

### 1. Create the command — `app/Console/Commands/<Name>Command.php`

Laravel auto-discovers commands in this directory; no manual registration
needed. `$signature` documents every argument/option inline:

```php
class ArchiveStaleAssetsCommand extends Command
{
    protected $signature = 'assets:archive-stale
                            {--days=90 : Age threshold in days}
                            {--force : Actually archive (default is dry-run)}';

    protected $description = 'Archive assets not touched in --days days';

    public function handle(): int
    {
        $isDryRun = ! $this->option('force');

        if ($isDryRun) {
            $this->warn('DRY RUN — no changes will be made. Use --force to apply.');
        }

        // ... find candidates, report via $this->table()/$this->line(), act if !$isDryRun

        return Command::SUCCESS;
    }
}
```

For a command acting on a single looked-up entity (user/token/asset) rather
than a bulk dry-run, follow `TokenRevokeCommand`'s shape instead: look up by
argument/option, `$this->error(...)` + `Command::FAILURE` on a miss, show
what will happen (`$this->table()`), then gate the mutation behind
`$force || $this->confirm(...)`:

```php
if (! $force && ! $this->confirm('Are you sure you want to revoke this token?')) {
    $this->info('Operation cancelled.');
    return Command::SUCCESS;   // cancelling is a success, not a failure
}
```

### 2. Delegate to an existing service, don't inline logic

```php
public function handle(S3Service $s3Service): int
{
    // thin: look up rows, call the service, report the result
}
```

### 3. Test it — `tests/Feature/Console/<Name>CommandTest.php`

```php
test('assets:backfill-etags fetches etags from S3 for assets missing one', function () {
    $asset = Asset::factory()->image()->create(['etag' => null]);

    $mock = Mockery::mock(S3Service::class);
    $mock->shouldReceive('getObjectMetadata')
        ->once()->with($asset->s3_key)
        ->andReturn(['etag' => 'new-etag-123']);
    $this->app->instance(S3Service::class, $mock);

    $this->artisan('assets:backfill-etags')->assertExitCode(0);

    expect($asset->fresh()->etag)->toBe('new-etag-123');
});
```

```bash
./vendor/bin/pint
php artisan config:clear && php artisan test tests/Feature/Console/
```

## Gotchas

- A dry-run-by-default command (`assets:deduplicate`) and a confirm-then-act
  command (`token:revoke`) are both valid shapes — pick dry-run-by-default
  when the operation is a bulk/discovery-style sweep, confirm-then-act for a
  single targeted lookup. Don't mix them (a command that's both dry-run *and*
  prompts is redundant friction).
- A command that fails a lookup should return `Command::FAILURE` (exit 1)
  with a clear `$this->error(...)` message — never silently return
  `Command::SUCCESS` on a miss (REQ-2 of `maintenance-commands.md`).
- `$this->app->instance(Service::class, $mock)` is how these tests swap a
  service for a Mockery double — the command's `handle(Service $service)`
  type-hint is what makes this work; don't `new` the service inside `handle()`.
- If the command is exposed on the System admin dashboard too
  (`SystemService::getSuggestedCommands()`), keep its `$description` accurate
  — it's surfaced there verbatim.
- A command touching `Tag`s should still route through
  `TagInputParser::parse()`/`Tag::resolve*TagIds()` for name normalization
  (trim/lowercase/dedup) rather than reimplementing it — see
  `reference-tag:create` for the existing pattern.

## Scenarios (BDD)

```gherkin
Scenario: assets:backfill-etags fetches missing etags from S3
  Given an asset with a null etag
  When assets:backfill-etags runs and S3Service::getObjectMetadata returns an etag
  Then the asset's etag column is updated
# pinned by: tests/Feature/Console/AssetMaintenanceCommandTest.php
```

## Tests & verification

- `tests/Feature/Console/AssetMaintenanceCommandTest.php`,
  `TokenCommandTest.php`, `JwtCommandTest.php`, `TwoFactorCommandTest.php`,
  `VerifyAssetIntegrityCommandTest.php` — the established
  `$this->artisan('cmd:name', [...])->expectsOutputToContain(...)->assertExitCode(...)`
  pattern, with services swapped for Mockery doubles via
  `$this->app->instance()`.
- `php artisan config:clear && php artisan test tests/Feature/Console/`.
