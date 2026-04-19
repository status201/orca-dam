// Firefox ignores `background-attachment: local` on <textarea>, so the
// image stays pinned to the padding box instead of scrolling with the
// content (Bugzilla #1126091). This polyfill emulates `local` by syncing
// `background-position` to the textarea's scroll offset. No-op in other
// browsers, where the native `local` attachment is honored.

const IS_FIREFOX = typeof navigator !== 'undefined'
    && /Firefox\//.test(navigator.userAgent);

const PATCHED = new WeakSet();

export function applyFirefoxBgLocalPolyfill(el) {
    if (!IS_FIREFOX || !el || PATCHED.has(el)) return;
    PATCHED.add(el);

    const sync = () => {
        el.style.backgroundPosition = (-el.scrollLeft) + 'px ' + (-el.scrollTop) + 'px';
    };

    el.addEventListener('scroll', sync, { passive: true });
    sync();
}
