<script setup>
import { onBeforeUnmount, ref } from 'vue';

import AppIcon from '@/components/AppIcon.vue';

/**
 * A section that folds away: a framed row with the title and an arrow on the right; it slides
 * open and shut. Built on <details>, so the keyboard (Enter / Space on the title) and screen
 * readers treat it as one; the slide is a Web Animation of the content's height, skipped when
 * the reader asked for less motion.
 */
defineProps({ open: { type: Boolean, default: false } });

const DURATION = 250;

const details = ref(null);
const body = ref(null);
let running = null;
let slideId = 0;

function reducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
}

function slide(from, to, onDone) {
    running?.cancel();
    const id = ++slideId;
    body.value.style.overflow = 'hidden';
    running = body.value.animate([{ height: `${from}px` }, { height: `${to}px` }], {
        duration: DURATION,
        easing: 'ease',
    });

    // The animation's end, or a timer as a fallback: a hidden tab draws no frames, so its
    // animations only finish when it is shown again. Only the latest slide may finish.
    const finish = () => {
        if (id !== slideId || !body.value) return;
        slideId++;
        running = null;
        body.value.style.overflow = '';
        onDone?.();
    };
    running.onfinish = finish;
    setTimeout(finish, DURATION + 100);
}

function toggle() {
    const el = details.value;
    if (reducedMotion() || typeof body.value.animate !== 'function') {
        el.open = !el.open;
        return;
    }

    if (!el.open || el.dataset.closing) {
        // Opening (or turning back while it shuts): show it, then grow from where it is.
        const from = el.dataset.closing ? body.value.getBoundingClientRect().height : 0;
        delete el.dataset.closing;
        el.open = true;
        slide(from, body.value.scrollHeight);
    } else {
        // Shutting: keep it open while it slides up, then close it.
        el.dataset.closing = '1';
        slide(body.value.getBoundingClientRect().height, 0, () => {
            delete el.dataset.closing;
            el.open = false;
        });
    }
}

onBeforeUnmount(() => running?.cancel());
</script>

<template>
    <details ref="details" class="accordion" :open="open">
        <summary @click.prevent="toggle">
            <span><slot name="title" /></span>
            <AppIcon name="more" class="chevron" />
        </summary>
        <div ref="body" class="accordion-body">
            <div class="accordion-inner">
                <slot />
            </div>
        </div>
    </details>
</template>
