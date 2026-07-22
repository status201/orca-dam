# SDD backfill status

The multi-session ledger for bootstrapping SDD in ORCA DAM. Underscore-prefixed so
`spec-lint.mjs` skips it as a spec (it still must appear in the README folder map
until removed). **Deleted in Phase 6** once the corpus is complete and the gate is
armed. Tick items as they land; keep the tree committed between waves.

Every backfilled spec documents code that **already ships** → `status: implemented`,
`version: 1`. Every Gherkin scenario ends with `# pinned by: tests/...` pointing at a
**real** test (grep the suite first). A behaviour with no test → a finding under the
spec's "Open questions / future", not a fabricated pin.

## Phase 1 — Scaffolding
- [x] `specs/README.md` (SDD method, Laravel-tailored)
- [x] `specs/architecture.md`
- [x] `specs/_backfill-status.md`
- [x] `specs/features/_feature-template.md`
- [x] `specs/recipes/_recipe-template.md`
- [x] `specs/decisions/README.md`
- [x] `specs/decisions/_adr-template.md`

## Phase 2 — ADRs (14) ✅
- [x] adr-000-spec-driven-development
- [x] adr-001-service-layer
- [x] adr-002-explicit-policy-roles
- [x] adr-003-soft-delete-keeps-s3
- [x] adr-004-auth-multi
- [x] adr-005-chunked-above-10mb
- [x] adr-006-immutable-s3-key
- [x] adr-007-blade-alpine-over-spa
- [x] adr-008-sqlite-tests
- [x] adr-009-project-owns-nl-json
- [x] adr-010-services-swallow-controllers-map
- [x] adr-011-settings-in-db
- [x] adr-012-reference-tags-api-only
- [x] adr-013-wordpress-plugin-separate-stream

## Phase 3 — Feature specs

### Wave A — assets & storage (13)
- [ ] asset-model
- [ ] asset-upload
- [ ] chunked-upload
- [ ] duplicate-detection
- [ ] s3-storage
- [ ] image-processing
- [ ] asset-replace
- [ ] asset-trash
- [ ] bulk-operations
- [ ] asset-search
- [ ] s3-integrity
- [ ] discovery-import
- [ ] csv-export-import

### Wave B — tags (3)
- [ ] tags
- [ ] tag-input
- [ ] ai-tagging

### Wave C — auth & access (7)
- [ ] authentication
- [ ] authorization-policies
- [ ] api-tokens-sanctum
- [ ] jwt-auth
- [ ] passkeys
- [ ] two-factor-auth
- [ ] user-management

### Wave D — API (3)
- [ ] rest-api
- [ ] reference-tags-api
- [ ] api-docs-admin

### Wave E — platform / cross-cutting (8)
- [ ] settings
- [ ] localization
- [ ] security-headers
- [ ] iframe-embedding
- [ ] upload-policy
- [ ] cloudflare-purge
- [ ] user-preferences
- [ ] queue-jobs

### Wave F — system & tools (5)
- [ ] system-admin
- [ ] maintenance-commands
- [ ] tikz-render
- [ ] client-side-tools
- [ ] easter-egg-game

## Phase 4 — Recipes (10)
- [ ] add-a-service
- [ ] add-an-api-endpoint
- [ ] add-a-policy-ability
- [ ] add-a-migration
- [ ] add-an-alpine-module
- [ ] add-a-console-command
- [ ] add-a-queued-job
- [ ] add-a-setting
- [ ] add-a-translated-string
- [ ] write-a-test

## Phase 5 — Build the gate (unarmed)
- [ ] scripts/sdd-guard.mjs (isProductionPath + PROCEDURE)
- [ ] scripts/spec-lint.mjs (PRUNE + pinnedPaths)
- [ ] .github/workflows/sdd.yml
- [ ] package.json spec:lint
- [ ] .gitignore .sdd-skip
- [ ] .claude/commands/{feature,fix,spec}.md
- [ ] CLAUDE.md / CONTRIBUTING.md / agents / CHANGELOG

## Phase 6 — Arm + verify
- [ ] .claude/settings.json (merge, keep both existing guards)
- [ ] remove this file + its folder-map line
- [ ] spec:lint / pint / test green
- [ ] prove all 6 gate behaviours
- [ ] push branch + open PR
