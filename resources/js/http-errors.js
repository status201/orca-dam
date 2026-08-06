/**
 * Resolving a failed fetch to the message worth showing.
 *
 * The server knows why a request failed; a hardcoded string in the browser does not. A dozen
 * handlers in this app read `errorData.message`, threw it, and then toasted a generic fallback
 * from the catch block instead — so a keyed 422 explaining exactly which field was too long
 * surfaced to the user as "Failed to update license". This is the one place that unpacking lives.
 *
 * Not imported by app.js: like tag-input-core.js this is a shared helper its consumers import
 * directly, so the Alpine-module count CLAUDE.md documents is unaffected.
 *
 * @see specs/features/error-handling.md REQ-11
 */

/**
 * The best available message for a failed response, falling back only when the server offered
 * nothing usable.
 *
 * Call this *before* reading the body yourself — it clones the response, and a body that has
 * already been consumed cannot be cloned.
 *
 * @param {Response} response  the failed fetch response
 * @param {string} fallback    shown only when the server said nothing we can use
 * @returns {Promise<string>}
 */
export async function errorMessageFrom(response, fallback = '') {
    const t = window.appTranslations || {};
    let data = null;

    try {
        data = await response.clone().json();
    } catch {
        // An HTML error page, a proxy error, or an empty body. Nothing to unpack.
    }

    // A 422 carries the actionable text: the first field error names what to change.
    if (response.status === 422 && data?.errors) {
        const first = Object.values(data.errors)[0];
        const message = Array.isArray(first) ? first[0] : first;

        if (message) {
            return message;
        }
    }

    if (data?.message) {
        return data.message;
    }

    // The /tools endpoints answer with `error` rather than `message`.
    if (data?.error) {
        return data.error;
    }

    // Two statuses whose bodies are produced by the framework or the web server, not by us, and
    // which are far more actionable named than as a generic failure.
    if (response.status === 419) {
        return t.sessionExpired || fallback;
    }

    if (response.status === 413) {
        return t.payloadTooLarge || fallback;
    }

    return fallback || t.requestFailed || 'The request could not be completed. Try again.';
}

/**
 * Reject with an Error carrying the server's message, so a `catch` block can surface
 * `error.message` instead of substituting a generic string.
 *
 * @param {Response} response
 * @param {string} fallback
 * @returns {Promise<never>}
 */
export async function throwHttpError(response, fallback = '') {
    const error = new Error(await errorMessageFrom(response, fallback));
    error.status = response.status;

    throw error;
}
