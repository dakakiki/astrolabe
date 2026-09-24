import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import { validationErrors } from '@/lib/http';
import { useToastStore } from '@/stores/toast';

/**
 * Form state for one API call: field values, per-field server errors and a
 * busy flag. Validation errors land next to their fields; anything else
 * becomes a toast, so a failure is never silent.
 */
export function useForm(initial) {
    const { t } = useI18n();
    const toast = useToastStore();

    const data = reactive({ ...initial });
    const errors = ref({});
    const processing = ref(false);

    async function submit(request) {
        processing.value = true;
        errors.value = {};

        try {
            return await request(data);
        } catch (error) {
            const fieldErrors = validationErrors(error);

            if (fieldErrors) {
                errors.value = fieldErrors;
            } else if (error.response?.status === 429) {
                toast.error(t('errors.tooManyAttempts'));
            } else if (error.response?.status === 403) {
                toast.error(t('errors.forbidden'));
            } else if (error.response?.status !== 401) {
                toast.error(t('errors.generic'));
            }

            throw error;
        } finally {
            processing.value = false;
        }
    }

    function reset(values = initial) {
        Object.assign(data, values);
        errors.value = {};
    }

    return { data, errors, processing, submit, reset };
}
