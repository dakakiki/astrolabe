import i18n from '@/portal/i18n';
import { useToastStore } from '@/stores/toast';

/**
 * A screen's own request. A 401 or 403 has already moved the person on (the
 * handlers in main.js: sign-in, the practice choice or "no access"), so it is
 * not an error here; anything else is said once, as a toast.
 */
export async function loadScreen(request) {
    try {
        return await request();
    } catch (error) {
        const status = error.response?.status;

        if (status !== 401 && status !== 403) {
            useToastStore().error(i18n.global.t('portal.errors.generic'));
        }

        return null;
    }
}
