<script setup>
import { useToastStore } from '@/stores/toast';

const toast = useToastStore();
</script>

<template>
    <div class="toast-host" role="status" aria-live="polite">
        <div
            v-for="item in toast.toasts"
            :key="item.id"
            class="toast"
            :class="{ 'is-error': item.kind === 'error' }"
            @click="toast.dismiss(item.id)"
        >
            {{ item.message }}
        </div>
    </div>
</template>
