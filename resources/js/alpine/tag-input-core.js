/**
 * Shared tag-input behaviour for every tag field in the app.
 *
 * The four tag inputs (asset edit, upload batch-metadata, grid bulk bar, grid row)
 * historically each had their own "add one tag on Enter" logic. This module centralises
 * the parsing + commit so a user can type/paste a comma- or newline-separated list and
 * get multiple tags, while each consumer keeps its own state names and decides what to
 * do with the resulting names (accumulate chips vs POST immediately).
 *
 * Usage: spread `tagInputCore({...})` into an Alpine x-data object and wire the input's
 * `@keydown.comma.prevent="commitInput()"` and `@paste="handleTagPaste($event)"`.
 *
 * Config (all callbacks are invoked with the Alpine component as `this`, so define them
 * with method shorthand — NOT arrow functions):
 *   - model         {string}   name of the x-model property holding the raw input
 *   - onCommitNames {function} (names[]) => void — required; receives the parsed names
 *   - isDuplicate   {function} (name) => bool — optional; filter out names already present
 *   - onDuplicate   {function} (name) => void — optional; called for each filtered duplicate
 *   - afterCommit   {function} () => void — optional; runs after committing (e.g. hide suggestions)
 *   - clearOnCommit {bool}     default true; set false when the consumer clears on success itself
 */
export function parseTagNames(raw) {
    if (!raw) return [];
    const seen = new Set();
    const out = [];
    for (const piece of String(raw).split(/[,\n\r]+/)) {
        const name = piece.trim().toLowerCase();
        if (name && !seen.has(name)) {
            seen.add(name);
            out.push(name);
        }
    }
    return out;
}

export function tagInputCore(config) {
    const clearOnCommit = config.clearOnCommit !== false;

    return {
        commitInput() {
            let names = parseTagNames(this[config.model] || '');

            if (config.isDuplicate) {
                names = names.filter((name) => {
                    if (config.isDuplicate.call(this, name)) {
                        config.onDuplicate?.call(this, name);
                        return false;
                    }
                    return true;
                });
            }

            if (names.length) {
                config.onCommitNames.call(this, names);
            }

            if (clearOnCommit) {
                this[config.model] = '';
            }

            config.afterCommit?.call(this);
        },

        handleTagPaste(event) {
            const clipboard = event.clipboardData || window.clipboardData;
            const text = clipboard ? clipboard.getData('text') : '';

            // Only intercept multi-tag pastes; a plain paste falls through so a partial
            // tag can still be edited before committing.
            if (/[,\n\r]/.test(text)) {
                event.preventDefault();
                this[config.model] = (this[config.model] || '') + text;
                this.commitInput();
            }
        },
    };
}

window.tagInputCore = tagInputCore;
window.parseTagNames = parseTagNames;
