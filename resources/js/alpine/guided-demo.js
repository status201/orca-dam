import {
    fixedAncestor,
    holeRect,
    isVisible,
    popoverStyle,
    ringStyle,
    shutterStyle,
} from './demo-geometry';

/**
 * The guided-demo engine: spotlight one real element at a time, explain it, move on.
 *
 * Pins specs/features/guided-demos.md. The definition comes from the server on
 * `window.__pageData.guidedDemo`; this module only renders and drives it.
 *
 * Two design rules make the whole thing work and are easy to break by accident:
 *
 *  1. The engine's entire vocabulary is `data-testid` values and DOM events. It knows
 *     nothing about assetGrid, the nav, or any other module — which is why a new demo is
 *     data rather than code, and why none of those modules had to change.
 *  2. Nothing in the resolve/position path may throw. A demo that fails must degrade to a
 *     skipped step, never break the page it is running on (REQ-9).
 */

/** Same-tab breadcrumb, so a navigation the *app* triggers doesn't lose the demo. */
const BREADCRUMB = 'orca.demo';

const el = (testid) => document.querySelector(`[data-testid="${testid}"]`);

/** The first visible candidate for a step's target: string, list, or nothing. */
function resolveTarget(target) {
    if (!target) {
        return null;
    }

    const ids = Array.isArray(target) ? target : [target];

    for (const id of ids) {
        const candidate = el(id);
        if (isVisible(candidate)) {
            return { id, node: candidate };
        }
    }

    return null;
}

/** A synthetic hover that actually reaches a handler bound on an ancestor wrapper. */
function synthesizeHover(node) {
    // mouseenter does not bubble natively, so it must be constructed with bubbles:true or
    // it never reaches the wrapper that owns the submenu's state. pointerover/mouseover
    // are dispatched too because handlers differ on which they listen for.
    for (const type of ['pointerover', 'mouseover', 'mouseenter']) {
        node.dispatchEvent(new MouseEvent(type, { bubbles: true, cancelable: true, view: window }));
    }
}

