<script setup>
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';

import {
    ANGLES,
    arcPath,
    aspectWeight,
    describeChart,
    houseMiddle,
    longitudeText,
    normalize,
    polar,
    spread,
    wheelRotation,
} from '@/lib/chart';
import { BODY_GLYPHS, SIGNS, formatDegrees } from '@/lib/zodiac';

/**
 * The natal chart wheel as inline SVG (docs/spec/11): the sign ring, house
 * cusps with their numbers, the bodies with degree, minute and ℞, aspect
 * lines coloured by type, and the angles marked. Colours come from the design
 * tokens through the .wheel styles, so themes and branding reach it.
 *
 * With a birth time the Ascendant sits at nine o'clock; without one there are
 * no angles or houses, 0° Aries takes that place and the Moon is drawn as the
 * arc it covered during the birth date. The position table next to the wheel
 * carries the same information in reading order; the SVG also describes
 * itself for screen readers.
 *
 * With `transits` (Phase 7a) the planets of another moment ride on an outer
 * ring, and the lines join them to the natal points they aspect instead of
 * showing the natal aspects.
 */
const props = defineProps({
    chart: { type: Object, required: true },
    name: { type: String, default: '' },
    // Drawn small: degree labels would be unreadable, the table beside it has them.
    compact: { type: Boolean, default: false },
    /** Transiting positions for the outer ring: [{ body, longitude, retrograde }]. */
    transits: { type: Array, default: null },
    /** Transit contacts drawn as lines: [{ transit, natal, type, orb, from, to }] (contactLines in lib/transits). */
    contacts: { type: Array, default: null },
});

const { t } = useI18n();
const id = useId();

// The outer transit ring needs room around the natal wheel.
const size = computed(() => (props.transits ? 760 : 640));
const C = computed(() => size.value / 2);
const R = {
    transitOut: 352,
    transitDeg: 336,
    transitGlyph: 314,
    out: 292,
    sign: 254,
    marker: 247,
    moonArc: 244,
    glyph: 233,
    house: 184,
    inner: 158,
};

/**
 * The degree label sits inside its glyph. It is wider than tall, so on the
 * left and right of the wheel it needs more room along the radius.
 */
function degreeRadius(longitude) {
    const theta = ((180 + longitude - rotation.value) * Math.PI) / 180;

    return R.glyph - 17 - 13 * Math.abs(Math.cos(theta));
}

/** Degrees kept between neighbouring glyphs. */
const MIN_GAP = 8.5;

const rotation = computed(() => wheelRotation(props.chart));
const at = (longitude, radius) => polar(longitude, radius, rotation.value, C.value);
const f = (n) => n.toFixed(2);

function segment(longitude, from, to, toLongitude = longitude) {
    const [x1, y1] = at(longitude, from);
    const [x2, y2] = at(toLongitude, to);

    return { x1: f(x1), y1: f(y1), x2: f(x2), y2: f(y2) };
}

const signs = computed(() =>
    SIGNS.map((sign, i) => {
        const [x, y] = at(i * 30 + 15, (R.out + R.sign) / 2);

        return { ...sign, x: f(x), y: f(y), boundary: segment(i * 30, R.sign, R.out) };
    }),
);

// Every degree, longer at 5° and 10°; the sign boundaries are drawn with the signs.
const ticks = computed(() =>
    Array.from({ length: 360 }, (_, degree) => degree)
        .filter((degree) => degree % 30 !== 0)
        .map((degree) => segment(degree, R.sign, R.sign + (degree % 10 === 0 ? 8 : degree % 5 === 0 ? 5.5 : 3))),
);

const houses = computed(() => {
    const cusps = props.chart.houses?.cusps;
    if (!cusps) return [];

    return cusps.map((cusp, i) => {
        const [x, y] = at(houseMiddle(cusps, i), (R.inner + R.house) / 2);

        return { number: i + 1, line: segment(cusp, R.inner, R.sign), x: f(x), y: f(y) };
    });
});

const angles = computed(() => {
    const values = props.chart.angles;
    if (!values) return [];

    return ANGLES.map((key) => {
        const edge = props.transits ? R.transitOut : R.out;
        const [x, y] = at(values[key], edge + 18);

        // Inside the sign ring, then a short pointer outside it, so the sign glyphs stay clear.
        return {
            key,
            main: key === 'asc' || key === 'mc',
            line: segment(values[key], R.inner, R.sign),
            pointer: segment(values[key], edge, edge + 9),
            x: f(x),
            y: f(y),
        };
    });
});

