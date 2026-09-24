<script setup>
import { useId } from 'vue';

defineProps({
    label: { type: String, required: true },
    error: { type: String, default: null },
    hint: { type: String, default: null },
});

// The slot receives the id and ARIA attributes to put on its control, so the
// label, hint and error are announced together by screen readers.
const id = useId();
</script>

<template>
    <div class="field">
        <label :for="id">{{ label }}</label>
        <slot
            :id="id"
            :aria="{
                'aria-invalid': error ? 'true' : undefined,
                'aria-describedby': error ? `${id}-error` : hint ? `${id}-hint` : undefined,
            }"
        />
        <div v-if="error" :id="`${id}-error`" class="error">{{ error }}</div>
        <div v-else-if="hint" :id="`${id}-hint`" class="hint">{{ hint }}</div>
    </div>
</template>
