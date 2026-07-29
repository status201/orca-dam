// Deterministic binary fixtures for upload specs — no fixture files on disk, so
// every upload can have unique bytes (etag-based duplicate detection is a
// behaviour under test). See specs/features/e2e-testing.md.
import { deflateSync } from 'node:zlib';

const CRC_TABLE = (() => {
    const table = new Int32Array(256);
    for (let n = 0; n < 256; n++) {
        let c = n;
        for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        table[n] = c;
    }
    return table;
})();

function crc32(buffer) {
    let c = -1;
    for (const byte of buffer) c = CRC_TABLE[(c ^ byte) & 0xff] ^ (c >>> 8);
    return (c ^ -1) >>> 0;
}

function chunk(type, data) {
    const length = Buffer.alloc(4);
    length.writeUInt32BE(data.length);
    const typed = Buffer.concat([Buffer.from(type, 'ascii'), data]);
    const crc = Buffer.alloc(4);
    crc.writeUInt32BE(crc32(typed));
    return Buffer.concat([length, typed, crc]);
}

/**
 * A valid RGB PNG of the given size, filled with one colour. Different colours
 * produce different bytes (and therefore different etags), which is what makes
 * the duplicate-detection specs meaningful.
 */
export function pngBuffer({ width = 8, height = 8, color = [220, 40, 40] } = {}) {
    const header = Buffer.alloc(13);
    header.writeUInt32BE(width, 0);
    header.writeUInt32BE(height, 4);
    header[8] = 8; // bit depth
    header[9] = 2; // colour type: truecolour
    // 10..12: compression / filter / interlace all default to 0.

    const stride = width * 3 + 1;
    const raw = Buffer.alloc(stride * height);
    for (let y = 0; y < height; y++) {
        raw[y * stride] = 0; // filter: none
        for (let x = 0; x < width; x++) {
            const at = y * stride + 1 + x * 3;
            raw[at] = color[0];
            raw[at + 1] = color[1];
            raw[at + 2] = color[2];
        }
    }

    return Buffer.concat([
        Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
        chunk('IHDR', header),
        chunk('IDAT', deflateSync(raw)),
        chunk('IEND', Buffer.alloc(0)),
    ]);
}

/** A payload for `setInputFiles()`. Pass the same `color` twice to force a duplicate. */
export function pngFixture(name, { color, width, height } = {}) {
    return {
        name,
        mimeType: 'image/png',
        buffer: pngBuffer({ color, width, height }),
    };
}

let counter = 0;

/**
 * Collision-free upload name. The MinIO bucket is not emptied between runs, so
 * upload specs must never reuse a filename across runs.
 */
export function uniqueName(prefix, extension = 'png') {
    counter += 1;
    return `${prefix}-${Date.now().toString(36)}-${counter}.${extension}`;
}

/** A distinct colour per call, so generated PNGs never collide by accident. */
export function uniqueColor() {
    counter += 1;
    return [(counter * 37) % 256, (counter * 71) % 256, (counter * 113) % 256];
}
