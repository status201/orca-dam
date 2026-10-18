/**
 * Request headers for a same-origin fetch() to ORCA, in one place.
 *
 * The CSRF token is read from the page's meta tag at call time rather than captured at page
 * load, so it follows the tag bootstrap.js refreshes every 15 minutes.
 *
 * Not imported by app.js: like http-errors.js, consumers import it directly.
 *
 * @see specs/features/error-handling.md REQ-request-headers
 */

/**
 * The page's current CSRF token.
 *
 * @returns {string}
 */
export function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * @param {{ json?: boolean, accept?: string }} [options]
 *   json   — the body is JSON, so send Content-Type: application/json (default true; false for FormData)
 *   accept — the Accept header (default application/json)
 * @returns {Record<string, string>}
 */
export function requestHeaders({ json = true, accept = 'application/json' } = {}) {
    const headers = {
        'X-CSRF-TOKEN': csrfToken(),
        'Accept': accept,
    };

    if (json) {
        headers['Content-Type'] = 'application/json';
    }

    return headers;
}
