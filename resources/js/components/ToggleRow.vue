<script setup>
import { useId } from 'vue';

/**
 * A setting that is on or off: its label and description, and a switch that
 * screen readers announce with both (role="switch").
 */
defineProps({
    label: { type: String, required: true },
    description: { type: String, default: null },
    disabled: { type: Boolean, default: false },
});

const model = defineModel({ type: Boolean, required: true });
const id = useId();
</script>

<template>
    <div class="toggle-row">
        <div class="min-w-0">
            <div :id="`${id}-label`" class="t-label">{{ label }}</div>
            <div v-if="description" :id="`${id}-desc`" class="t-desc">{{ description }}</div>
        </div>
        <button
            type="button"
            class="switch"
            role="switch"
            :aria-checked="model ? 'true' : 'false'"
            :aria-labelledby="`${id}-label`"
            :aria-describedby="description ? `${id}-desc` : undefined"
            :disabled="disabled"
            @click="model = !model"
        />
    </div>
</template>
