# Easter-egg game & leaderboard

```yaml
id: easter-egg-game
status: implemented
version: 1
owner: core
related:
  - architecture
source:
  - app/Models/GameScore.php
  - app/Http/Controllers/GameScoreController.php
  - resources/js/app.js
  - database/migrations/2026_04_02_000000_create_game_scores_table.php
  - database/migrations/2026_04_12_000003_add_leaderboard_index_to_game_scores.php
```

## Background / Why

A hidden, purely cosmetic easter egg: double-clicking the ORCA logo lazy-loads a
small game bundle from `public/games/` (not built by Vite — plain static
JS/CSS shipped alongside the app) and records scores against the authenticated
user. It exists for morale, not for any product requirement — kept intentionally
minimal (one model, one controller, two routes) and isolated so it can't affect
core DAM behaviour.

## Requirements

- **REQ-1** — The game bundle (`/games/orca-game.js`, `/games/orca-game.css`) is
  only fetched on the *second* click of the ORCA logo within 1 second of the
  first, and only once per page load (`window.__orcaGameLoaded` guard) — it never
  loads eagerly on initial page render.
- **REQ-2** — Score submission and leaderboard reads require authentication
  (`game/scores` sits in the standard `auth` route group); there is no role
  restriction — any authenticated user (admin/editor/api) can play and appear on
  the leaderboard.
- **REQ-3** — `score` must be an integer in `[1, 999999]`; out-of-range or
  non-integer submissions are rejected with a validation error and never reach the
  `game_scores` table.
- **REQ-4** — The leaderboard shows each user's **best** score only (not every
  submission), ordered descending, capped at the top 5.

## Technical design

### Contract / public interface

```yaml
GameScore (Model, belongsTo User):
  fillable: [user_id, score]
  leaderboard(int $limit = 5): Collection   # static — [{name, score}], best-per-user, desc, capped

GameScoreController:
  index()          # GET  game/scores        -> {leaderboard: [...]}
  store(Request)   # POST game/scores        -> validate score:required|integer|min:1|max:999999
                    #                          -> GameScore::create() -> {leaderboard: [...]}
```

### Data shapes

```yaml
game_scores:
  id: bigint
  user_id: bigint            # FK -> users.id, cascadeOnDelete
  score: unsigned int
  created_at / updated_at
  # composite index (user_id, score) for the leaderboard's GROUP BY user_id, MAX(score);
  # replaces an earlier single-column index on score alone (redundant with the composite)

# leaderboard() response shape
[{name: string, score: int}, ...]   # up to 5 entries, one per distinct user_id, desc by MAX(score)
```

### Layer touchpoints & ordering

`resources/js/app.js`'s `DOMContentLoaded` handler wires a click listener on
`#orca-logo-container`: two clicks within 1000ms trigger a one-time lazy
`<link>`/`<script>` injection for `/games/orca-game.css` and
`/games/orca-game.js`, then calls `window.OrcaGame.init()` once the script
loads (or immediately on subsequent triggers, via
`window.__orcaGameScriptLoaded`). The game bundle itself is a static asset in
`public/games/`, entirely outside the Vite build and outside this spec's
server-side contract — only the two `game/scores` endpoints and the `GameScore`
model are part of the backend surface.

### Persistence

`game_scores` table only. `leaderboard()` is a single query
(`GROUP BY user_id` + `MAX(score)` joined to `users` for the display name) — no
caching, since it's a low-traffic easter egg.

## Scenarios (BDD)

```gherkin
Scenario: Guests cannot view or submit to the leaderboard
  Given an unauthenticated visitor
  When they GET game/scores or POST game/scores
  Then both are redirected to login
# pinned by: tests/Feature/GameScoreTest.php

Scenario: An authenticated user can fetch the leaderboard
  Given any authenticated user
  When they GET game/scores
  Then the response is 200 with a leaderboard array
# pinned by: tests/Feature/GameScoreTest.php

Scenario: Submitting a score persists it and returns the updated leaderboard
  Given an authenticated user
  When they POST game/scores with score=1234
  Then a game_scores row is created for that user
  And the response's leaderboard reflects it
# pinned by: tests/Feature/GameScoreTest.php

Scenario: Out-of-range or non-integer scores are rejected
  Given an authenticated user
  When they POST game/scores with score 0, 1000000, -5, "abc", or missing
  Then each is rejected with a validation error and no row is created
# pinned by: tests/Feature/GameScoreTest.php

Scenario: The leaderboard shows each user's best score only, ordered desc, capped at five
  Given one user has three scores (10, 90, 50) and five other users have one score each
  When the leaderboard is fetched
  Then it shows exactly 5 entries, the multi-score user's entry uses their best (90),
    and the lowest of the six distinct scores is excluded by the cap
# pinned by: tests/Feature/GameScoreTest.php
```

## Tests & verification

- Feature: `tests/Feature/GameScoreTest.php` — `php artisan config:clear && php artisan test`

## Open questions / future

- The client-side game bundle (`public/games/orca-game.js`/`.css`) and the
  double-click lazy-load wiring in `resources/js/app.js` have no automated test
  coverage (no Dusk/browser-level suite in this project) — only the backend
  `GameScore`/`GameScoreController` surface is Pest-tested. Low risk given the
  feature is cosmetic and isolated from core DAM flows.
