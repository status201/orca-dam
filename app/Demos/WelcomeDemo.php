<?php

namespace App\Demos;

use App\Models\User;

/**
 * The first-run walkthrough: what the dashboard tells you, then how to find things
 * in the library.
 *
 * Deliberately contains no admin-only functionality, so every role can play it —
 * AssetPolicy::viewAny/view/create admit any known role and Route::resource('assets')
 * carries no `can:` middleware, so admin, editor and api all reach every target here.
 * Anything gated (trash, discovery, export, users, system) belongs in a separate demo.
 *
 * See specs/features/guided-demos.md and specs/recipes/add-a-guided-demo.md.
 */
final class WelcomeDemo implements Demo
{
    public function id(): string
    {
        return 'welcome';
    }

    public function title(): string
    {
        return __('Welcome to ORCA');
    }

    public function description(): string
    {
        return __('A short walk through the dashboard and the asset library.');
    }

    public function isAvailableTo(User $user): bool
    {
        // Every role: nothing this demo points at is gated.
        return true;
    }

    public function nextDemoId(): ?string
    {
        // Named unconditionally — DemoRegistry::next() gates the offer, so a non-admin
        // simply finishes here. The successor does not exist yet.
        return 'admin-basics';
    }

    public function steps(User $user): array
    {
        return [
            // ── the dashboard ──────────────────────────────────────────────────
            new DemoStep(
                target: 'dashboard',
                placement: 'center',
                routeName: 'dashboard',
                title: __('Welcome to ORCA'),
                body: __('ORCA keeps your images, video and documents in one searchable library. This walkthrough points at the handful of controls worth knowing — it takes about a minute.'),
            ),
            new DemoStep(
                target: 'stat-total-assets',
                placement: 'right',
                routeName: 'dashboard',
                reveal: 'scroll-top',
                // On a phone the dashboard reflows; the copy still lands unanchored.
                fallback: 'center',
                title: __('Your library at a glance'),
                body: __('These cards count what is in the library right now. The arrow on a card opens the matching list, so they double as shortcuts.'),
            ),
            new DemoStep(
                target: 'tour',
                placement: 'left',
                routeName: 'dashboard',
                fallback: 'center',
                title: __('The feature carousel'),
                body: __('This panel rotates through every feature you can reach, built from your role. It is the quickest index of what ORCA can do.'),
            ),
            new DemoStep(
                target: ['nav-assets', 'nav-mobile-assets'],
                placement: 'bottom',
                routeName: 'dashboard',
                reveal: 'scroll-top',
                fallback: 'center',
                title: __('Everything starts under Assets'),
                body: __('Browsing, uploading and the trash all hang off this menu. It stays with you on every page.'),
            ),
            new DemoStep(
                target: ['nav-user-menu', 'nav-mobile-profile'],
                placement: 'bottom',
                routeName: 'dashboard',
                fallback: 'center',
                title: __('Your own settings'),
                body: __('Your profile, interface language, dark mode and how many results you see per page all live behind your name. Next, we will open the library itself.'),
            ),

            // ── the library ────────────────────────────────────────────────────
            new DemoStep(
                target: 'grid-total',
                placement: 'bottom',
                routeName: 'assets.index',
                reveal: 'scroll-top',
                title: __('This is the library'),
                body: __('The count reflects the filters you have applied, not the whole bucket — so it tells you straight away whether a search actually narrowed anything down.'),
            ),
            new DemoStep(
                target: ['grid-search', 'grid-search-mobile'],
                placement: 'bottom',
                routeName: 'assets.index',
                advanceOn: ['event' => 'input', 'on' => 'self', 'minLength' => 3],
                title: __('Search'),
                body: __('Type part of a filename or a tag. Have a go — search understands operators too, so you can require or exclude terms.'),
            ),
            new DemoStep(
                target: 'grid-filter-folder',
                placement: 'bottom',
                routeName: 'assets.index',
                title: __('Narrow by folder'),
                body: __('Folders mirror how the files are stored. Pick one to scope everything below it.'),
            ),
            new DemoStep(
                target: 'grid-filter-type',
                placement: 'bottom',
                routeName: 'assets.index',
                title: __('Narrow by file type'),
                body: __('Images, video, documents. Combine a type with a folder or a tag to zero in on one thing.'),
            ),
            new DemoStep(
                target: 'grid-filter-tags',
                placement: 'bottom',
                routeName: 'assets.index',
                advanceOn: ['event' => 'click', 'on' => 'self'],
                title: __('Narrow by tag'),
                body: __('Tags are how ORCA finds things nobody named well. Open the tag filter to see them.'),
            ),
            new DemoStep(
                target: 'grid-tag-filter-panel',
                placement: 'bottom',
                routeName: 'assets.index',
                reveal: ['click' => 'grid-filter-tags'],
                fallback: 'center',
                title: __('Tags, in one place'),
                body: __('Search the tags, sort them, pin the ones you use constantly. Some tags are added by hand, others by image recognition.'),
            ),
            new DemoStep(
                target: 'grid-sort',
                placement: 'bottom',
                routeName: 'assets.index',
                title: __('Sorting'),
                body: __('Newest first by default. Sort by name when you know roughly what it is called, or by size when you are hunting for the big files.'),
            ),
            // The three view modes get a step each, and each one switches the library into
            // that view before it speaks — being told the views differ teaches far less
            // than watching the same assets rearrange. `until` names what the click should
            // produce, because the target here *is* the toggle: without it the reveal's
            // idempotency check would never fire (a view button is always visible).
            new DemoStep(
                target: 'grid-view-grid',
                placement: 'bottom',
                routeName: 'assets.index',
                reveal: ['click' => 'grid-view-grid', 'until' => 'asset-grid-view'],
                title: __('Grid — for scanning a lot at once'),
                body: __('Uniform square tiles, up to twelve across on a wide screen, so the most assets fit at once. Every tile is the same size whatever shape the file is, which makes this the quickest view for spotting something you would recognise on sight. The crop and fit buttons to the left decide whether an image is zoomed to fill its tile or shrunk to fit inside it.'),
            ),
            new DemoStep(
                target: 'asset-card',
                placement: 'right',
                routeName: 'assets.index',
                // Guaranteed grid view by the step above, so the copy can safely talk about
                // the hover controls. An empty or fully-filtered library has no cards at
                // all, though — and that is exactly when a newcomer most needs telling what
                // one contains.
                fallback: 'center',
                title: __('What a tile tells you'),
                body: __('Filename, and the first couple of tags with a count for the rest. Hover a tile for a straight download and a shortcut to its metadata; click it to open the asset in full, with its dimensions, licence and public URL.'),
            ),
            new DemoStep(
                target: 'grid-view-masonry',
                placement: 'bottom',
                routeName: 'assets.index',
                reveal: ['click' => 'grid-view-masonry', 'until' => 'asset-masonry-view'],
                title: __('Masonry — for judging images by shape'),
                body: __('Nothing is cropped here: every image keeps its own proportions, so a panorama looks like a panorama and a portrait like a portrait, and the columns read top to bottom. Reach for it when you are choosing between images and the composition matters — which is also why the crop and fit buttons disappear in this view.'),
            ),
            new DemoStep(
                target: 'grid-view-list',
                placement: 'bottom',
                routeName: 'assets.index',
                reveal: ['click' => 'grid-view-list', 'until' => 'asset-list-view'],
                title: __('List — the working view'),
                body: __('One row per asset, with filename, S3 key, file size and dimensions in columns you can compare straight down the page. It is the only view you can edit from without leaving it: add a tag or change a licence in the row itself. Each row also carries all four actions — open, edit, replace and delete — where a tile offers two.'),
            ),
            new DemoStep(
                target: 'grid-upload',
                placement: 'left',
                routeName: 'assets.index',
                title: __('Adding your own'),
                body: __('Drag files in, up to 500 MB each. Large files are split up automatically, so a flaky connection will not cost you an upload.'),
            ),
        ];
    }
}
