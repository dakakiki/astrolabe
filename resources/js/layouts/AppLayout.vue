<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import FeedbackDialog from '@/components/FeedbackDialog.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { useAuthStore } from '@/stores/auth';

const { t, locale } = useI18n();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const practiceNav = [
    {
        label: 'nav.practice',
        items: [
            { to: { name: 'dashboard' }, label: 'nav.dashboard', icon: '◈', exact: true },
            { to: '/clients', label: 'nav.clients', icon: '◉' },
            { to: '/consultations', label: 'nav.consultations', icon: '☉' },
            { to: '/calendar', label: 'nav.calendar', icon: '▦' },
            { to: '/sky', label: 'nav.sky', icon: '✷' },
        ],
    },
    {
        label: 'nav.business',
        items: [
            { to: '/services', label: 'nav.services', icon: '◇' },
            { to: '/payments', label: 'nav.payments', icon: '¤' },
            { to: '/tasks', label: 'nav.tasks', icon: '✓' },
        ],
    },
    { label: 'nav.workspace', items: [{ to: '/settings', label: 'nav.settings', icon: '⚙' }] },
];

// The operator's admin (Phase 8c): the same frame, its own screens, no practice.
const adminNav = [
    {
        label: 'nav.admin',
        items: [
            { to: { name: 'admin.astrologers' }, label: 'nav.astrologers', icon: '◉' },
            { to: { name: 'admin.audit' }, label: 'nav.auditLog', icon: '☰' },
            { to: { name: 'admin.invitations' }, label: 'nav.invitations', icon: '✉' },
            { to: { name: 'admin.feedback' }, label: 'nav.inbox', icon: '✎' },
            { to: { name: 'admin.system' }, label: 'nav.system', icon: '◈' },
        ],
    },
    { label: 'nav.operator', items: [{ to: { name: 'admin.security' }, label: 'nav.security', icon: '⚙' }] },
];

const navGroups = computed(() => (auth.isAdmin ? adminNav : practiceNav));
const feedbackDialog = ref(null);

const sidebarOpen = ref(false);
const menuOpen = ref(false);
const menuRoot = ref(null);

watch(
    () => route.fullPath,
    () => {
        sidebarOpen.value = false;
        menuOpen.value = false;
    },
);

const initials = computed(() =>
    (auth.user?.name ?? '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join(''),
);

// The user's own time zone and the time there, as in the prototype's top bar.
const now = ref(new Date());
let clock;
onMounted(() => (clock = setInterval(() => (now.value = new Date()), 30_000)));
onBeforeUnmount(() => clearInterval(clock));

const localTime = computed(() => {
    try {
        return new Intl.DateTimeFormat(locale.value, {
            hour: '2-digit',
            minute: '2-digit',
            timeZone: auth.user?.timezone,
        }).format(now.value);
    } catch {
        return '';
    }
});

function closeMenuOnOutsideClick(event) {
    if (menuOpen.value && !menuRoot.value?.contains(event.target)) {
        menuOpen.value = false;
    }
}
onMounted(() => document.addEventListener('click', closeMenuOnOutsideClick));
onBeforeUnmount(() => document.removeEventListener('click', closeMenuOnOutsideClick));

async function signOut() {
    await auth.logout();
    router.push({ name: 'login' });
}
</script>

<template>
    <div class="shell" :class="{ 'shell-admin': auth.isAdmin }">
        <aside id="sidebar" class="sidebar" :class="{ open: sidebarOpen }">
            <div class="brandmark">
                <div class="glyph" aria-hidden="true">✷</div>
                <div class="name">
                    {{ t('app.name') }}<small>{{ auth.isAdmin ? t('admin.badge') : auth.workspace?.name }}</small>
                </div>
            </div>
            <nav class="nav" :aria-label="t(auth.isAdmin ? 'nav.admin' : 'nav.practice')">
                <template v-for="group in navGroups" :key="group.label">
                    <div class="nav-label">{{ t(group.label) }}</div>
                    <RouterLink
                        v-for="item in group.items"
                        :key="item.label"
                        :to="item.to"
                        :exact-active-class="item.exact ? 'router-link-active' : ''"
                        :active-class="item.exact ? '' : 'router-link-active'"
                    >
                        <span class="ico" aria-hidden="true">{{ item.icon }}</span
                        >{{ t(item.label) }}
                    </RouterLink>
                </template>
            </nav>
        </aside>
        <div class="backdrop" :class="{ open: sidebarOpen }" @click="sidebarOpen = false" />

        <div class="main">
            <header class="topbar">
                <button
                    type="button"
                    class="menu-btn"
                    :aria-label="t('nav.openMenu')"
                    aria-controls="sidebar"
                    :aria-expanded="sidebarOpen"
                    @click="sidebarOpen = !sidebarOpen"
                >
                    ☰
                </button>
                <span v-if="auth.isAdmin" class="badge b-warn">{{ t('admin.badge') }}</span>
                <div class="flex-1" />
                <button v-if="!auth.isAdmin" type="button" class="btn btn-ghost btn-sm" @click="feedbackDialog.open()">
                    {{ t('nav.feedback') }}
                </button>
                <span class="tz-chip">{{ auth.user?.timezone }} · {{ localTime }}</span>
                <ThemeToggle />
                <div ref="menuRoot" class="relative">
                    <button
                        type="button"
                        class="avatar"
                        :aria-label="t('nav.userMenu')"
                        aria-haspopup="menu"
                        :aria-expanded="menuOpen"
                        @click="menuOpen = !menuOpen"
                    >
                        {{ initials }}
                    </button>
                    <div v-if="menuOpen" class="menu" role="menu">
                        <div class="who">
                            <div class="font-medium text-ink">{{ auth.user?.name }}</div>
                            <div class="truncate text-xs text-ink-3">{{ auth.user?.email }}</div>
                        </div>
                        <RouterLink v-if="auth.isAdmin" :to="{ name: 'admin.security' }" role="menuitem">{{
                            t('nav.security')
                        }}</RouterLink>
                        <RouterLink v-else to="/settings" role="menuitem">{{ t('nav.settings') }}</RouterLink>
                        <button type="button" role="menuitem" @click="signOut">{{ t('nav.signOut') }}</button>
                    </div>
                </div>
            </header>
            <main class="content">
                <slot />
            </main>
        </div>
        <FeedbackDialog v-if="!auth.isAdmin" ref="feedbackDialog" />
    </div>
</template>
