// The dashboard feature tour — pins the tour section of specs/features/dashboard.md.
//
// Autoplay advances the carousel every 7s, which is short enough to race an
// assertion. Every navigation handler calls pauseAutoPlay(), so each test clicks
// a control first: that both fixes the slide position and kills the interval, and
// everything after it is deterministic.
//
// Slide counts are role-dependent (admins get extra slides, and the passkey promo
// only appears for a user without one), so nothing here hardcodes a total — the
// assertions are all relative to what the page reports.
import { expect, test, testid } from './support/fixtures.js';

const current = (page) => page.locator(testid('tour-current'));
const total = (page) => page.locator(testid('tour-total'));

/** Park the tour on its first slide with autoplay stopped. */
async function parkOnFirstSlide(page) {
    await page.goto('/dashboard');
    await expect(page.locator(testid('tour'))).toBeVisible();

    await page.locator(testid('tour-dot')).first().click();
    await expect(page.locator(testid('tour-autoplay'))).toHaveAttribute('data-playing', 'false');
    await expect(current(page)).toHaveText('1');
}

test.describe('dashboard feature tour', () => {
    test('the tour renders one dot per slide', async ({ page }) => {
        await page.goto('/dashboard');
        await expect(page.locator(testid('tour'))).toBeVisible();

        const slides = Number(await total(page).textContent());
        expect(slides).toBeGreaterThan(1);
        await expect(page.locator(testid('tour-dot'))).toHaveCount(slides);
    });

    test('next and previous step through the slides', async ({ page }) => {
        await parkOnFirstSlide(page);

        await page.click(testid('tour-next'));
        await expect(current(page)).toHaveText('2');

        await page.click(testid('tour-prev'));
        await expect(current(page)).toHaveText('1');
    });

    test('previous from the first slide wraps to the last', async ({ page }) => {
        await parkOnFirstSlide(page);

        await page.click(testid('tour-prev'));

        await expect(current(page)).toHaveText(await total(page).textContent());
    });

    test('a dot jumps straight to its slide', async ({ page }) => {
        await parkOnFirstSlide(page);

        await page.locator(`${testid('tour-dot')}[data-slide="2"]`).click();

        await expect(current(page)).toHaveText('3');
    });

    test('the autoplay toggle stops and restarts the carousel', async ({ page }) => {
        await page.goto('/dashboard');
        const toggle = page.locator(testid('tour-autoplay'));

        // It starts playing, so the first click must stop it.
        await expect(toggle).toHaveAttribute('data-playing', 'true');
        await toggle.click();
        await expect(toggle).toHaveAttribute('data-playing', 'false');

        await toggle.click();
        await expect(toggle).toHaveAttribute('data-playing', 'true');
    });
});
