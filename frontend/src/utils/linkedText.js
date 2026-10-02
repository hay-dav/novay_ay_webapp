// Keep user-authored text as text nodes; only HTTP(S) addresses become links.
export function splitLinkedText(value) {
    const text = String(value ?? '');
    const parts = [];
    const pattern = /(?:https?:\/\/|www\.)[^\s<>"']+/giu;
    let cursor = 0;
    for (const match of text.matchAll(pattern)) {
        const start = match.index;
        let label = match[0].replace(/[.,!?;:]+$/u, '');
        for (const [open, close] of [['(', ')'], ['[', ']'], ['{', '}']]) {
            while (label.endsWith(close) && label.split(close).length > label.split(open).length)
                label = label.slice(0, -1);
        }
        const href = label.startsWith('www.') ? 'https://' + label : label;
        try {
            const url = new URL(href);
            if (!['http:', 'https:'].includes(url.protocol)) continue;
        } catch { continue; }
        if (start > cursor) parts.push({ text: text.slice(cursor, start) });
        parts.push({ text: label, href });
        cursor = start + label.length;
    }
    if (cursor < text.length) parts.push({ text: text.slice(cursor) });
    return parts;
}
