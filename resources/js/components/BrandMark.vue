<script setup>
// The AstroLabe mark (concept A "Rete", chosen 9 Oct 2026): a night-sky disc in a
// brass limb, the off-centre ecliptic ring of a rete, and the rule sighting a star.
// Geometry from the presentation site's logo generator (website/prototype/tools/logos.mjs);
// colours from the --logo-* tokens, so the mark follows night and day.
import { useId } from 'vue';

const sky = `brand-sky-${useId()}`;
</script>

<template>
    <svg viewBox="0 0 64 64" aria-hidden="true" focusable="false">
        <defs>
            <radialGradient :id="sky" cx=".38" cy=".3" r=".8">
                <stop offset="0" stop-color="var(--logo-sky-1)" />
                <stop offset="1" stop-color="var(--logo-sky-2)" />
            </radialGradient>
        </defs>
        <circle cx="32" cy="7.6" r="3.7" fill="none" stroke="var(--logo-limb)" stroke-width="2.2" />
        <circle cx="32" cy="35" r="22.2" :fill="`url(#${sky})`" />
        <circle cx="32" cy="35" r="23.5" fill="none" stroke="var(--logo-limb)" stroke-width="2.6" />
        <circle cx="32" cy="31" r="13" fill="none" stroke="var(--logo-brass)" stroke-width="1.7" />
        <path d="M43.1 15.77L20.9 54.23" stroke="var(--logo-brass)" stroke-width="1.5" />
        <circle cx="32" cy="35" r="2.3" fill="var(--logo-brass)" />
        <path
            d="M40.15 13.88Q41.6 19.43 47.15 20.88Q41.6 22.33 40.15 27.88Q38.7 22.33 33.15 20.88Q38.7 19.43 40.15 13.88Z"
            fill="var(--logo-star)"
        />
    </svg>
</template>
