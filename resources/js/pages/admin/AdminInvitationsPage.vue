<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

import FormField from '@/components/FormField.vue';
import PaginationBar from '@/components/PaginationBar.vue';
import { useForm } from '@/composables/useForm';
import { formatDateTime } from '@/lib/datetime';
import http from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

/**
 * Closed-beta invitations from the admin (Phase 8c) — the same as the
 * `invitations:*` commands. A new invitation's link is shown once.
 */
const { t, locale } = useI18n();
const auth = useAuthStore();
const toast = useToastStore();

const TONES = { valid: 'b-ok', used: '', expired: 'b-warn', revoked: 'b-danger' };

const invitations = ref(null);
const meta = ref(null);
const lastLink = ref(null);
const form = useForm({ email: '', note: '', days: 14 });

const zone = computed(() => auth.user?.timezone || 'UTC');
const when = (iso) => (iso ? formatDateTime(iso, locale.value, zone.value) : '—');

async function load(page = 1) {
    const { data } = await http.get('/admin/invitations', { params: { page } });
    invitations.value = data.data;
    meta.value = data.meta;
}

async function send() {
    await form
        .submit(async (data) => {
            const response = await http.post('/admin/invitations', { ...data, note: data.note || null });
            lastLink.value = response.data.meta;
            toast.success(t('admin.invitations.sent', { email: response.data.data.email }));
            form.reset();
            await load();
        })
        .catch(() => {});
}

async function revoke(invitation) {
    try {
        await http.delete(`/admin/invitations/${invitation.id}`);
        toast.success(t('admin.invitations.revoked'));
        await load(meta.value?.current_page ?? 1);
    } catch {
        toast.error(t('errors.generic'));
    }
}

onMounted(() => load().catch(() => toast.error(t('errors.generic'))));
</script>

<template>
    <div class="page-head">
        <div class="eyebrow">{{ t('admin.badge') }}</div>
        <h1>{{ t('admin.invitations.title') }}</h1>
        <div class="sub">{{ t('admin.invitations.sub') }}</div>
    </div>

    <section class="card">
        <form class="card-body" novalidate @submit.prevent="send">
            <div class="grid grid-cols-1 gap-x-3 md:grid-cols-[minmax(0,2fr)_minmax(0,2fr)_8rem]">
                <FormField v-slot="{ id, aria }" :label="t('admin.invitations.email')" :error="form.errors.value.email">
                    <input
                        :id="id"
                        v-model="form.data.email"
                        v-bind="aria"
                        class="input"
                        type="email"
                        autocomplete="off"
                    />
                </FormField>
                <FormField
                    v-slot="{ id, aria }"
                    :label="t('admin.invitations.note')"
                    :hint="t('admin.invitations.noteHint')"
                    :error="form.errors.value.note"
                >
                    <input :id="id" v-model="form.data.note" v-bind="aria" class="input" maxlength="255" />
                </FormField>
                <FormField v-slot="{ id, aria }" :label="t('admin.invitations.days')" :error="form.errors.value.days">
                    <input
                        :id="id"
                        v-model.number="form.data.days"
                        v-bind="aria"
                        class="input"
                        type="number"
                        min="1"
                        max="90"
                    />
                </FormField>
            </div>
            <button type="submit" class="btn btn-primary" :disabled="form.processing.value || !form.data.email">
                {{ t('admin.invitations.send') }}
            </button>

            <div v-if="lastLink" class="notice mt-4" :class="lastLink.sent ? 'n-neutral' : 'n-warn'">
                <div class="min-w-0">
                    <strong>{{ lastLink.sent ? t('admin.invitations.link') : t('admin.invitations.notSent') }}</strong>
                    <code class="block font-mono text-xs break-all">{{ lastLink.link }}</code>
                </div>
            </div>
        </form>

        <p v-if="invitations && invitations.length === 0" class="card-body text-ink-3">
            {{ t('admin.invitations.empty') }}
        </p>
        <div v-else-if="invitations" class="overflow-x-auto">
            <table class="data">
                <thead>
                    <tr>
                        <th scope="col">{{ t('admin.invitations.columns.email') }}</th>
                        <th scope="col">{{ t('admin.invitations.columns.status') }}</th>
                        <th scope="col" class="hidden md:table-cell">{{ t('admin.invitations.columns.sent') }}</th>
                        <th scope="col" class="hidden md:table-cell">{{ t('admin.invitations.columns.expires') }}</th>
                        <th scope="col" class="hidden lg:table-cell">{{ t('admin.invitations.columns.note') }}</th>
                        <th scope="col">
                            <span class="sr-only">{{ t('admin.invitations.revoke') }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="invitation in invitations" :key="invitation.id" class="cursor-default!">
                        <td>
                            {{ invitation.email }}
                            <RouterLink
                                v-if="invitation.user"
                                :to="{ name: 'admin.astrologer', params: { id: invitation.user.id } }"
                                class="block text-xs hover:underline"
                                >{{ invitation.user.name }}</RouterLink
                            >
                        </td>
                        <td>
                            <span class="badge" :class="TONES[invitation.status]">{{
                                t(`admin.invitations.statuses.${invitation.status}`)
                            }}</span>
                        </td>
                        <td class="hidden text-xs text-ink-3 md:table-cell">{{ when(invitation.sent_at) }}</td>
                        <td class="hidden text-xs text-ink-3 md:table-cell">{{ when(invitation.expires_at) }}</td>
                        <td class="hidden max-w-64 truncate text-xs text-ink-3 lg:table-cell">
                            {{ invitation.note ?? '' }}
                        </td>
                        <td class="text-right">
                            <button
                                v-if="invitation.status === 'valid'"
                                type="button"
                                class="btn btn-ghost btn-sm text-danger"
                                @click="revoke(invitation)"
                            >
                                {{ t('admin.invitations.revoke') }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <PaginationBar v-if="meta && meta.last_page > 1" :meta="meta" @page="load" />
    </section>
</template>
