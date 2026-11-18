<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import AppIcon from '@/components/AppIcon.vue';
import { formatDateTime } from '@/lib/datetime';
import { formatRelative } from '@/lib/format';
import http from '@/lib/http';
import { describeNotice } from '@/lib/inAppNotifications';
import { useAuthStore } from '@/stores/auth';

/**
 * The bell in the header (docs/spec/09, "Obaveštenja"): the person's notifications
 * in the app — in the practice open now and about their account — the newest
 * first, with a count of the unread ones. Which kinds come here is their choice in
 * Settings → Notifications. Opening one marks it read and goes where it is about.
 *
 * Read again every minute while the tab is visible, and on each page change.
 */
const { t, locale } = useI18n();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const POLL_MS = 60_000;

const open = ref(false);
const items = ref([]);
const unread = ref(0);
const failed = ref(false);
const root = ref(null);
let timer = null;

const zone = computed(() => auth.user?.timezone ?? 'UTC');
const moment = (iso) => (iso ? formatDateTime(iso, locale.value, zone.value) : '');
const day = (iso) => (iso ? formatDateTime(iso, locale.value, zone.value, { dateStyle: 'long' }) : '');

const rows = computed(() =>
    items.value.map((item) => {
        const notice = describeNotice(item);
        const params = {
            client: notice.client ?? t('bell.someClient'),
            when: moment(notice.at),
            from: moment(notice.from),
            day: day(notice.day),
            count: notice.count ?? 0,
        };

        return {
            item,
            notice,
            text: notice.count !== undefined ? t(notice.title, params, notice.count) : t(notice.title, params),
            detail:
                notice.overdue > 0
                    ? t('bell.overdue', { count: notice.overdue }, notice.overdue)
                    : notice.from
                      ? t('bell.fromTo', params)
                      : notice.at
                        ? params.when
                        : '',
            ago: formatRelative(item.created_at, locale.value),
        };
    }),
);

async function load() {
    if (document.visibilityState === 'hidden') return;

    try {
        const { data } = await http.get('/notifications');
        items.value = data.data;
        unread.value = data.meta.unread;
        failed.value = false;
    } catch {
        failed.value = true;
    }
}

async function toggle() {
    open.value = !open.value;
    if (open.value) await load();
}

async function choose(row) {
    open.value = false;

    if (!row.item.read_at) {
        row.item.read_at = new Date().toISOString();
        unread.value = Math.max(0, unread.value - 1);
        http.post(`/notifications/${row.item.id}/read`).catch(() => {});
    }

    if (row.notice.to) await router.push(row.notice.to);
}

async function readAll() {
    try {
        await http.post('/notifications/read-all');
        items.value.forEach((item) => {
            item.read_at ??= new Date().toISOString();
        });
        unread.value = 0;
    } catch {
        failed.value = true;
    }
}

function outside(event) {
    if (open.value && root.value && !root.value.contains(event.target)) open.value = false;
}

function escape(event) {
    if (event.key === 'Escape') open.value = false;
}

onMounted(() => {
    load();
    timer = window.setInterval(load, POLL_MS);
    document.addEventListener('visibilitychange', load);
    document.addEventListener('click', outside);
    document.addEventListener('keydown', escape);
});

onBeforeUnmount(() => {
    window.clearInterval(timer);
    document.removeEventListener('visibilitychange', load);
    document.removeEventListener('click', outside);
    document.removeEventListener('keydown', escape);
});

// A page change may be the answer to one (a request confirmed): read the count again.
watch(() => route.fullPath, load);
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            class="icon-btn bell"
            :aria-label="unread ? t('bell.openUnread', { count: unread }, unread) : t('bell.open')"
            aria-haspopup="dialog"
            :aria-expanded="open"
            @click="toggle"
            v-tip="t('tips.bell.open')"
        >
            <AppIcon name="notifications" />
            <span v-if="unread" class="bell-count" aria-hidden="true">{{ unread > 99 ? '99+' : unread }}</span>
        </button>

        <div v-if="open" class="menu bell-panel" role="dialog" :aria-label="t('bell.title')">
            <div class="bell-head">
                <strong>{{ t('bell.title') }}</strong>
                <button
                    v-if="unread"
                    type="button"
                    class="btn btn-ghost btn-sm"
                    @click="readAll"
                    v-tip="t('tips.bell.readAll')"
                >
                    <AppIcon name="markRead" />
                    {{ t('bell.readAll') }}
                </button>
            </div>

            <p v-if="failed && !items.length" class="bell-empty" role="alert">{{ t('errors.generic') }}</p>
            <p v-else-if="!items.length" class="bell-empty">{{ t('bell.empty') }}</p>
            <ul v-else class="bell-list">
                <li v-for="row in rows" :key="row.item.id">
                    <button
                        type="button"
                        class="bell-item"
                        :class="{ unread: !row.item.read_at }"
                        @click="choose(row)"
                        v-tip="t('tips.bell.item')"
                    >
                        <AppIcon :name="row.notice.icon" class="bell-icon" />
                        <span class="min-w-0 flex-1">
                            <span class="block">{{ row.text }}</span>
                            <span v-if="row.detail" class="block font-mono text-xs text-ink-2">{{ row.detail }}</span>
                            <span class="block text-xs text-ink-3">{{ row.ago }}</span>
                        </span>
                        <span v-if="!row.item.read_at" class="bell-dot" :aria-label="t('bell.unread')" />
                    </button>
                </li>
            </ul>

            <RouterLink :to="{ name: 'settings.notifications' }" class="bell-settings" @click="open = false">
                <AppIcon name="settings" />{{ t('bell.settings') }}
            </RouterLink>
        </div>
    </div>
</template>
