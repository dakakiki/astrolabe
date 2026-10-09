// The SPA routes. Kept apart from index.js (which creates the browser router)
// so tests can resolve them with a memory history.
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
                path: 'branding',
                name: 'settings.branding',
                component: () => import('@/pages/settings/BrandingSettings.vue'),
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
            {
                path: 'data',
                name: 'settings.data',
                component: () => import('@/pages/settings/DataSettings.vue'),
                // Open while new terms wait for acceptance: export and leave (Phase 8c).
                meta: { legal: true },
            },
        ],
    },

    // The operator's admin (Phase 8c): only for the admin account, which has no practice.
    {
        path: '/admin',
        redirect: { name: 'admin.astrologers' },
    },
    {
        path: '/admin/astrologers',
        name: 'admin.astrologers',
        component: () => import('@/pages/admin/AdminAstrologersPage.vue'),
        meta: { admin: true },
    },
    {
        path: '/admin/astrologers/:id(\\d+)',
        name: 'admin.astrologer',
        component: () => import('@/pages/admin/AdminAstrologerPage.vue'),
        meta: { admin: true },
    },
    {
        path: '/admin/audit-log',
        name: 'admin.audit',
        component: () => import('@/pages/admin/AdminAuditLogPage.vue'),
        meta: { admin: true },
    },
    {
        path: '/admin/invitations',
        name: 'admin.invitations',
        component: () => import('@/pages/admin/AdminInvitationsPage.vue'),
        meta: { admin: true },
    },
    {
        path: '/admin/feedback',
        name: 'admin.feedback',
        component: () => import('@/pages/admin/AdminFeedbackPage.vue'),
        meta: { admin: true },
    },
    {
        path: '/admin/system',
        name: 'admin.system',
        component: () => import('@/pages/admin/AdminSystemPage.vue'),
        meta: { admin: true },
    },
    {
        path: '/admin/security',
        name: 'admin.security',
        component: () => import('@/pages/admin/AdminSecurityPage.vue'),
        meta: { admin: true },
    },

    // Terms of Service, Data Processing Agreement, Privacy Policy (Phase 8c): public, and
    // the screen for a new version, which closes the practice until it is accepted.
    {
        path: '/legal/accept',
        name: 'legal.accept',
        component: () => import('@/pages/legal/LegalAcceptancePage.vue'),
        meta: { verified: true, legal: true, layout: 'auth' },
    },
    {
        path: '/legal/:document(terms|dpa|privacy)',
        name: 'legal.show',
        component: () => import('@/pages/legal/LegalDocumentPage.vue'),
        meta: { layout: 'bare' },
    },

    // A practice scheduled for deletion shows only this (Phase 8b).
    {
        path: '/practice-deletion',
        name: 'practice-deletion',
        component: () => import('@/pages/PracticeDeletionPage.vue'),
        meta: { verified: true, closing: true, layout: 'auth' },
    },
    // The export link from the email, after signing in: the server sends the file.
    {
        path: '/exports/:id(\\d+)/download',
        name: 'practice-exports.link',
        component: () => import('@/pages/NotFoundPage.vue'),
        meta: { verified: true, closing: true },
        beforeEnter: (to) => {
            window.location.assign(to.fullPath);

            return false;
        },
    },

    {
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: () => import('@/pages/NotFoundPage.vue'),
        meta: { layout: 'bare' },
    },
];

export default routes;
