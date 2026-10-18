/**
 * A byte count as the UI shows it: "512 B", "12.5 KB", "3.25 MB" — the same shape as the server's
 * Asset::formatted_size, with no trailing zeros. Six modules used to carry their own copy, with
 * three different outputs.
 *
 * Not imported by app.js: consumers import it directly.
 *
 * @param {number|string} bytes   a string is accepted: the S3 SDK reports some sizes as strings
 * @param {number} [decimals=2]
 * @returns {string}
 */
export function formatBytes(bytes, decimals = 2) {
    const units = ['B', 'KB', 'MB', 'GB'];
    let size = Number(bytes) || 0;
    let unit = 0;

    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit++;
    }

    return `${parseFloat(size.toFixed(decimals))} ${units[unit]}`;
}
