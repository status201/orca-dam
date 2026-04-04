---
name: laravel-13-upgrade
description: Upgrade the ORCA DAM project from Laravel 12 to Laravel 13. Use when working on the Laravel version migration, upgrading framework dependencies, or identifying and fixing Laravel 13 breaking changes in ORCA.
---

# Laravel 12 → 13 Upgrade — ORCA DAM

You are performing a careful, systematic upgrade of the ORCA DAM project from Laravel 12 to Laravel 13. Laravel 13 is a lightweight release — estimated upgrade time is ~10 minutes for a typical project. Most breaking changes are low-impact and may not affect ORCA at all.

**Before touching any code:** read `references/laravel-13-upgrade-guide.md` for the complete list of breaking changes. Read `references/orca-context.md` for ORCA-specific notes that affect how you prioritise and apply changes.

---

## Process

Work through the following phases in order. Do not skip phases. Do not apply changes speculatively — only fix what you can confirm is present in the codebase.

### Phase 1 — Establish baseline

1. Confirm the current installed version:
   ```bash
   composer show laravel/framework | grep versions
   ```
2. Run the full test suite and record the result. This is your baseline — every phase should end with tests at least as green as this.
3. Create or confirm you are on a dedicated branch (e.g. `upgrade/laravel-13`).

### Phase 2 — Search for affected patterns

Search the codebase systematically. Group findings by impact tier before making any changes.

**High impact (likely to break immediately):**
- `VerifyCsrfToken` or `ValidateCsrfToken` → must rename to `PreventRequestForgery`
- `composer.json` → version constraints for `laravel/framework`, `phpunit/phpunit`, `pestphp/pest`

**Medium impact (breaks if you cache PHP objects):**
- `config/cache.php` → check for `serializable_classes`; if absent, add it
- Any code that puts PHP objects into the cache

**Low impact (only if ORCA uses these features):**
- `$event->exceptionOccurred` on `JobAttempted` → renamed to `$event->exception`
- `$event->connection` on `QueueBusy` → renamed to `$event->connectionName`
- `'pagination::default'` or `'pagination::simple-default'` view references
- Manager `extend` callbacks using `$this`
- Custom `Str` factories in tests
- `Container::call` with nullable class defaults

Use `grep -r` or the Glob/Grep tools to search. List every affected file before making any edits.

### Phase 3 — Apply changes

For each impact tier, work top-down:

1. **List** all files that need to change (confirm with Grep before editing).
2. **Apply** the fix consistently across all occurrences in one pass.
3. **Run tests** after completing each tier.

See `references/laravel-13-upgrade-guide.md` for exact before/after code patterns for every change.

### Phase 4 — Update dependencies

Once all code changes are in place and tests are green:

```bash
composer require laravel/framework:^13.0 --with-all-dependencies
```

If you also use PHPUnit or Pest:
```bash
# PHPUnit
composer require --dev phpunit/phpunit:^12.0

# Pest (if used)
composer require --dev pestphp/pest:^4.0
```

Run `composer install` and then the full test suite again.

### Phase 5 — Verify runtime behaviour

After dependency update:

- [ ] CSRF protection works (submit a form, confirm no 419 errors)
- [ ] Cache read/write round-trips work (check both file and Redis drivers if used)
- [ ] Any queue listeners that reference event properties behave correctly
- [ ] Session cookie name hasn't changed unexpectedly (check browser devtools)
- [ ] Image upload and retrieval flows work end-to-end

### Phase 6 — Document

Update `CHANGELOG.md` (follow ORCA's ocean-themed release naming convention) and note the Laravel version in `README.md` if it appears there.

---

## Key rules

- **Never rename a class without confirming it exists in the codebase first.**
- **Never edit `composer.json` version constraints by hand** — use `composer require` so the lockfile stays consistent.
- **If a test fails after a change, fix it before moving to the next tier.** Do not accumulate failures.
- **Consult `references/laravel-13-upgrade-guide.md`** before implementing any pattern — it contains the authoritative before/after examples.
- When uncertain about current Laravel 13 behaviour, fetch the official docs directly:
  ```
  https://laravel.com/docs/13.x/upgrade
  ```
