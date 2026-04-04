# ORCA DAM — Project Context for Laravel 13 Upgrade

Read this before starting the upgrade. It describes what ORCA is, its stack, and which parts of the Laravel 13 upgrade guide are most and least likely to matter.

---

## What ORCA is

ORCA is a Digital Asset Management (DAM) system developed under the GitHub handle `status201`. It is a separate project from StudyFlow's Spark platform. Its primary responsibilities are:

- Storing, organising, and serving digital assets (images, documents)
- Providing a browsable asset library with a modal/picker interface for embedding in external tools
- JWT-authenticated API access (used e.g. by the TinyMCE integration)
- Image refresh and version management
- Internationalisation (i18n) support

## Stack

- **Backend:** Laravel (PHP), with Alpine.js for lightweight frontend interactivity
- **Templating:** Laravel Blade
- **Authentication:** JWT (for API consumers); standard Laravel session auth for the admin UI
- **Asset handling:** Image upload, storage, and retrieval
- **Deployment:** Hetzner server, managed via Laravel Forge

## Release naming convention

ORCA releases follow an ocean/orca-themed naming tradition. Releases through *Spindrift* (release 9) have been named collaboratively. When updating `CHANGELOG.md` as part of this upgrade, follow the same convention for the new release name.

---

## Upgrade prioritisation for ORCA

### Almost certainly relevant

- **CSRF middleware rename (§2):** ORCA has a web UI with forms (image uploads, asset management). Any middleware exclusions or references to `VerifyCsrfToken` / `ValidateCsrfToken` must be updated.
- **Dependency constraints (§1):** Always required.

### Check carefully

- **Cache serializable_classes (§3):** ORCA caches asset metadata. Confirm whether cached values are PHP objects (Eloquent models, value objects) or plain arrays/scalars. If objects, allowlist them; if not, set `false`.
- **Session cookie name (§4):** ORCA has a logged-in admin UI. A cookie name change will invalidate sessions on deploy. Decide whether to pin the old name or accept a forced logout.
- **Cache prefix (§4):** If ORCA uses Redis or a shared cache, a prefix change means a cold cache on deploy. Acceptable for most deploys but worth noting.

### Probably not relevant — but search to confirm

- **`JobAttempted` / `QueueBusy` events (§7, §8):** Only relevant if ORCA has queue listeners that inspect these specific events. ORCA does use queues (for image processing), so check.
- **Manager `extend` callbacks (§9):** Only relevant if ORCA registers custom cache, queue, or filesystem drivers via `extend`.
- **MySQL `DELETE` with `JOIN` (§10):** Only relevant if ORCA has joined delete queries. Check the `AssetRepository` or any bulk-delete logic.
- **Pagination views (§11):** Only relevant if ORCA references pagination view names directly in Blade templates or PHP. Most apps use the default — just confirm.
- **Polymorphic pivots (§12):** Check if ORCA uses polymorphic many-to-many relationships (e.g. assets tagged with multiple tag types).
- **`Str` factories in tests (§14):** Only relevant if ORCA tests generate UUIDs or ULIDs with custom factories.
- **Model boot nested instantiation (§15):** Search for any `boot()` or `booted()` methods that instantiate the same model class.

### Very unlikely to be relevant

- Contract changes (§ "Very-low-impact"): Only relevant if ORCA ships custom implementations of framework contracts (custom cache store, custom queue driver, custom dispatcher, etc.).
- `Container::call` nullable defaults (§5): Only relevant in very specific dependency injection patterns.
- Domain route precedence (§6): ORCA does not appear to use subdomain routing.

---

## Notes on the JWT layer

The JWT authentication layer (used by the TinyMCE + ORCA DAM integration) operates on stateless API routes. These routes are typically excluded from CSRF middleware. After renaming `VerifyCsrfToken` → `PreventRequestForgery`, **confirm that the API route exclusions are still correctly wired** — a misconfiguration here could silently remove CSRF protection from web routes or break the API.

---

## After the upgrade

- Update `CHANGELOG.md` with a new ocean-themed release name
- Note the Laravel version in `README.md` if referenced there
- Tag the release on GitHub following ORCA's existing tag convention
