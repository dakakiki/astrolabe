<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute } from 'vue-router';

import AdminActionDialog from '@/components/admin/AdminActionDialog.vue';
import AuditLogTable from '@/components/admin/AuditLogTable.vue';
import PaginationBar from '@/components/PaginationBar.vue';
import { astrologerState, stateTone } from '@/lib/admin';
import { formatDateTime } from '@/lib/datetime';
import { formatBytes } from '@/lib/files';
import http from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

/**
 * One astrologer in the admin (Phase 8c): the account, the practice's figures,
 * how they came in, account help, and their audit log. Never client data.
 */
const { t, locale } = useI18n();
const route = useRoute();
const auth = useAuthStore();
const toast = useToastStore();

const astrologer = ref(null);
const extra = ref(null);
const notFound = ref(false);
const entries = ref([]);
const entriesMeta = ref(null);
const dialog = ref(null);

const zone = computed(() => auth.user?.timezone || 'UTC');
const when = (iso) => (iso ? formatDateTime(iso, locale.value, zone.value) : t('admin.common.never'));
const state = computed(() => (astrologer.value ? astrologerState(astrologer.value) : null));

async function load() {
    try {
        const { data } = await http.get(`/admin/astrologers/${route.params.id}`);
        astrologer.value = data.data;
        extra.value = data.meta;
    } catch (error) {
        if (error.response?.status === 404) notFound.value = true;
        else toast.error(t('errors.generic'));
    }
}

async function loadEntries(page = 1) {
    const { data } = await http.get('/admin/audit-logs', { params: { user_id: route.params.id, page, per_page: 20 } });
    entries.value = data.data;
    entriesMeta.value = data.meta;
}

const actions = computed(() => {
    const a = astrologer.value;
    if (!a) return [];

    const list = [];
    if (a.two_factor_enabled)
        list.push({ key: 'resetTwoFactor', path: 'two-factor-reset', method: 'post', required: true, danger: true });
    if (!a.email_verified) list.push({ key: 'verification', path: 'verification', method: 'post', required: false });
    list.push(
        a.suspended_at
            ? { key: 'restore', path: 'suspension', method: 'delete', required: false }
            : { key: 'suspend', path: 'suspension', method: 'post', required: true, danger: true },
    );

    return list;
});

function start(action) {
    const name = astrologer.value.name;

    dialog.value.open({
        key: action.key,
        title: t(`admin.astrologer.actions.${action.key}.title`, { name }),
        body: t(`admin.astrologer.actions.${action.key}.body`),
        submit: t(`admin.astrologer.actions.${action.key}.button`),
        reasonRequired: action.required,
        danger: action.danger,
        run: async (reason) => {
            const url = `/admin/astrologers/${astrologer.value.id}/${action.path}`;
            const { data } =
                action.method === 'delete'
                    ? await http.delete(url, { data: { reason } })
                    : await http.post(url, { reason });

            return { key: action.key, astrologer: data.data };
        },
    });
}

function done({ key, astrologer: updated }) {
    astrologer.value = updated;
    toast.success(t(`admin.astrologer.actions.${key}.done`));
    loadEntries();
}

onMounted(async () => {
    await load();
    if (astrologer.value) loadEntries().catch(() => {});
});
</script>

