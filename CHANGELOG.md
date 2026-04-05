# Changelog

All notable changes to ORCA DAM will be documented in this file.

---

## [Unreleased] — Riptide

### Upgraded
- Laravel 12 → **Laravel 13.3** (framework, Symfony 8.x components)
- PHPUnit 11 → **PHPUnit 12.5**
- Pest 3.8 → **Pest 4.4**
- laravel/tinker 2.x → **3.0**
- pragmarx/google2fa-laravel 2.x → **3.0**

### Added
- `serializable_classes` security hardening in `config/cache.php` (set to `false` — safe default since ORCA only caches scalars and arrays)

### Notes
- No application code changes required — ORCA was already compatible with all Laravel 13 breaking changes
- Session cookies and cache prefixes unchanged (published configs with explicit format)
- All 643 tests passing with 1755 assertions
