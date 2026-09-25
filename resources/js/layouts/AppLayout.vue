<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import ThemeToggle from '@/components/ThemeToggle.vue';
import { useAuthStore } from '@/stores/auth';

const { t, locale } = useI18n();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const navGroups = [
    {
        label: 'nav.practice',
        items: [
            { to: { name: 'dashboard' }, label: 'nav.dashboard', icon: '◈', exact: true },
            { to: '/clients', label: 'nav.clients', icon: '◉' },
            { to: '/consultations', label: 'nav.consultations', icon: '☉' },
            { to: '/calendar', label: 'nav.calendar', icon: '▦' },
        ],
    },
    {
        label: 'nav.business',
        items: [
            { to: '/services', label: 'nav.services', icon: '◇' },
            { to: '/tasks', label: 'nav.tasks', icon: '✓' },
        ],
    },
    { label: 'nav.workspace', items: [{ to: '/settings', label: 'nav.settings', icon: '⚙' }] },
];

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
    <div class="shell">
        <aside id="sidebar" class="sidebar" :class="{ open: sidebarOpen }">
            <div class="brandmark">
                <div class="glyph" aria-hidden="true">✷</div>
                <div class="name">
                    {{ t('app.name') }}<small>{{ auth.workspace?.name }}</small>
                </div>
            </div>
            <nav class="nav" :aria-label="t('nav.practice')">
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
                <div class="flex-1" />
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
                        <RouterLink to="/settings" role="menuitem">{{ t('nav.settings') }}</RouterLink>
                        <button type="button" role="menuitem" @click="signOut">{{ t('nav.signOut') }}</button>
                    </div>
                </div>
            </header>
            <main class="content">
                <slot />
            </main>
        </div>
    </div>
</template>
