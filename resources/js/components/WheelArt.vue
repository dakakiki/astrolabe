<script setup>
// Decorative wheel behind the sign-in copy, as in the prototype.
const C = 200;

function point(deg, r) {
    const rad = (deg * Math.PI) / 180;
    return [C + r * Math.cos(rad), C - r * Math.sin(rad)];
}

const spokes = Array.from({ length: 12 }, (_, i) => [...point(i * 30, 122), ...point(i * 30, 196)]);
const ticks = Array.from({ length: 72 }, (_, i) => [...point(i * 5, 170), ...point(i * 5, 178)]);

const nodes = [12, 74, 118, 155, 203, 248, 291, 330];
const web = nodes.flatMap((a, i) => nodes.slice(i + 1).map((b) => [...point(a, 116), ...point(b, 116)]));
</script>

<template>
    <svg class="wheel-bg" viewBox="0 0 400 400" aria-hidden="true">
        <g fill="none" stroke="#8f97e0" stroke-width="1">
            <circle cx="200" cy="200" r="196" />
            <circle cx="200" cy="200" r="170" />
            <circle cx="200" cy="200" r="122" />
            <circle cx="200" cy="200" r="116" />
        </g>
        <g stroke="#8f97e0" stroke-width="1">
            <line v-for="(l, i) in spokes" :key="`s${i}`" :x1="l[0]" :y1="l[1]" :x2="l[2]" :y2="l[3]" />
            <line v-for="(l, i) in ticks" :key="`t${i}`" :x1="l[0]" :y1="l[1]" :x2="l[2]" :y2="l[3]" opacity=".6" />
        </g>
        <g stroke="#a2701f" stroke-width=".8" opacity=".85">
            <line v-for="(l, i) in web" :key="`w${i}`" :x1="l[0]" :y1="l[1]" :x2="l[2]" :y2="l[3]" />
        </g>
    </svg>
</template>