<template>
    <p v-if="notFound" class="text-ink-3">{{ t('notFound.title') }}</p>

    <template v-else-if="astrologer">
        <div class="page-head">
            <div class="eyebrow">
                <RouterLink :to="{ name: 'admin.astrologers' }" class="text-ink-4 hover:underline">{{
                    t('admin.astrologer.back')
                }}</RouterLink>
            </div>
            <h1>{{ astrologer.name }}</h1>
            <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                <span class="badge" :class="stateTone(state)">{{ t(`admin.astrologers.statuses.${state}`) }}</span>
                <span class="text-sm text-ink-3">{{ astrologer.email }}</span>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <section class="card">
                <div class="card-head">
                    <h2>{{ t('admin.astrologer.account') }}</h2>
                </div>
                <dl class="card-body grid grid-cols-[minmax(0,10rem)_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm">
                    <dt class="text-ink-3">{{ t('admin.astrologer.email') }}</dt>
                    <dd>
                        {{ astrologer.email }} ·
                        {{
                            astrologer.email_verified
                                ? t('admin.astrologer.emailConfirmed')
                                : t('admin.astrologer.emailNotConfirmed')
                        }}
                    </dd>
                    <dt class="text-ink-3">{{ t('admin.astrologer.twoFactor') }}</dt>
                    <dd>{{ astrologer.two_factor_enabled ? t('admin.astrologer.on') : t('admin.astrologer.off') }}</dd>
                    <dt class="text-ink-3">{{ t('admin.astrologer.registered') }}</dt>
                    <dd>{{ when(astrologer.registered_at) }}</dd>
                    <dt class="text-ink-3">{{ t('admin.astrologer.lastLogin') }}</dt>
                    <dd>{{ when(astrologer.last_login_at) }}</dd>
                    <dt class="text-ink-3">{{ t('admin.astrologer.locale') }}</dt>
                    <dd>{{ astrologer.locale }} · {{ astrologer.timezone }}</dd>
                    <dt class="text-ink-3">{{ t('admin.astrologer.invitation') }}</dt>
                    <dd>
                        <template v-if="extra?.invitation">
                            {{ when(extra.invitation.sent_at) }}
                            <span v-if="extra.invitation.note" class="block text-ink-3">{{
                                extra.invitation.note
                            }}</span>
                        </template>
                        <span v-else class="text-ink-3">{{ t('admin.astrologer.noInvitation') }}</span>
                    </dd>
                </dl>
                <div v-if="astrologer.suspended_at" class="card-body pt-0">
                    <div class="notice n-danger">
                        <div>
                            <strong>{{
                                t('admin.astrologer.suspendedSince', { date: when(astrologer.suspended_at) })
                            }}</strong>
                            {{ astrologer.suspension_reason }}
                        </div>
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="card-head">
                    <h2>{{ t('admin.astrologer.practice') }}</h2>
                    <span v-if="astrologer.practice" class="right">{{ astrologer.practice.name }}</span>
                </div>
                <div class="card-body space-y-3 text-sm">
                    <div v-if="astrologer.practice?.deletes_at" class="notice n-warn">
                        {{ t('admin.astrologer.deletesOn', { date: when(astrologer.practice.deletes_at) }) }}
                    </div>
                    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div>
                            <dt class="text-xs text-ink-3">{{ t('admin.astrologers.columns.clients') }}</dt>
                            <dd class="font-mono text-lg">{{ astrologer.counts.clients }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-3">{{ t('admin.astrologers.columns.consultations') }}</dt>
                            <dd class="font-mono text-lg">{{ astrologer.counts.consultations }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-3">{{ t('admin.astrologers.columns.appointments') }}</dt>
                            <dd class="font-mono text-lg">{{ astrologer.counts.appointments }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-3">{{ t('admin.astrologers.columns.storage') }}</dt>
                            <dd class="font-mono text-lg">{{ formatBytes(astrologer.storage_bytes, locale) }}</dd>
                        </div>
                    </dl>
                    <div v-if="extra?.memberships?.length">
                        <div class="text-xs text-ink-3">{{ t('admin.astrologer.memberships') }}</div>
                        <div v-for="membership in extra.memberships" :key="membership.id">
                            {{ membership.name }} · {{ t(`roles.${membership.role}`) }}
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <section class="card mt-4">
            <div class="card-head">
                <h2>{{ t('admin.astrologer.help') }}</h2>
            </div>
            <div class="card-body">
                <p class="mb-3 text-ink-3">{{ t('admin.astrologer.helpIntro') }}</p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="action in actions"
                        :key="action.key"
                        type="button"
                        class="btn"
                        :class="{ 'btn-danger': action.danger }"
                        @click="start(action)"
                    >
                        {{ t(`admin.astrologer.actions.${action.key}.button`) }}
                    </button>
                </div>
            </div>
        </section>

        <section class="card mt-4">
            <div class="card-head">
                <h2>{{ t('admin.astrologer.activity') }}</h2>
            </div>
            <p v-if="entriesMeta && entries.length === 0" class="card-body text-ink-3">{{ t('admin.audit.empty') }}</p>
            <AuditLogTable v-else :entries="entries" :show-who="false" />
            <PaginationBar v-if="entriesMeta && entriesMeta.last_page > 1" :meta="entriesMeta" @page="loadEntries" />
        </section>

        <AdminActionDialog ref="dialog" @done="done" />
    </template>

    <p v-else class="text-ink-3">{{ t('admin.common.loading') }}</p>
</template>