/** The Moon's span when the birth time is unknown, and its middle. */
const moonRange = computed(() => {
    const range = props.chart.moon_range;
    if (!range) return null;

    return {
        path: arcPath(range.from, range.to, R.moonArc, rotation.value, C.value),
        middle: normalize(range.from + normalize(range.to - range.from) / 2),
    };
});

// The mean node would sit on top of the true node; it stays in the table.
const bodies = computed(() => {
    const shown = props.chart.positions
        .filter((position) => position.body !== 'mean_node')
        .map((position) => ({
            ...position,
            at: position.body === 'moon' && moonRange.value ? moonRange.value.middle : position.longitude,
        }));
    const display = spread(
        shown.map((position) => ({ key: position.body, longitude: position.at })),
        MIN_GAP,
    );

    return shown.map((position) => {
        const where = display[position.body];
        const [gx, gy] = at(where, R.glyph);
        const [dx, dy] = at(where, degreeRadius(where));
        const isRange = position.body === 'moon' && moonRange.value;

        return {
            ...position,
            glyph: BODY_GLYPHS[position.body],
            gx: f(gx),
            gy: f(gy),
            dx: f(dx),
            dy: f(dy),
            degree: isRange ? null : formatDegrees(position.longitude),
            marker: segment(position.at, R.sign, R.marker),
            // From the true degree to the glyph, where the glyph had to move aside.
            leader:
                Math.abs(normalize(where - position.at + 180) - 180) > 0.5
                    ? segment(position.at, R.marker, R.glyph + 12, where)
                    : null,
            label: isRange
                ? `${t(`bodies.${position.body}`)}: ${t('chart.describe.range', {
                      from: longitudeText(props.chart.moon_range.from, t),
                      to: longitudeText(props.chart.moon_range.to, t),
                  })}`
                : [
                      `${t(`bodies.${position.body}`)} ${longitudeText(position.longitude, t)}`,
                      position.house ? t('chart.describe.house', { house: position.house }) : null,
                      position.retrograde ? t('chart.retrograde') : null,
                  ]
                      .filter(Boolean)
                      .join(' · '),
        };
    });
});

// The planets of the other moment, spread along the outer ring like the natal ones.
const transitBodies = computed(() => {
    if (!props.transits) return [];

    const shown = props.transits.filter((position) => position.body !== 'mean_node');
    const display = spread(
        shown.map((position) => ({ key: position.body, longitude: position.longitude })),
        MIN_GAP,
    );

    return shown.map((position) => {
        const where = display[position.body];
        const [gx, gy] = at(where, R.transitGlyph);
        const [dx, dy] = at(where, R.transitDeg);

        return {
            ...position,
            glyph: BODY_GLYPHS[position.body],
            gx: f(gx),
            gy: f(gy),
            dx: f(dx),
            dy: f(dy),
            degree: formatDegrees(position.longitude),
            marker: segment(position.longitude, R.out, R.out + 7),
            leader:
                Math.abs(normalize(where - position.longitude + 180) - 180) > 0.5
                    ? segment(position.longitude, R.out + 7, R.transitGlyph - 11, where)
                    : null,
            label: [
                `${t('transits.title')}: ${t(`bodies.${position.body}`)} ${longitudeText(position.longitude, t)}`,
                position.retrograde ? t('chart.retrograde') : null,
            ]
                .filter(Boolean)
                .join(' · '),
        };
    });
});

const aspectLines = computed(() => {
    if (props.contacts) {
        return props.contacts.map((contact) => ({
            a: contact.transit,
            b: contact.natal,
            type: contact.type,
            weight: aspectWeight(contact.orb),
            ...segment(contact.from, R.inner, R.inner, contact.to),
        }));
    }

    const longitudes = Object.fromEntries(props.chart.positions.map((position) => [position.body, position.longitude]));
    Object.assign(longitudes, props.chart.angles ?? {});

    return (props.chart.aspects ?? [])
        .filter((aspect) => longitudes[aspect.a] !== undefined && longitudes[aspect.b] !== undefined)
        .map((aspect) => ({
            ...aspect,
            weight: aspectWeight(aspect.orb),
            ...segment(longitudes[aspect.a], R.inner, R.inner, longitudes[aspect.b]),
        }));
});

