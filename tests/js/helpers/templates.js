import { readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * The Vue templates as text, for tests that check a convention on every screen (icons and
 * tooltips on buttons). No component is mounted.
 */
export const root = fileURLToPath(new URL('../../../', import.meta.url));

function vueFiles(dir) {
    return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const path = join(dir, entry.name);
        if (entry.isDirectory()) return vueFiles(path);
        return entry.name.endsWith('.vue') ? [path] : [];
    });
}

/** Every .vue file under resources/js: `{ name: 'resources/js/…', source }`. */
export const files = vueFiles(join(root, 'resources/js')).map((path) => ({
    name: relative(root, path).replace(/\\/g, '/'),
    source: readFileSync(path, 'utf8'),
}));

// End of the opening tag that starts at `start`, skipping ">" inside attribute values.
function openTagEnd(source, start) {
    let quote = null;
    for (let i = start + 1; i < source.length; i++) {
        const c = source[i];
        if (quote) {
            if (c === quote) quote = null;
        } else if (c === '"' || c === "'") {
            quote = c;
        } else if (c === '>') {
            return i + 1;
        }
    }
    return -1;
}

/**
 * Buttons, links and labels in a template whose class list matches `classes`, with their
 * opening tag and what is inside.
 */
export function elements(source, classes = /(?:^|\s)btn(?:\s|$)/) {
    const found = [];
    const template = source.indexOf('<template');
    const tags = /<(button|RouterLink|a|label)(?=[\s>])/g;
    let match;
    while ((match = tags.exec(source))) {
        if (match.index < template) continue;
        const end = openTagEnd(source, match.index);
        const open = source.slice(match.index, end);
        const list = (open.match(/\sclass="([^"]*)"/) || [])[1] ?? '';
        if (open.endsWith('/>') || !classes.test(list)) continue;
        const inner = source.slice(end, source.indexOf(`</${match[1]}`, end));
        found.push({ line: source.slice(0, match.index).split('\n').length, open, inner });
    }
    return found;
}

/** A value in a nested locale object by its dotted key. */
export function lookup(messages, key) {
    return key.split('.').reduce((node, part) => (node == null ? node : node[part]), messages);
}
