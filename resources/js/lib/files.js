/**
 * Upload helpers. The server decides what is accepted (it reads the content);
 * these checks only spare the astrologer an upload that would be refused.
 */

/** 32 → "32B", 1536 → "1.5 kB" */
export function formatBytes(bytes, locale) {
    if (bytes === null || bytes === undefined) return '';

    const units = ['byte', 'kilobyte', 'megabyte', 'gigabyte'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return new Intl.NumberFormat(locale, {
        style: 'unit',
        unit: units[unit],
        // "32 byte" reads oddly; the narrow form of bytes is "32B".
        unitDisplay: unit === 0 ? 'narrow' : 'short',
        maximumFractionDigits: unit === 0 ? 0 : 1,
    }).format(value);
}

export function extensionOf(name) {
    const match = /\.([^./\\]+)$/.exec(name ?? '');

    return match ? match[1].toLowerCase() : '';
}

/** Why a file would be refused — "type" or "size" — or null when it may go. */
export function checkFile(file, { maxSize, extensions }) {
    if (!extensions.includes(extensionOf(file.name))) return 'type';
    if (maxSize && file.size > maxSize) return 'size';

    return null;
}

/** The accept attribute of a file input: ".jpg,.png,…" */
export function acceptAttribute(extensions) {
    return extensions.map((extension) => `.${extension}`).join(',');
}

/** A broad kind for the icon next to a file. */
export function fileKind(mimeType) {
    if (!mimeType) return 'file';
    if (mimeType.startsWith('image/')) return 'image';
    if (mimeType.startsWith('audio/')) return 'audio';
    if (mimeType.startsWith('video/')) return 'video';
    if (mimeType === 'application/pdf') return 'pdf';
    if (mimeType.startsWith('text/')) return 'text';

    return 'document';
}
