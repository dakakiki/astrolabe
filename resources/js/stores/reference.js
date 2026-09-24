import { defineStore } from 'pinia';
import { ref } from 'vue';

import http from '@/lib/http';

/** Allowed values for forms (locales, currencies, chart options), fetched once per visit. */
export const useReferenceStore = defineStore('reference', () => {
    const data = ref(null);
    let pending = null;

    function load() {
        pending ??= http
            .get('/reference-data')
            .then((response) => {
                data.value = response.data.data;
            })
            .catch((error) => {
                pending = null;
                throw error;
            });

        return pending;
    }

    return { data, load };
});
