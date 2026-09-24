<script setup>
import { useI18n } from 'vue-i18n';
import { RouterLink, useRouter } from 'vue-router';

import ConsultationStatusBadge from '@/components/ConsultationStatusBadge.vue';
import { useLabels } from '@/composables/useLabels';
import { formatDateTime } from '@/lib/datetime';
import { initials } from '@/lib/format';
import { serviceColorClass } from '@/lib/services';
import { useAuthStore } from '@/stores/auth';

defineProps({
    consultations: { type: Array, required: true },
    showClient: { type: Boolean, default: true },
    busy: { type: Boolean, default: false },
});

const { t, locale } = useI18n();
const labels = useLabels();
const router = useRouter();
const auth = useAuthStore();

const open = (consultation) => router.push({ name: 'consultations.show', params: { id: consultation.id } });
const date = (consultation) =>
    formatDateTime(consultation.starts_at, locale.value, auth.user?.timezone, { dateStyle: 'medium' });
const time = (consultation) =>
    formatDateTime(consultation.starts_at, locale.value, auth.user?.timezone, { timeStyle: 'short' });
</script>

<template>
    <table class="data" :aria-busy="busy">
        <thead>
            <tr>
                <th>{{ t('consultations.columns.date') }}</th>
                <th v-if="showClient">{{ t('consultations.columns.client') }}</th>
                <th>{{ t('consultations.columns.consultation') }}</th>
                <th>{{ t('consultations.columns.status') }}</th>
                <th>{{ t('consultations.columns.methods') }}</th>
                <th>{{ t('consultations.columns.chart') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="consultation in consultations" :key="consultation.id" @click="open(consultation)">
                <td class="whitespace-nowrap">
                    <template v-if="consultation.starts_at">
                        <div class="font-mono text-xs">{{ date(consultation) }}</div>
                        <div class="text-xs text-ink-3">{{ time(consultation) }}</div>
                    </template>
                    <span v-else class="text-ink-4">—</span>
                </td>
                <td v-if="showClient">
                    <div v-if="consultation.client" class="person">
                        <span class="ini" aria-hidden="true">{{ initials(consultation.client.full_name) }}</span>
                        <RouterLink
                            :to="{ name: 'clients.show', params: { id: consultation.client.id } }"
                            class="nm hover:underline"
                            @click.stop
                            >{{ consultation.client.full_name }}</RouterLink
                        >
                    </div>
                </td>
                <td class="max-w-80">
                    <span
                        v-if="consultation.service"
                        class="mr-1.5 inline-block size-2 rounded-sm"
                        :class="serviceColorClass(consultation.service.color)"
                        :title="consultation.service.name"
                    />
                    <RouterLink
                        :to="{ name: 'consultations.show', params: { id: consultation.id } }"
                        class="font-medium text-ink hover:underline"
                        @click.stop
                        >{{
                            consultation.title || consultation.service?.name || t('consultations.untitled')
                        }}</RouterLink
                    >
                    <div v-if="consultation.title && consultation.service" class="text-xs text-ink-3">
                        {{ consultation.service.name }}
                    </div>
                    <div v-if="consultation.topics" class="truncate text-xs text-ink-3">{{ consultation.topics }}</div>
                    <div v-if="consultation.duration_minutes" class="text-xs text-ink-4">
                        {{ t('consultations.minutes', { n: consultation.duration_minutes }) }}
                    </div>
                </td>
                <td><ConsultationStatusBadge :status="consultation.status" /></td>
                <td>
                    <div class="flex flex-wrap gap-1">
                        <span v-for="method in consultation.methods" :key="method.id" class="method-pill">{{
                            labels.method(method)
                        }}</span>
                    </div>
                </td>
                <td>
                    <span v-if="consultation.has_chart" class="badge b-plain b-info">{{
                        t('consultations.snapshot')
                    }}</span>
                    <span v-else class="text-ink-4">—</span>
                </td>
            </tr>
        </tbody>
    </table>
</template>
