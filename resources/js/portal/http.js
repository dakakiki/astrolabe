import axios from 'axios';

// The portal's API on its own host (docs/spec/12): the session cookie and the
// CSRF cookie are the portal's, never the application's.
const http = axios.create({
    baseURL: '/api/portal/v1',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

let onUnauthenticated = () => {};
let onNoPractice = () => {};

/** The session ended (sign-out elsewhere, expiry, "sign out everywhere"). */
export function setUnauthenticatedHandler(handler) {
    onUnauthenticated = handler;
}

/** The open practice closed (access revoked, client archived) or none is chosen yet. */
export function setNoPracticeHandler(handler) {
    onNoPractice = handler;
}

http.interceptors.response.use(
    (response) => response,
    async (error) => {
        const status = error.response?.status;
        const config = error.config ?? {};
        const code = error.response?.data?.code;

        // An expired CSRF token: the session endpoint sets a fresh cookie; repeat once.
        if (status === 419 && !config._retriedAfterCsrf) {
            config._retriedAfterCsrf = true;
            await http.get('/session');

            return http(config);
        }

        if (status === 401 && !config.skipAuthRedirect) {
            onUnauthenticated();
        }

        if (status === 403 && (code === 'portal_no_access' || code === 'portal_choose_practice')) {
            onNoPractice(code);
        }

        return Promise.reject(error);
    },
);

/** Laravel's 422 payload as { field: 'first message' }, or null for other errors. */
export function validationErrors(error) {
    if (error.response?.status !== 422) return null;

    return Object.fromEntries(
        Object.entries(error.response.data.errors ?? {}).map(([field, messages]) => [field, messages[0]]),
    );
}

export default http;
