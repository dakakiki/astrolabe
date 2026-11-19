/**
 * Admin → Notifications (docs/spec/09, "Obaveštenja — tekstovi"): the operator words every
 * notification's texts. Pure helpers for the screens: which texts differ from the code's,
 * what is sent when saving, and where a placeholder goes into a text.
 */

/** The text shown in a field: the operator's wording, else the code's. */
export function shownText(text) {
    return text.value ?? text.default;
}

/**
 * What the form sends for the texts: each one as typed, `null` for one back at the
 * code's wording (the server then removes the operator's version).
 */
export function textsPayload(texts, values) {
    return Object.fromEntries(
        texts.map((text) => [text.key, values[text.key] === text.default ? null : values[text.key]]),
    );
}

/** The texts typed differently from what is saved — what the preview must show. */
export function unsavedTexts(texts, values) {
    return Object.fromEntries(
        texts.filter((text) => values[text.key] !== shownText(text)).map((text) => [text.key, values[text.key]]),
    );
}

/** Puts `:name` at the cursor (or at the end), with a space before it when needed. */
export function insertPlaceholder(value, name, start = value.length, end = start) {
    const before = value.slice(0, start);
    const gap = before === '' || /\s$/.test(before) ? '' : ' ';
    const token = `${gap}:${name}`;

    return { value: before + token + value.slice(end), cursor: start + token.length };
}

/** Placeholders a text uses that its notification does not give it, and required ones it lost. */
export function placeholderProblems(value, text) {
    const used = [...value.matchAll(/(?<![\w:/]):([A-Za-z][A-Za-z_]*)/g)].map((match) => match[1]);

    return {
        unknown: [...new Set(used.filter((name) => !text.placeholders.includes(name)))],
        missing: text.required.filter((name) => !used.includes(name)),
    };
}
