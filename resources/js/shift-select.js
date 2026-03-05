/**
 * Shared shift+click range selection utility.
 *
 * Toggles `clickedItem` in `selected`, and when shift is held applies the
 * same state to every item between `lastClickedIndex` and the current index.
 *
 * @param {Array} items            Ordered list of all selectable item identifiers
 * @param {Array} selected         Currently selected identifiers (mutated in place)
 * @param {*}     clickedItem      The identifier that was clicked
 * @param {number|null} lastClickedIndex  Index of the previous click (null if none)
 * @param {Event} event            The click event (checked for shiftKey)
 * @returns {number} The current index, to be stored as the new lastClickedIndex
 */
export function applyShiftSelect(items, selected, clickedItem, lastClickedIndex, event) {
    // Toggle the clicked item
    const idx = selected.indexOf(clickedItem);
    if (idx === -1) {
        selected.push(clickedItem);
    } else {
        selected.splice(idx, 1);
    }

    const currentIndex = items.indexOf(clickedItem);

    if (event.shiftKey && lastClickedIndex !== null && currentIndex !== lastClickedIndex) {
        window.getSelection().removeAllRanges();
        const isNowSelected = selected.includes(clickedItem);
        const start = Math.min(lastClickedIndex, currentIndex);
        const end = Math.max(lastClickedIndex, currentIndex);
        for (let i = start; i <= end; i++) {
            const item = items[i];
            if (item === clickedItem) continue;
            const alreadySelected = selected.includes(item);
            if (isNowSelected && !alreadySelected) {
                selected.push(item);
            } else if (!isNowSelected && alreadySelected) {
                selected.splice(selected.indexOf(item), 1);
            }
        }
    }

    return currentIndex;
}
