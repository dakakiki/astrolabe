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

/** Fetch the CSRF cookie; call once before the first state-changing request. */
export function ensureCsrfCookie() {
    return axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

export default http;
