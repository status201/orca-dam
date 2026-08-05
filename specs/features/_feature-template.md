<!--
  Copy this file to <name>.md in this folder (features/) and fill it in. This is
  the FEATURE template (the "what"). For a repeatable how-to use the recipe
  template (../recipes/_recipe-template.md); for an architectural decision use
  ../decisions/_adr-template.md.
  Keep it lean (see ../README.md "Format rules"): Markdown for narrative, flat
  YAML for structured schemas, reference code by symbol name not line number.
  Delete any section that genuinely does not apply — don't leave empty headings.
-->

# <Feature name>

```yaml
id: <kebab-case-id>          # matches the filename
status: draft                # draft | active | implemented
version: 1                   # integer; bump when ## Requirements or ## Technical design
                             # changes — enforced by scripts/sdd-guard.mjs. Scenarios,
                             # tests, background and open questions do not bump it.
owner: <name or team>
related:                     # other specs this depends on / extends
  - architecture
source:                      # the code that implements this spec
  - app/...
```

## Background / Why

<!-- The context behind the "what". What problem does this solve? What was the
     state before, and what is the intended outcome? Enough for an agent to reason
     ahead about steps it will need. 2-5 sentences. -->

## Requirements

<!-- The design broken into discrete, testable pieces. -->

- **REQ-1** — …
- **REQ-2** — …
- **REQ-3** — …

## Technical design

### Contract / public interface

<!-- The methods/types/routes other layers call. The surface a re-implementation
     must preserve: controller actions + routes, service methods, model scopes,
     policy abilities, request/response JSON shapes. Reference by symbol + path. -->

### Data shapes

<!-- Schemas — DB columns, request payloads, JSON responses. Use a flat YAML block
     when nesting goes beyond ~3 levels. -->

```yaml
# example — the persisted shape
Asset:
  id: int
  s3_key: string   # unique, immutable
  filename: string
  mime_type: string
  size: int
```

### Layer touchpoints & ordering

<!-- Which files collaborate, and any ordering constraints
     (request → middleware → controller → service → S3/queue). -->

### Persistence

<!-- DB tables/columns, S3 key layout, cache keys, AND what is deliberately NOT
     persisted. Delete if the feature holds no persistent state. -->

## Visual aids

<!-- A diagram if it clarifies, plus tools/libraries with explicit versions if
     the feature pulls any in. -->

## Scenarios (BDD)

<!-- If this feature is also driven in a browser, put those scenarios in a
     block at the END of this section, under the marker comment below, and pin
     them to the tests/e2e/*.spec.js file. The owning feature spec pins its own
     E2E test — e2e-testing.md owns only the harness (seeding, role sessions,
     the S3 skip). See ../recipes/write-an-e2e-test.md. -->

```gherkin
Scenario: <happy path>
  Given <state>
  When <action>
  Then <outcome>
# pinned by: tests/Feature/..., tests/Unit/...

Scenario: <failure / edge case>
  Given <state>
  When <action>
  Then <outcome>
# pinned by: tests/Feature/...

# — browser-level (see e2e-testing.md for the harness) —

Scenario: <what the browser proves that a Feature test cannot>
  Given <state>
  When <action>
  Then <outcome>
# pinned by: tests/e2e/<area>.spec.js
```

## Tests & verification

<!-- Which suites exercise this, and how to run them. -->

- Unit: `tests/Unit/...` — `php artisan config:clear && php artisan test`
- Feature: `tests/Feature/...` — same
- E2E: `tests/e2e/<area>.spec.js` — `npm run test:e2e` (delete if not driven in a browser)
- Style: `./vendor/bin/pint --test`
- Manual check: exercise the route/HTTP call, or assert state via `php artisan tinker`.

## Open questions / future

- …
