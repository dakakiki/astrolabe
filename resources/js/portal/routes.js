// The portal SPA's routes. Kept apart from router.js (which creates the
// browser router) so tests can resolve them with a memory history.
const routes = [
    {
        path: '/sign-in',
        name: 'sign-in',
        component: () => import('@/portal/pages/SignInPage.vue'),
        meta: { guest: true, layout: 'auth' },
    },
    // The pages emailed links open: the token is after "#", and nothing is used up until the button.
    {
        path: '/sign-in/link',
        name: 'sign-in.link',
        component: () => import('@/portal/pages/SignInLinkPage.vue'),
        meta: { public: true, layout: 'auth' },
    },
    {
        path: '/invitation',
        name: 'invitation',
        component: () => import('@/portal/pages/InvitationPage.vue'),
        meta: { public: true, layout: 'auth' },
    },
    {
        path: '/choose',
        name: 'choose',
        component: () => import('@/portal/pages/ChoosePracticePage.vue'),
        meta: { auth: true, layout: 'auth' },
    },
    {
        path: '/no-access',
        name: 'no-access',
        component: () => import('@/portal/pages/NoAccessPage.vue'),
        meta: { auth: true, layout: 'auth' },
    },
    {
        path: '/',
        name: 'home',
        component: () => import('@/portal/pages/HomePage.vue'),
        meta: { practice: true },
    },
    {
        path: '/appointments',
        name: 'appointments',
        component: () => import('@/portal/pages/AppointmentsPage.vue'),
        meta: { practice: true },
    },
    {
        path: '/shared',
        name: 'shared',
        component: () => import('@/portal/pages/SharedPage.vue'),
        meta: { practice: true },
    },
    {
        path: '/profile',
        name: 'profile',
        component: () => import('@/portal/pages/ProfilePage.vue'),
        meta: { practice: true },
    },
    {
        path: '/security',
        name: 'security',
        component: () => import('@/portal/pages/SecurityPage.vue'),
        meta: { auth: true },
    },
    {
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: () => import('@/portal/pages/NotFoundPage.vue'),
        meta: { public: true, layout: 'auth' },
    },
];

export default routes;
