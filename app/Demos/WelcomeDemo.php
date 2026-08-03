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
            new DemoStep(
                target: 'grid-view-list',
                placement: 'bottom',
                routeName: 'assets.index',
                title: __('Three ways to look'),
                body: __('Grid for browsing, masonry for images of mixed shapes, list when you want filenames, sizes and tags side by side.'),
            ),
            new DemoStep(
                target: ['asset-card', 'asset-masonry-card', 'asset-row'],
                placement: 'right',
                routeName: 'assets.index',
                // An empty or fully-filtered library has no cards, and that is exactly
                // when a newcomer most needs to be told what one looks like.
                fallback: 'center',
                title: __('One card per asset'),
                body: __('Open an asset to see everything about it. You can also edit its tags and licence straight from the list, without leaving the page.'),
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
