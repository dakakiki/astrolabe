import axios from 'axios';

// First-party SPA auth goes through Sanctum's cookie session (doc 03):
// credentials travel as cookies and tokens are never kept in localStorage.
const http = axios.create({
    baseURL: '/api/v1',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

/** Fetch the CSRF cookie; needed before the first state-changing request of a visit. */
export function ensureCsrfCookie() {
    return axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

let onUnauthenticated = () => {};

/** Called when the API reports the session has ended (401), e.g. to show the sign-in screen. */
export function setUnauthenticatedHandler(handler) {
    onUnauthenticated = handler;
}

http.interceptors.response.use(
    (response) => response,
    async (error) => {
        const status = error.response?.status;
        const config = error.config ?? {};

        // An expired CSRF token: fetch a fresh one and repeat the request once.
        if (status === 419 && !config._retriedAfterCsrf) {
            config._retriedAfterCsrf = true;
            await ensureCsrfCookie();
            return http(config);
        }

        if (status === 401 && !config.skipAuthRedirect) {
            onUnauthenticated();
        }

        return Promise.reject(error);
    },
);

/** Laravel's 422 payload as { field: 'first message' }, or null for other errors. */
export function validationErrors(error) {
    if (error.response?.status !== 422) {
        return null;
    }

    return Object.fromEntries(
        Object.entries(error.response.data.errors ?? {}).map(([field, messages]) => [field, messages[0]]),
    );
}

export default http;
