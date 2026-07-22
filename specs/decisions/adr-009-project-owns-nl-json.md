# ADR-009 — The project owns `lang/nl.json` — `lang:safe-update`, never raw `lang:update`

```yaml
id: adr-009-project-owns-nl-json
status: accepted
date: 2026-07-22
deciders: core
related:
  - ../architecture
  - ../features/localization
  - ../recipes/add-a-translated-string
```

## Context / Forces

ORCA is bilingual (`en`, `nl`). Framework strings (validation/auth/passwords) come
from the `laravel-lang/common` dev dependency and are refreshed with a Laravel
command. But ORCA's **own** UI strings — every `__()` key — live in `lang/nl.json`,
and the stock `php artisan lang:update` / `lang:add nl` **overwrites that file with
the package's version**, silently destroying project translations.

## Decision

Treat `lang/nl.json` as **project-owned and hand-maintained**. Refresh framework
strings only via a wrapper, `php artisan lang:safe-update`, which protects
`nl.json`. Raw `lang:update` / `lang:add nl` are **forbidden**: a PreToolUse hook
(`guard-lang-update.cjs`) blocks them, and `TranslationIntegrityTest` guards sentinel
keys + completeness. Every new `__()` string gets a Dutch entry, inserted in
alphabetical order.

## Alternatives considered

- **Let `lang:update` manage everything** — rejected: it clobbers project
  translations; that's the exact bug this ADR exists to prevent.
- **Move ORCA strings into PHP array files (`lang/nl/app.php`)** — reasonable, but
  the app already uses JSON `__()` keys pervasively and tooling/toasts read the JSON
  channels; migrating now is churn without benefit.
- **A translation-management SaaS** — rejected as overkill for two languages
  maintained in-repo.

## Consequences

- **Good:** project translations are safe; the guard + integrity test make the
  footgun mechanically impossible to fire by accident.
- **Good:** a single obvious command (`lang:safe-update`) for framework refreshes.
- **Trade-off:** contributors must know the rule and keep `nl.json` alphabetized and
  complete by hand; adding a string is a two-file discipline.