const title = computed(() => {
    if (props.transits) {
        return props.name ? t('chart.wheel.transitsTitle', { name: props.name }) : t('chart.wheel.transitsTitleNoName');
    }

    return props.name ? t('chart.wheel.title', { name: props.name }) : t('chart.wheel.titleNoName');
});
const description = computed(() => describeChart(props.chart, t));
</script>

<template>
    <svg
        class="wheel"
        :class="{ 'is-compact': compact }"
        :viewBox="`0 0 ${size} ${size}`"
        role="img"
        :aria-labelledby="`${id}-title ${id}-desc`"
        xmlns="http://www.w3.org/2000/svg"
    >
        <title :id="`${id}-title`">{{ title }}</title>
        <desc :id="`${id}-desc`">{{ description }}</desc>

        <!-- Rings -->
        <circle v-if="transits" :cx="C" :cy="C" :r="R.transitOut" class="transit-band" />
        <circle :cx="C" :cy="C" :r="R.out" class="band" />
        <circle :cx="C" :cy="C" :r="R.sign" class="face" />
        <circle v-if="houses.length" :cx="C" :cy="C" :r="R.house" class="ring" />
        <circle :cx="C" :cy="C" :r="R.inner" class="ring" />

        <!-- Signs, their boundaries and the degree scale -->
        <g aria-hidden="true">
            <line v-for="(tick, i) in ticks" :key="`t${i}`" v-bind="tick" class="tick" />
            <template v-for="sign in signs" :key="sign.key">
                <line v-bind="sign.boundary" class="sign-edge" />
                <text :x="sign.x" :y="sign.y" class="sign-glyph" :class="`el-${sign.element}`">{{ sign.glyph }}</text>
            </template>
        </g>

        <!-- Houses -->
        <g aria-hidden="true">
            <template v-for="house in houses" :key="house.number">
                <line v-bind="house.line" class="cusp" />
                <text :x="house.x" :y="house.y" class="house-num">{{ house.number }}</text>
            </template>
        </g>

        <!-- Aspects -->
        <g aria-hidden="true">
            <line
                v-for="aspect in aspectLines"
                :key="`${aspect.a}-${aspect.b}-${aspect.type}`"
                :x1="aspect.x1"
                :y1="aspect.y1"
                :x2="aspect.x2"
                :y2="aspect.y2"
                class="asp"
                :class="[`asp-${aspect.type}`, `asp-${aspect.weight}`]"
            />
        </g>

        <!-- Angles -->
        <g aria-hidden="true">
            <template v-for="angle in angles" :key="angle.key">
                <line v-bind="angle.line" class="angle-line" :class="{ 'is-main': angle.main }" />
                <line v-bind="angle.pointer" class="angle-line" :class="{ 'is-main': angle.main }" />
                <text :x="angle.x" :y="angle.y" class="angle-label" :class="{ 'is-main': angle.main }">
                    {{ t(`chart.angleAbbr.${angle.key}`) }}
                </text>
            </template>
        </g>

        <!-- The Moon's span without a birth time -->
        <path v-if="moonRange" :d="moonRange.path" class="moon-range" aria-hidden="true" />

        <!-- Bodies -->
        <g v-for="body in bodies" :key="body.body" class="body">
            <title>{{ body.label }}</title>
            <line v-bind="body.marker" class="marker" />
            <line v-if="body.leader" v-bind="body.leader" class="leader" />
            <text :x="body.gx" :y="body.gy" class="planet-glyph">{{ body.glyph }}</text>
            <text v-if="body.degree && !compact" :x="body.dx" :y="body.dy" class="planet-deg">
                {{ body.degree }}
                <tspan v-if="body.retrograde" class="planet-retro">℞</tspan>
            </text>
        </g>

        <!-- The planets of the other moment -->
        <g v-for="body in transitBodies" :key="`transit-${body.body}`" class="body transit">
            <title>{{ body.label }}</title>
            <line v-bind="body.marker" class="marker" />
            <line v-if="body.leader" v-bind="body.leader" class="leader" />
            <text :x="body.gx" :y="body.gy" class="planet-glyph">{{ body.glyph }}</text>
            <text v-if="!compact" :x="body.dx" :y="body.dy" class="planet-deg">
                {{ body.degree }}
                <tspan v-if="body.retrograde" class="planet-retro">℞</tspan>
            </text>
        </g>
    </svg>
</template>
