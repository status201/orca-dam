/**
 * Wires every <x-char-counter> on the page to the field it counts.
 *
 * `maxlength` alone gives no feedback: the input just stops accepting characters. A user pasting a
 * long copyright saw nothing, hit the cap, and had no idea — which is half of why the original bug
 * report said "without any indication to the user what's wrong".
 *
 * Counts in code points (`[...value].length`) rather than `value.length`, so an emoji or an
 * accented character counts as the one character MySQL will count, not as two UTF-16 units.
 *
 * Advisory only — see specs/features/input-validation.md REQ-8.
 */

const WARN_AT = 0.9;

function paint(counter, field) {
    const max = Number(counter.dataset.charMax);
    const used = [...field.value].length;
    const output = counter.querySelector('[data-char-count]');

    if (output) {
        output.textContent = used;
    }

    counter.classList.toggle('text-gray-400', used < max * WARN_AT);
    counter.classList.toggle('text-amber-600', used >= max * WARN_AT && used < max);
    counter.classList.toggle('text-red-600', used >= max);
}

export function initCharCounters(root = document) {
    root.querySelectorAll('[data-char-counter]').forEach((counter) => {
        const field = root.getElementById
            ? root.getElementById(counter.dataset.charCounter)
            : root.querySelector(`#${CSS.escape(counter.dataset.charCounter)}`);

        if (! field) {
            return;
        }

        // `input` covers typing, pasting and cut; the initial paint covers a page rendered with
        // old() input after a validation redirect, which is exactly when the count matters most.
        field.addEventListener('input', () => paint(counter, field));
        paint(counter, field);
    });
}

document.addEventListener('DOMContentLoaded', () => initCharCounters());
