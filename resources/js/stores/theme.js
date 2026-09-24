import { defineStore } from 'pinia';
import { ref, watch } from 'vue';

const STORAGE_KEY = 'astrolabe.theme';

function readStored() {
    try {
        return localStorage.getItem(STORAGE_KEY) === 'day' ? 'day' : 'night';
    } catch {
        return 'night';
    }
}

// The theme is a per-device convenience, so browser storage is appropriate here;
// nothing private is ever stored this way.
export const useThemeStore = defineStore('theme', () => {
    const theme = ref(readStored());

    watch(
        theme,
        (value) => {
            if (value === 'day') {
                document.documentElement.dataset.theme = 'day';
            } else {
                delete document.documentElement.dataset.theme;
            }

            try {
                localStorage.setItem(STORAGE_KEY, value);
            } catch {
                // Storage can be unavailable (private mode); the theme still applies.
            }
        },
        { immediate: true },
    );

    function toggle() {
        theme.value = theme.value === 'day' ? 'night' : 'day';
    }

    return { theme, toggle };
});