export function guidedDemo() {
    const payload = window.__pageData?.guidedDemo || null;

    return {
        demo: payload,
        steps: payload?.steps || [],
        ui: payload?.ui || {},
        index: payload?.step || 0,

        running: false,
        awaiting: false,
        missing: false,
        finished: false,
        targetKey: '',
        placement: 'bottom',

        // False from the moment a step is entered until its target has been resolved and
        // the geometry written. Resolution is asynchronous (reveal, then a hydration
        // poll), so without this there is no way — for a reader or a test — to tell
        // "still finding the target" from "there is no target".
        settled: false,

        // Whether the *current* step has picked its target yet. Distinct from `settled`
        // because reposition() runs on scroll and resize too: entering a step scrolls,
        // which would otherwise let a reposition triggered by the previous step's
        // observers declare the new step settled while targetKey is still stale.
        _acquired: false,

        hole: null,
        shutters: ['top', 'right', 'bottom', 'left'],
        popover: '',
        ring: 'display:none;',

        // Latched the first time a step on this document is positioned, so the keyboard is
        // handed the popover once per page load rather than on every reposition. See
        // focusPrimary().
        _focused: false,

        // Teardown handles, all nulled by stop().
        _observers: [],
        _advance: null,
        _frame: null,
        _onViewport: null,
        _pollUntil: 0,

        get total() {
            return this.steps.length;
        },

        get step() {
            return this.steps[this.index] || { title: '', body: '' };
        },

        get isLast() {
            return this.index >= this.total - 1;
        },

        get onThisPage() {
            return !this.demo || this.step.routeName === this.demo.currentRoute;
        },

        get hasTarget() {
            return this.targetKey !== '' && this.hole !== null;
        },

        init() {
            if (!this.demo || this.total === 0) {
                // Nothing armed. Re-arm from a breadcrumb if the app navigated us here
                // itself and dropped the query string (a filter select setting
                // window.location.href, for instance).
                this.recoverFromBreadcrumb();

                return;
            }

            this.running = true;
            document.body.classList.add('orca-demo-active');

            this._onViewport = () => this.schedule();
            window.addEventListener('scroll', this._onViewport, { passive: true, capture: true });
            window.addEventListener('resize', this._onViewport, { passive: true });
            // load doesn't bubble, so lazy images only reach a capture-phase listener.
            document.addEventListener('load', this._onViewport, true);

            this.goToStep(this.index);
        },

        /**
         * A demo was in flight but the URL no longer says so. Put it back, once.
         *
         * Guarded on the path so a demo abandoned on one page cannot follow the user
         * around the app, and cleared unconditionally so a failed re-arm cannot loop.
         */
        recoverFromBreadcrumb() {
            let crumb = null;

            try {
                crumb = JSON.parse(window.sessionStorage.getItem(BREADCRUMB) || 'null');
                window.sessionStorage.removeItem(BREADCRUMB);
            } catch (error) {
                return;
            }

            if (!crumb || crumb.path !== window.location.pathname) {
                return;
            }

            const url = new URL(window.location.href);
            url.searchParams.set('demo', crumb.demo);
            url.searchParams.set('demoStep', String(crumb.step));
            window.location.replace(url.toString());
        },

        /** Written synchronously — a fetch cannot be relied on before an unload. */
        dropBreadcrumb(step) {
            try {
                window.sessionStorage.setItem(BREADCRUMB, JSON.stringify({
                    demo: this.demo.id,
                    step,
                    path: window.location.pathname,
                }));
            } catch (error) {
                // Private mode or a full quota: the demo simply won't survive an
                // app-driven navigation. Not worth telling anyone about.
            }
        },

        clearBreadcrumb() {
            try {
                window.sessionStorage.removeItem(BREADCRUMB);
            } catch (error) {
                /* nothing to do */
            }
        },

        // ── navigation ────────────────────────────────────────────────────────

        goToStep(target) {
            const index = Math.max(0, Math.min(target, this.total - 1));
            this.index = index;
            this.detachAdvance();
            this.awaiting = false;
            this.settled = false;
            this._acquired = false;

            // replaceState, not pushState: one history entry per *page*, so Back leaves
            // the demo in one press rather than one per step.
            this.syncUrl();

            if (!this.onThisPage) {
                // The step belongs elsewhere. Show the hand-off card rather than guessing.
                this.showUnanchored('center');

                return;
            }

            this.reveal(this.step.reveal)
                .then(() => this.acquireTarget())
                .catch(() => this.showUnanchored('center'));
        },

        next() {
            if (this.isLast) {
                this.finish();

                return;
            }

            const following = this.steps[this.index + 1];

            if (following && following.routeName !== this.demo.currentRoute) {
                this.navigateTo(this.index + 1);

                return;
            }

            this.goToStep(this.index + 1);
        },

        prev() {
            if (this.index === 0) {
                return;
            }

            const preceding = this.steps[this.index - 1];

            if (preceding && preceding.routeName !== this.demo.currentRoute) {
                this.navigateTo(this.index - 1);

                return;
            }

            this.goToStep(this.index - 1);
        },

        /** Cross a page boundary, carrying the demo in the URL. */
        navigateTo(index) {
            const step = this.steps[index];
            const url = new URL(step.url, window.location.origin);
            url.searchParams.set('demo', this.demo.id);
            url.searchParams.set('demoStep', String(index));
            this.clearBreadcrumb();
            window.location.assign(url.toString());
        },

        /** The hand-off card's action — the same move as a cross-page Next. */
        goToPage() {
            this.navigateTo(this.index);
        },

        skip() {
            this.record(true);
            this.stop();
        },

        finish() {
            this.record(false);

            if (this.demo.next) {
                // Offer the successor instead of closing; the server already checked the
                // user may play it.
                this.finished = true;
                this.showUnanchored('center');

                return;
            }

            this.stop();
        },

        startNext() {
            const url = new URL(window.location.pathname, window.location.origin);
            url.searchParams.set('demo', this.demo.next.id);
            this.clearBreadcrumb();
            window.location.assign(url.toString());
        },

        stop() {
            this.running = false;
            this.finished = false;
            this.detachAdvance();
            this.disconnectObservers();
            this.clearBreadcrumb();
            document.body.classList.remove('orca-demo-active');

            window.removeEventListener('scroll', this._onViewport, { capture: true });
            window.removeEventListener('resize', this._onViewport);
            document.removeEventListener('load', this._onViewport, true);

            // Drop the demo from the URL so a reload does not restart it.
            const url = new URL(window.location.href);
            url.searchParams.delete('demo');
            url.searchParams.delete('demoStep');
            window.history.replaceState({}, '', url.toString());
        },

        syncUrl() {
            const url = new URL(window.location.href);
            url.searchParams.set('demo', this.demo.id);
            url.searchParams.set('demoStep', String(this.index));

            // Skip a write that would change nothing. This is not just tidiness: the first
            // step after a cross-page hand-off already has the right URL (navigateTo put it
            // there), and a replaceState while the cross-document view transition from
            // app.css is still running aborts it — which the browser reports as an
            // unhandled "Transition was skipped".
            if (url.toString() === window.location.href) {
                return;
            }

            window.history.replaceState({}, '', url.toString());
        },

        /**
         * Fire-and-forget. A failed save ends the demo normally: "could not save demo
         * progress" would be a poor first notification for a brand-new user.
         */
        record(dismissed) {
            const token = document.querySelector('meta[name="csrf-token"]')?.content;

            if (!this.demo?.completeUrl || !token) {
                return;
            }

            fetch(this.demo.completeUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    Accept: 'application/json',
                },
                body: JSON.stringify({ dismissed }),
            }).catch(() => {});
        },

        // ── targets ───────────────────────────────────────────────────────────

        /** Open whatever hides the next target. Resolves once it has settled. */
        reveal(spec) {
            if (!spec) {
                return Promise.resolve();
            }

            if (spec === 'scroll-top') {
                window.scrollTo({ top: 0, behavior: 'auto' });

                return this.settle(120);
            }

            if (spec.hover) {
                const node = el(spec.hover);
                if (node) {
                    synthesizeHover(node);
                }

                return this.settle(160);
            }

            if (spec.click) {
                const node = el(spec.click);

                // Clicking a toggle that is already in the wanted state undoes it, so a
                // click reveal needs a postcondition. `until` names what the click is
                // supposed to produce; without it the step's own target is assumed, which
                // is right when the reveal opens the very thing being pointed at (a
                // collapsed panel) but not when a step points at the control it presses —
                // a view-mode button is always visible, so it would never fire.
                const done = spec.until
                    ? isVisible(el(spec.until))
                    : Boolean(resolveTarget(this.step.target));

                if (node && !done) {
                    node.click();
                }

                return this.settle(200);
            }

            return Promise.resolve();
        },

        settle(ms) {
            return new Promise((resolve) => window.setTimeout(resolve, ms));
        },

        /**
         * Find the target, scroll it into view, draw the spotlight.
         *
         * Polls briefly first: an x-show element exists in the DOM before Alpine unhides
         * it, and lazy images shift layout underneath a freshly measured rect.
         */
        acquireTarget() {
            this._pollUntil = performance.now() + 1500;
            this.poll();
        },

        poll() {
            const found = resolveTarget(this.step.target);

            if (found) {
                this.lockOn(found);

                return;
            }

            if (this.step.target && performance.now() < this._pollUntil) {
                window.requestAnimationFrame(() => this.poll());

                return;
            }

            if (!this.step.target || this.step.fallback === 'center') {
                this.showUnanchored(this.step.target ? 'center' : this.step.placement);

                return;
            }

            // fallback: 'skip' — the target is legitimately absent for this user.
            if (this.isLast) {
                this.stop();

                return;
            }

            this.goToStep(this.index + 1);
        },

        lockOn(found) {
            this.targetKey = found.id;
            this.missing = false;
            this._acquired = true;
            this.disconnectObservers();

            const pinned = fixedAncestor(found.node);

            if (pinned) {
                // A fixed target does not move with the page, so scrolling to it is at
                // best pointless. Go to the top instead, which is where the nav lives.
                window.scrollTo({ top: 0, behavior: 'auto' });
            } else {
                found.node.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'auto' });
            }

            this.observe(found.node);
            this.attachAdvance(found.node);
            this.schedule();
        },

        showUnanchored(placement) {
            this.targetKey = '';
            this.hole = null;
            this.missing = Boolean(this.step.target);
            this.placement = placement === 'sheet' ? placement : 'center';
            this._acquired = true;
            this.disconnectObservers();
            this.schedule();
        },

        // ── layout ────────────────────────────────────────────────────────────

        /** Coalesce every reposition trigger into one write per frame. */
        schedule() {
            if (this._frame) {
                return;
            }

            this._frame = window.requestAnimationFrame(() => {
                this._frame = null;
                this.reposition();
                this.focusPrimary();
            });
        },

        reposition() {
            // Not acquired yet means the step is still resolving its target, and
            // targetKey still holds the previous one — positioning against it would both
            // flash the old geometry and wrongly report the step as settled.
            if (!this.running || !this._acquired) {
                return;
            }

            try {
                const node = this.targetKey ? el(this.targetKey) : null;

                if (this.targetKey && !isVisible(node)) {
                    // The node was replaced (an x-for re-render) or hidden. Re-resolve by
                    // testid rather than holding a stale reference.
                    const again = resolveTarget(this.step.target);

                    if (!again) {
                        this.showUnanchored('center');

                        return;
                    }

                    this.lockOn(again);

                    return;
                }

                this.hole = node ? holeRect(node.getBoundingClientRect()) : null;
                this.ring = ringStyle(this.hole);

                const placed = popoverStyle(this.hole, this.hole ? this.step.placement : 'center');
                this.popover = placed.style;
                this.placement = placed.placement;
                this.settled = this._acquired;
            } catch (error) {
                // Never let geometry break the page. Degrade to a centered card.
                this.hole = null;
                this.ring = 'display:none;';
                this.popover = popoverStyle(null, 'center').style;
                this.placement = 'center';
                this.settled = this._acquired;
            }
        },

        shutter(side) {
            return shutterStyle(side, this.hole);
        },

        // ── focus ─────────────────────────────────────────────────────────────

        /**
         * Hand the keyboard the step's primary action, once per document (REQ-13).
         *
         * Stepping with Enter worked same-page only by accident: clicking Next left it
         * focused, so the next Enter re-activated the same node — and that node survives
         * every same-page transition, since only the text and the geometry change. A
         * hand-off is a full document load (navigateTo → location.assign), which resets
         * focus to the body, so Enter did nothing until the reader found the button with
         * Tab. Moving focus in is also what the popover's role="dialog" aria-modal="true"
         * has been claiming all along.
         *
         * Latched rather than per-step, because schedule() also fires on every scroll,
         * resize and observer tick — re-focusing there would fight the reader for the
         * keyboard. Guarded on `settled`, which reposition() has just written, so this can
         * never fire while a target is still resolving.
         */
        focusPrimary() {
            if (this._focused || !this.running || !this.settled) {
                return;
            }

            // Consumed even when the focus is declined below: a step that lands the reader
            // in the search box must not have the keyboard taken away one step later.
            this._focused = true;

            // An act-to-advance step wants the reader's hands on the app's own control
            // (REQ-8), so taking focus here would break the step it is meant to help.
            // `awaiting` is set in attachAdvance, inside lockOn, before its schedule() —
            // so it is already correct by the time this reads it.
            if (this.awaiting) {
                return;
            }

            const primary = !this.onThisPage
                ? 'demo-goto'
                : (this.isLast ? 'demo-finish' : 'demo-next');

            // demo-goto lives in a nested x-if, so it exists only once onThisPage has
            // flipped and Alpine has patched the DOM; `?.` makes a miss harmless anyway.
            this.$nextTick(() => {
                // preventScroll: lockOn has already put the target where it wants it, and a
                // focus-induced scroll would slide it out from under the spotlight.
                this.$el.querySelector(`[data-testid="${primary}"]`)?.focus({ preventScroll: true });
            });
        },

        observe(node) {
            if (typeof ResizeObserver !== 'undefined') {
                const ro = new ResizeObserver(() => this.schedule());
                ro.observe(node);
                ro.observe(document.documentElement);
                this._observers.push(ro);
            }

            if (typeof IntersectionObserver !== 'undefined') {
                const io = new IntersectionObserver(() => this.schedule());
                io.observe(node);
                this._observers.push(io);
            }

            if (typeof MutationObserver !== 'undefined' && node.parentElement) {
                const mo = new MutationObserver(() => this.schedule());
                mo.observe(node.parentElement, { childList: true, subtree: true, attributes: true });
                this._observers.push(mo);
            }
        },

        disconnectObservers() {
            this._observers.forEach((observer) => observer.disconnect());
            this._observers = [];
        },

        // ── act-to-advance ────────────────────────────────────────────────────

        /**
         * Wire up a step that waits for the user to do the thing.
         *
         * Capture phase is required, not stylistic: the grid's filter selects navigate
         * from their own change handler, which would destroy a bubble-phase listener
         * before it ran. Capture fires first, so the breadcrumb is written while the page
         * still exists.
         */
        attachAdvance(node) {
            const spec = this.step.advanceOn;

            if (!spec) {
                return;
            }

            this.awaiting = true;

            if (spec.appear) {
                if (typeof MutationObserver === 'undefined') {
                    return;
                }

                const watcher = new MutationObserver(() => {
                    if (isVisible(el(spec.appear))) {
                        this.satisfied();
                    }
                });
                watcher.observe(document.body, { childList: true, subtree: true, attributes: true });
                this._advance = { disconnect: () => watcher.disconnect() };

                return;
            }

            const min = spec.minLength || 0;
            const handler = (event) => {
                if (min > 0 && (event.target?.value || '').trim().length < min) {
                    return;
                }

                this.satisfied();
            };

            node.addEventListener(spec.event, handler, true);
            this._advance = {
                disconnect: () => node.removeEventListener(spec.event, handler, true),
            };
        },

        detachAdvance() {
            if (this._advance) {
                this._advance.disconnect();
                this._advance = null;
            }
        },

        /**
         * The user did the thing. Persist the *next* step before advancing, because the
         * action may be about to navigate the page out from under us.
         */
        satisfied() {
            this.detachAdvance();
            this.awaiting = false;

            const following = Math.min(this.index + 1, this.total - 1);
            this.dropBreadcrumb(following);

            if (this.isLast) {
                return;
            }

            // Let the app's own handler finish first; if it navigates, the breadcrumb
            // above is what resumes the demo.
            window.setTimeout(() => {
                if (this.running) {
                    this.clearBreadcrumb();
                    this.goToStep(following);
                }
            }, 350);
        },

        // ── keyboard ──────────────────────────────────────────────────────────

        onKey(event) {
            if (!this.running) {
                return;
            }

            // An interactive step hands the arrow keys to the real control, so stepping
            // must not steal them while the user is typing or picking.
            const tag = (document.activeElement?.tagName || '').toLowerCase();
            const typing = ['input', 'textarea', 'select'].includes(tag)
                || document.activeElement?.isContentEditable;

            if (typing) {
                return;
            }

            if (event.key === 'ArrowRight') {
                event.preventDefault();
                this.next();
            } else if (event.key === 'ArrowLeft') {
                event.preventDefault();
                this.prev();
            }
        },
    };
}

window.guidedDemo = guidedDemo;
