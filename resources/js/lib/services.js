/**
 * A service's calendar colour (App\Enums\ServiceColor) as a Tailwind class
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

export function serviceColorClass(color) {
    return COLOR_CLASSES[color] ?? 'bg-ink-4';
}
