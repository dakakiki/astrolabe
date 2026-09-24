import { defineStore } from 'pinia';
import { ref } from 'vue';

let nextId = 1;

export const useToastStore = defineStore('toast', () => {
    const toasts = ref([]);

    function push(message, kind = 'info', timeout = 3200) {
        const id = nextId++;
        toasts.value.push({ id, message, kind });
        setTimeout(() => dismiss(id), timeout);
    }

    function dismiss(id) {
        toasts.value = toasts.value.filter((toast) => toast.id !== id);
    }

    return {
        toasts,
        dismiss,
        success: (message) => push(message, 'success'),
        error: (message) => push(message, 'error', 5000),
    };
});
