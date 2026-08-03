/**
 * Geometry for the guided-demo spotlight. Pure functions — no Alpine, no DOM writes.
 *
 * A mixin, not a registered module: it is imported by guided-demo.js and never referenced
 * from a Blade `x-data`, so it does not count toward the module total in app.js.
 *
 * The spotlight is the *absence* of scrim: four fixed rectangles surround the target and
 * the hole is simply where they aren't. That is forced rather than stylistic — the nav
 * carries a transform while hiding, which makes it a stacking context, so raising a nav
 * link's z-index would still paint it under the scrim. Not touching the target also means
 * clicks inside the hole reach the real element, which is what makes act-to-advance work.
 *
 * Every value returned here is an inline style string in pixels. No class name is ever
 * computed, so nothing depends on Tailwind's content scanner.
 */

/** Padding between the target's box and the highlight ring. */
export const RING_PAD = 6;

/** Gap between the ring and the popover. */
export const POPOVER_GAP = 12;

/** Popover box used for placement maths; the real element is clamped to it by CSS. */
export const POPOVER_W = 340;
export const POPOVER_H = 190;

/** Below this viewport width the popover becomes a bottom sheet. */
export const SHEET_BREAKPOINT = 640;

const px = (n) => `${Math.round(n)}px`;

/**
 * The padded rectangle the spotlight cuts, clamped to the viewport so a partially
 * off-screen target still produces a sane hole.
 *
 * Edges are rounded to whole pixels *before* the width and height are derived from them,
 * so the click-blocking panels and the spotlight always agree on where the boundary is.
 * Rounding each of top/left/width/height independently instead leaves them disagreeing by
 * a pixel whenever the target sits on a fractional offset — which, with rem-based
 * spacing, is most of the time.
 */
export function holeRect(rect) {
    const vw = window.innerWidth;
    const vh = window.innerHeight;

    const top = Math.round(Math.max(0, rect.top - RING_PAD));
    const left = Math.round(Math.max(0, rect.left - RING_PAD));
    const bottom = Math.round(Math.min(vh, rect.bottom + RING_PAD));
    const right = Math.round(Math.min(vw, rect.right + RING_PAD));

    return {
        top,
        left,
        width: Math.max(0, right - left),
        height: Math.max(0, bottom - top),
        bottom,
        right,
    };
}

/**
 * Inline style for one of the four click-blocking panels around `hole`.
 *
 * These are transparent: the dimming is the spotlight's own outer box-shadow (see
 * app.css). They exist only so a click outside the hole is swallowed while a click inside
 * it still reaches the real element — a box-shadow is not hit-tested.
 *
 * With no hole an unanchored step is dimmed by the single full-viewport veil instead, so
 * all four collapse.
 */
export function shutterStyle(side, hole) {
    if (!hole) {
        return 'display:none;';
    }

    switch (side) {
        case 'top':
            return `top:0;left:0;right:0;height:${px(hole.top)};`;
        case 'bottom':
            return `top:${px(hole.bottom)};left:0;right:0;bottom:0;`;
        case 'left':
            return `top:${px(hole.top)};left:0;width:${px(hole.left)};height:${px(hole.height)};`;
        case 'right':
            return `top:${px(hole.top)};left:${px(hole.right)};right:0;height:${px(hole.height)};`;
        default:
            return 'display:none;';
    }
}

/** Inline style for the highlight ring, which traces the hole exactly. */
export function ringStyle(hole) {
    if (!hole) {
        return 'display:none;';
    }

    return `top:${px(hole.top)};left:${px(hole.left)};width:${px(hole.width)};height:${px(hole.height)};`;
}

/**
 * Where the popover actually goes.
 *
 * Returns `{ style, placement }` — the resolved placement may differ from the requested
 * one, because a side with no room flips to its opposite rather than rendering off-screen.
 * The resolved value is what the overlay reports as `data-placement`, so a test asserts
 * what happened rather than what was asked for.
 */
export function popoverStyle(hole, requested) {
    const vw = window.innerWidth;
    const vh = window.innerHeight;

    if (vw < SHEET_BREAKPOINT) {
        return { style: 'left:0.5rem;right:0.5rem;bottom:0.5rem;top:auto;', placement: 'sheet' };
    }

    if (!hole || requested === 'center') {
        return {
            style: `top:50%;left:50%;transform:translate(-50%,-50%);width:${px(Math.min(POPOVER_W, vw - 32))};`,
            placement: 'center',
        };
    }

    const fits = {
        bottom: vh - hole.bottom >= POPOVER_H + POPOVER_GAP,
        top: hole.top >= POPOVER_H + POPOVER_GAP,
        right: vw - hole.right >= POPOVER_W + POPOVER_GAP,
        left: hole.left >= POPOVER_W + POPOVER_GAP,
    };

    // Flip to the opposite side when the requested one has no room; if neither side of the
    // axis fits, fall through to whichever side does, then to a centered card.
    const order = {
        bottom: ['bottom', 'top', 'right', 'left'],
        top: ['top', 'bottom', 'right', 'left'],
        right: ['right', 'left', 'bottom', 'top'],
        left: ['left', 'right', 'bottom', 'top'],
    }[requested] || ['bottom', 'top', 'right', 'left'];

    const placement = order.find((side) => fits[side]);

    if (!placement) {
        return popoverStyle(null, 'center');
    }

    const width = Math.min(POPOVER_W, vw - 32);
    const clamp = (v, min, max) => Math.max(min, Math.min(v, max));

    if (placement === 'bottom' || placement === 'top') {
        const left = clamp(hole.left + hole.width / 2 - width / 2, 16, vw - width - 16);
        const style = placement === 'bottom'
            ? `top:${px(hole.bottom + POPOVER_GAP)};left:${px(left)};width:${px(width)};`
            : `bottom:${px(vh - hole.top + POPOVER_GAP)};left:${px(left)};width:${px(width)};`;

        return { style, placement };
    }

    const top = clamp(hole.top + hole.height / 2 - POPOVER_H / 2, 16, Math.max(16, vh - POPOVER_H - 16));
    const style = placement === 'right'
        ? `top:${px(top)};left:${px(hole.right + POPOVER_GAP)};width:${px(width)};`
        : `top:${px(top)};right:${px(vw - hole.left + POPOVER_GAP)};width:${px(width)};`;

    return { style, placement };
}

/**
 * Whether an element is actually on the page and visible.
 *
 * `offsetParent` is null for a `display:none` element but also for anything
 * `position:fixed`, hence the second branch — the nav and the bulk bar are both fixed and
 * both legitimate targets.
 */
export function isVisible(el) {
    if (!el) {
        return false;
    }

    const style = window.getComputedStyle(el);

    if (style.visibility === 'hidden' || style.display === 'none' || style.opacity === '0') {
        return false;
    }

    if (el.offsetParent === null && style.position !== 'fixed') {
        return false;
    }

    const rect = el.getBoundingClientRect();

    return rect.width > 0 && rect.height > 0;
}

/**
 * The nearest `position: fixed` ancestor, or null.
 *
 * Used to decide whether scrolling the target into view is meaningful: a fixed element
 * does not move when the page scrolls, so scrollIntoView on one is a no-op at best and a
 * jarring jump at worst.
 */
export function fixedAncestor(el) {
    let node = el;

    while (node && node !== document.body) {
        if (window.getComputedStyle(node).position === 'fixed') {
            return node;
        }
        node = node.parentElement;
    }

    return null;
}
