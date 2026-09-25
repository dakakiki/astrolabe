import { createRouter, createWebHistory } from 'vue-router';

import { useAuthStore } from '@/stores/auth';

import { resolveNavigation } from './guard';

const routes = [
    // Signed-out screens
    {
        path: '/login',
        name: 'login',
        component: () => import('@/pages/auth/LoginPage.vue'),
        meta: { guest: true, layout: 'auth' },
    },
    {
        path: '/register',
        name: 'register',
        component: () => import('@/pages/auth/RegisterPage.vue'),
        meta: { guest: true, layout: 'auth' },
    },
    {
        path: '/forgot-password',
        name: 'forgot-password',
        component: () => import('@/pages/auth/ForgotPasswordPage.vue'),
        meta: { guest: true, layout: 'auth' },
    },
    {
        path: '/reset-password/:token',
        name: 'reset-password',
        component: () => import('@/pages/auth/ResetPasswordPage.vue'),
        meta: { guest: true, layout: 'auth' },
    },

    // Email verification: the notice, and the page the emailed link opens
    {
        path: '/verify-email',
        name: 'verify-email',
        component: () => import('@/pages/auth/VerifyEmailPage.vue'),
        meta: { auth: true, layout: 'auth' },
    },
    {
        path: '/verify-email/:id/:hash',
        name: 'verify-email-link',
        component: () => import('@/pages/auth/VerifyEmailLinkPage.vue'),
        meta: { auth: true, layout: 'auth' },
    },

    // The application
    {
        path: '/',
        name: 'dashboard',
        component: () => import('@/pages/DashboardPage.vue'),
        meta: { verified: true },
    },
    {
        path: '/clients',
        name: 'clients.index',
        component: () => import('@/pages/clients/ClientsPage.vue'),
        meta: { verified: true },
    },
    {
        path: '/clients/new',
        name: 'clients.create',
        component: () => import('@/pages/clients/ClientFormPage.vue'),
        meta: { verified: true },
    },
    {
        path: '/clients/:id(\\d+)',
        name: 'clients.show',
        component: () => import('@/pages/clients/ClientPage.vue'),
        meta: { verified: true },
    },
    {
        path: '/clients/:id(\\d+)/edit',
        name: 'clients.edit',
        component: () => import('@/pages/clients/ClientFormPage.vue'),
        meta: { verified: true },
    },
    {
        path: '/consultations',
        name: 'consultations.index',
        component: () => import('@/pages/consultations/ConsultationsPage.vue'),
        meta: { verified: true },
    },
    {
        path: '/consultations/new',
        name: 'consultations.create',
        component: () => import('@/pages/consultations/ConsultationPage.vue'),
        meta: { verified: true },
    },
    {
        path: '/consultations/:id(\\d+)',
        name: 'consultations.show',
        component: () => import('@/pages/consultations/ConsultationPage.vue'),
        meta: { verified: true },
    },
    {
        path: '/clients/:clientId(\\d+)/people/new',
        name: 'related-people.create',
        component: () => import('@/pages/related/RelatedPersonFormPage.vue'),
        meta: { verified: true },
    },
    {
        path: '/people/:id(\\d+)',
        name: 'related-people.show',
        component: () => import('@/pages/related/RelatedPersonPage.vue'),
        meta: { verified: true },
    },
    {
        path: '/people/:id(\\d+)/edit',
        name: 'related-people.edit',
        component: () => import('@/pages/related/RelatedPersonFormPage.vue'),
        meta: { verified: true },
    },
    {
        path: '/services',
        name: 'services.index',
        component: () => import('@/pages/services/ServicesPage.vue'),
        meta: { verified: true },
    },
    {
        path: '/calendar',
        name: 'calendar',
        component: () => import('@/pages/calendar/CalendarPage.vue'),
        meta: { verified: true },
    },
    {
        path: '/sky',
        name: 'sky',
        component: () => import('@/pages/sky/SkyCalendarPage.vue'),
        meta: { verified: true },
    },
    {
        path: '/tasks',
        name: 'tasks.index',
        component: () => import('@/pages/tasks/TasksPage.vue'),
        meta: { verified: true },
    },
    {
        path: '/payments',
        name: 'payments.index',
        component: () => import('@/pages/payments/PaymentsPage.vue'),
        meta: { verified: true },
    },
    {
        path: '/settings',
        component: () => import('@/pages/settings/SettingsPage.vue'),
        meta: { verified: true },
        children: [
            { path: '', redirect: { name: 'settings.profile' } },
            {
                path: 'profile',
                name: 'settings.profile',
                component: () => import('@/pages/settings/ProfileSettings.vue'),
            },
            {
                path: 'practice',
                name: 'settings.practice',
                component: () => import('@/pages/settings/PracticeSettings.vue'),
            },
            {
                path: 'chart',
                name: 'settings.chart',
                component: () => import('@/pages/settings/ChartSettings.vue'),
            },
            {
                path: 'regional',
                name: 'settings.regional',
                component: () => import('@/pages/settings/RegionalSettings.vue'),
            },
            {
                path: 'notifications',
                name: 'settings.notifications',
                component: () => import('@/pages/settings/NotificationSettings.vue'),
            },
            {
                path: 'security',
                name: 'settings.security',
                component: () => import('@/pages/settings/SecuritySettings.vue'),
            },
        ],
    },

    {
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: () => import('@/pages/NotFoundPage.vue'),
        meta: { layout: 'bare' },
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();
    await auth.load();

    return resolveNavigation(to, { user: auth.user });
});

export default router;
