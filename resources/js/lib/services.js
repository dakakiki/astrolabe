/**
 * A service's calendar colour (App\Enums\ServiceColor) as Tailwind classes
 * over the `--svc-*` tokens. The classes are written out in full so the build
 * keeps them.
 */
const COLOR_CLASSES = {
    indigo: 'bg-svc-indigo',
    sky: 'bg-svc-sky',
    teal: 'bg-svc-teal',
    green: 'bg-svc-green',
    amber: 'bg-svc-amber',
    coral: 'bg-svc-coral',
    rose: 'bg-svc-rose',
    violet: 'bg-svc-violet',
};

/** The left edge of an appointment in the calendar. */
const EDGE_CLASSES = {
    indigo: 'border-l-svc-indigo',
    sky: 'border-l-svc-sky',
    teal: 'border-l-svc-teal',
    green: 'border-l-svc-green',
    amber: 'border-l-svc-amber',
    coral: 'border-l-svc-coral',
    rose: 'border-l-svc-rose',
    violet: 'border-l-svc-violet',
};

export function serviceColorClass(color) {
    return COLOR_CLASSES[color] ?? 'bg-ink-4';
}

export function serviceEdgeClass(color) {
    return EDGE_CLASSES[color] ?? 'border-l-brand';
}
