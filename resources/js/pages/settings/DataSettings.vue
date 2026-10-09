<script setup>
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRouter } from 'vue-router';

import FormField from '@/components/FormField.vue';
import PracticeExportCard from '@/components/PracticeExportCard.vue';
import { useForm } from '@/composables/useForm';
import { formatDateTime } from '@/lib/datetime';
import http from '@/lib/http';
import { LEGAL_DOCUMENTS } from '@/lib/legal';
import { useAuthStore } from '@/stores/auth';
import { useReferenceStore } from '@/stores/reference';

/**
 * Settings → Your data (Phase 8b; docs/spec/06, "Pravna priprema"): the
 * practice export, how long data is kept, and deleting the practice with the
 * accounts that belong only to it. Export and deletion are the owner's.
 * Also which versions of the Terms, the DPA and the privacy policy the person
 * accepted (Phase 8c); this screen stays open while new terms wait.
 */
const { t, locale } = useI18n();
const auth = useAuthStore();
const reference = useReferenceStore();
const router = useRouter();

const retention = computed(() => reference.data?.retention ?? null);
const graceDays = computed(() => retention.value?.practice_deletion_days ?? 30);

const deletion = useForm({ password: '' });

const agreements = computed(() =>
    LEGAL_DOCUMENTS.map((slug) => {
        const accepted = auth.user?.legal?.accepted?.[slug] ?? null;

        return {
            slug,
            accepted,
            on: accepted
                ? formatDateTime(accepted.accepted_at, locale.value, auth.user?.timezone || 'UTC', {
                      dateStyle: 'medium',
                  })
                : null,
            current: accepted?.version === auth.user?.legal?.current?.[slug],
        };
    }),
);

async function scheduleDeletion() {
    if (!window.confirm(t('settings.data.deletion.confirm', { days: graceDays.value }))) {
        return;
    }

    await deletion
        .submit(async (data) => {
            const response = await http.post('/workspace/deletion', data);
            auth.workspace = response.data.data;
            deletion.reset();
            router.push({ name: 'practice-deletion' });
        })
        .catch(() => {});
}

onMounted(() => reference.load().catch(() => {}));
</script>

<template>
    <div v-if="!auth.isOwner" class="notice n-neutral">{{ t('settings.ownerOnly') }}</div>

    <PracticeExportCard v-if="auth.isOwner" />

    <section class="card">
        <div class="card-head">
            <h2>{{ t('settings.data.retention.title') }}</h2>
        </div>
        <ul v-if="retention" class="card-body list-disc space-y-1.5 pl-9">
            <li>{{ t('settings.data.retention.deleted', { days: retention.deleted_days }) }}</li>
            <li>{{ t('settings.data.retention.clients') }}</li>
            <li>{{ t('settings.data.retention.audit', { months: retention.audit_log_months }) }}</li>
            <li>{{ t('settings.data.retention.exports', { days: retention.export_keep_days }) }}</li>
            <li>{{ t('settings.data.retention.backups', { days: retention.backup_days }) }}</li>
        </ul>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>{{ t('settings.data.legal.title') }}</h2>
        </div>
        <ul class="card-body space-y-2">
            <li
                v-for="agreement in agreements"
                :key="agreement.slug"
                class="flex flex-wrap items-baseline gap-x-3 gap-y-1"
            >
                <RouterLink :to="{ name: 'legal.show', params: { document: agreement.slug } }" class="font-medium">{{
                    t(`legal.documents.${agreement.slug}`)
                }}</RouterLink>
                <span v-if="agreement.accepted" class="text-sm text-ink-3">
                    {{
                        t(agreement.slug === 'privacy' ? 'settings.data.legal.seen' : 'settings.data.legal.accepted', {
                            date: agreement.on,
                        })
                    }}
                    <RouterLink
                        v-if="!agreement.current"
                        :to="{
                            name: 'legal.show',
                            params: { document: agreement.slug },
                            query: { version: agreement.accepted.version },
                        }"
                        >{{ t('settings.data.legal.thatVersion') }}</RouterLink
                    >
                </span>
                <span v-else class="text-sm text-ink-3">{{
                    t(agreement.slug === 'privacy' ? 'settings.data.legal.notSeen' : 'settings.data.legal.notYet')
                }}</span>
            </li>
        </ul>
    </section>

    <section v-if="auth.isOwner" class="card">
        <div class="card-head">
            <h2 class="text-danger">{{ t('settings.data.deletion.title') }}</h2>
        </div>
        <form class="card-body" novalidate @submit.prevent="scheduleDeletion">
            <p class="mb-2">{{ t('settings.data.deletion.intro') }}</p>
            <p class="mb-2">{{ t('settings.data.deletion.grace', { days: graceDays }) }}</p>
            <p class="mb-4 text-ink-3">{{ t('settings.data.deletion.exportFirst') }}</p>
            <div class="flex flex-wrap items-end gap-2">
                <div class="min-w-56 flex-1">
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('settings.data.deletion.password')"
                        :error="deletion.errors.value.password"
                    >
                        <input
                            :id="id"
                            v-model="deletion.data.password"
                            v-bind="aria"
                            class="input"
                            type="password"
                            autocomplete="current-password"
                        />
                    </FormField>
                </div>
                <button
                    class="btn btn-danger mb-3.5"
                    type="submit"
                    :disabled="deletion.processing.value || !deletion.data.password"
                >
                    {{ t('settings.data.deletion.submit') }}
                </button>
            </div>
        </form>
    </section>
</template>
