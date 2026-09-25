<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

import AppointmentStatusBadge from '@/components/calendar/AppointmentStatusBadge.vue';
import NatalChart from '@/components/NatalChart.vue';
import PaymentForm from '@/components/PaymentForm.vue';
import { useLabels } from '@/composables/useLabels';
import { useMoney } from '@/composables/useMoney';
import { formatDateTime } from '@/lib/datetime';
import { formatDate, initials } from '@/lib/format';
import http from '@/lib/http';
import { transitsRoute } from '@/lib/transits';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

/**
 * One appointment beside the calendar (docs/spec/10): when, where and with
 * whom, in the astrologer's zone and the zone it was entered in; its status;
 * and what to do next — record the consultation, open the client, look at
 * the client's chart without leaving the calendar, or open the transits for
 * the appointment's time (Phase 7a). Money paid for it before the session — a
 * deposit — is recorded here and moves to the consultation later (Phase 7b).
 */
const props = defineProps({
    appointmentId: { type: Number, required: true },
    /** Other appointments of the same astrologer at the same time. */
    overlaps: { type: Array, default: () => [] },
});
const emit = defineEmits(['edit', 'changed', 'close', 'loaded']);

const { t, locale } = useI18n();
const labels = useLabels();
const money = useMoney();
const auth = useAuthStore();
const toast = useToastStore();

const appointment = ref(null);
const failed = ref(false);
const busy = ref(false);
const cancelling = ref(false);
const reason = ref('');
const reasonError = ref(null);
const statusConflicts = ref([]);
const chart = ref(null);
const chartState = ref('idle');
const depositOpen = ref(false);

async function load() {
    appointment.value = null;
    failed.value = false;
    cancelling.value = false;
    statusConflicts.value = [];
    chart.value = null;
    chartState.value = 'idle';
    depositOpen.value = false;

    try {
        const { data } = await http.get(`/appointments/${props.appointmentId}`);
        appointment.value = data.data;
        emit('loaded', data.data);
    } catch {
        failed.value = true;
    }
}

watch(() => props.appointmentId, load, { immediate: true });

const zone = computed(() => auth.user?.timezone ?? 'UTC');
const when = computed(() => {
    const item = appointment.value;
    const date = formatDateTime(item.starts_at, locale.value, zone.value, { dateStyle: 'full' });
    const from = formatDateTime(item.starts_at, locale.value, zone.value, { timeStyle: 'short' });
    const to = formatDateTime(item.ends_at, locale.value, zone.value, { timeStyle: 'short' });

    return `${date}, ${from}–${to}`;
});
const entered = computed(() => {
    const item = appointment.value;
    if (item.timezone === zone.value) return null;

    return t('appointments.detail.entered', {
        zone: item.timezone,
        time: formatDateTime(item.starts_at, locale.value, item.timezone, { timeStyle: 'short' }),
    });
});
const link = computed(() => {
    const details = appointment.value?.location_details ?? '';
    return /^https?:\/\//i.test(details) ? details : null;
});

async function setStatus(status, allowOverlap = false) {
    busy.value = true;
    statusConflicts.value = [];

    try {
        const { data } = await http.patch(`/appointments/${props.appointmentId}`, {
            status,
            allow_overlap: allowOverlap || undefined,
        });
        appointment.value = data.data;
        toast.success(t('appointments.statusChanged'));
        emit('changed', data.data);
    } catch (error) {
        if (error.response?.status === 409) statusConflicts.value = error.response.data.conflicts ?? [];
        else toast.error(t('errors.generic'));
    } finally {
        busy.value = false;
    }
}

async function cancel() {
    reasonError.value = null;
    if (!reason.value.trim()) {
        reasonError.value = t('appointments.detail.cancelReason');
        return;
    }

    busy.value = true;
    try {
        const { data } = await http.post(`/appointments/${props.appointmentId}/cancel`, {
            reason: reason.value.trim(),
        });
        appointment.value = data.data;
        cancelling.value = false;
        reason.value = '';
        toast.success(t('appointments.cancelled'));
        emit('changed', data.data);
    } catch (error) {
        reasonError.value =
            error.response?.data?.errors?.reason?.[0] ?? error.response?.data?.message ?? t('errors.generic');
    } finally {
        busy.value = false;
    }
}

async function toggleChart() {
    if (chartState.value !== 'idle') {
        chartState.value = 'idle';
        chart.value = null;
        return;
    }

    chartState.value = 'loading';
    try {
        const { data } = await http.get(`/clients/${appointment.value.client.id}/chart`);
        chart.value = data.data.status === 'ready' ? data.data : null;
        chartState.value = 'done';
    } catch {
        chartState.value = 'failed';
    }
}

const moment = (item) => formatDateTime(item.starts_at, locale.value, zone.value);

// Deposits: what was paid before the session; the service may ask for one.
const payments = computed(() => appointment.value?.payments ?? []);
const depositExpected = computed(
    () =>
        appointment.value?.service?.requires_deposit &&
        appointment.value.status === 'scheduled' &&
        !payments.value.length,
);

async function depositSaved() {
    depositOpen.value = false;
    const { data } = await http.get(`/appointments/${props.appointmentId}`);
    appointment.value = data.data;
}
</script>

<template>
    <section class="card">
        <div class="card-head">
            <h2 class="truncate">{{ appointment?.service?.name ?? t('appointments.detail.title') }}</h2>
            <button
                type="button"
                class="btn btn-ghost btn-sm right"
                :aria-label="t('appointments.detail.close')"
                @click="emit('close')"
            >
                ✕
            </button>
        </div>

        <div v-if="appointment" class="card-body space-y-4">
            <div class="person">
                <span class="ini" aria-hidden="true">{{ initials(appointment.client?.full_name) }}</span>
                <div class="min-w-0">
                    <RouterLink
                        :to="{ name: 'clients.show', params: { id: appointment.client.id } }"
                        class="font-medium hover:underline"
                        >{{ appointment.client.full_name }}</RouterLink
                    >
                    <div class="mt-0.5"><AppointmentStatusBadge :status="appointment.status" /></div>
                </div>
            </div>

            <dl class="facts">
                <dt>{{ t('appointments.detail.when') }}</dt>
                <dd>
                    {{ when }}
                    <span class="block text-xs text-ink-3">{{
                        t('calendar.minutes', { n: appointment.duration_minutes })
                    }}</span>
                    <span v-if="entered" class="block text-xs text-ink-3">{{ entered }}</span>
                </dd>

                <dt>{{ t('appointments.detail.where') }}</dt>
                <dd>
                    {{ labels.locationType(appointment.location_type) }}
                    <template v-if="appointment.location_details">
                        ·
                        <a v-if="link" :href="link" target="_blank" rel="noopener noreferrer" class="break-all">{{
                            appointment.location_details
                        }}</a>
                        <span v-else>{{ appointment.location_details }}</span>
                    </template>
                </dd>

                <template v-if="appointment.assigned_user && appointment.assigned_user.id !== auth.user?.id">
                    <dt>{{ t('appointments.detail.with') }}</dt>
                    <dd>{{ appointment.assigned_user.name }}</dd>
                </template>
            </dl>

            <div v-if="overlaps.length && appointment.status !== 'cancelled'" class="notice n-warn" role="status">
                ⚠
                {{
                    t('appointments.detail.conflict', {
                        names: overlaps.map((item) => `${item.client?.full_name} (${moment(item)})`).join(', '),
                    })
                }}
            </div>

            <div v-if="appointment.status === 'cancelled'" class="notice n-neutral">
                {{
                    t('appointments.detail.cancelledBecause', {
                        when: formatDateTime(appointment.cancelled_at, locale, zone),
                        reason: appointment.cancellation_reason,
                    })
                }}
            </div>

            <div v-if="appointment.notes">
                <div class="label">{{ t('appointments.detail.notes') }}</div>
                <p class="whitespace-pre-line text-ink-2">{{ appointment.notes }}</p>
            </div>

            <div v-if="payments.length || depositExpected">
                <div class="label">{{ t('appointments.detail.deposits') }}</div>
                <ul v-if="payments.length" class="text-sm">
                    <li v-for="payment in payments" :key="payment.id" class="flex flex-wrap gap-x-2">
                        <span class="font-mono text-xs text-ink-3">{{
                            formatDate(payment.paid_on, locale, 'medium')
                        }}</span>
                        <span class="font-mono">{{
                            money.format({
                                amount: payment.kind === 'refund' ? -payment.amount : payment.amount,
                                currency: payment.currency,
                            })
                        }}</span>
                        <span v-if="payment.method" class="text-xs text-ink-3">{{
                            t(`payments.methods.${payment.method}`)
                        }}</span>
                    </li>
                </ul>
                <p v-if="depositExpected" class="notice n-info mt-1">{{ t('appointments.detail.depositExpected') }}</p>
            </div>

            <div v-if="statusConflicts.length" class="notice n-warn" role="alert">
                <div>
                    <strong>{{ t('appointments.form.overlapTitle') }}</strong>
                    <ul class="mt-1 list-disc pl-5">
                        <li v-for="conflict in statusConflicts" :key="conflict.id">
                            {{ moment(conflict) }} · {{ conflict.client?.full_name }}
                        </li>
                    </ul>
                    <button
                        type="button"
                        class="btn btn-sm mt-2"
                        :disabled="busy"
                        @click="setStatus('scheduled', true)"
                    >
                        {{ t('appointments.form.saveAnyway') }}
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <RouterLink
                    v-if="appointment.consultation"
                    :to="{ name: 'consultations.show', params: { id: appointment.consultation.id } }"
                    class="btn btn-primary btn-sm"
                    >{{ t('appointments.detail.openConsultation') }}</RouterLink
                >
                <RouterLink
                    v-else-if="appointment.status !== 'cancelled'"
                    :to="{ name: 'consultations.create', query: { appointment: appointment.id } }"
                    class="btn btn-primary btn-sm"
                    >{{ t('appointments.detail.recordConsultation') }}</RouterLink
                >
                <button type="button" class="btn btn-sm" @click="emit('edit', appointment)">
                    {{ t('appointments.detail.edit') }}
                </button>
                <button type="button" class="btn btn-sm" :aria-expanded="chartState !== 'idle'" @click="toggleChart">
                    {{
                        chartState === 'idle' ? t('appointments.detail.showChart') : t('appointments.detail.hideChart')
                    }}
                </button>
                <button
                    v-if="appointment.status !== 'cancelled' && !depositOpen"
                    type="button"
                    class="btn btn-sm"
                    @click="depositOpen = true"
                >
                    {{ t('appointments.detail.recordDeposit') }}
                </button>
                <RouterLink
                    v-if="appointment.client.chart_ready"
                    :to="transitsRoute(appointment.client.id, appointment.starts_at, zone)"
                    class="btn btn-sm"
                    >{{ t('transits.forDate') }}</RouterLink
                >
            </div>

            <div class="flex flex-wrap gap-2 border-t border-line-soft pt-3">
                <template v-if="appointment.status === 'scheduled'">
                    <button type="button" class="btn btn-ghost btn-sm" :disabled="busy" @click="setStatus('completed')">
                        {{ t('appointments.detail.markHeld') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm" :disabled="busy" @click="setStatus('no_show')">
                        {{ t('appointments.detail.markNoShow') }}
                    </button>
                    <button
                        type="button"
                        class="btn btn-ghost btn-sm text-danger"
                        :aria-expanded="cancelling"
                        @click="cancelling = !cancelling"
                    >
                        {{ t('appointments.detail.cancel') }}
                    </button>
                </template>
                <button
                    v-else
                    type="button"
                    class="btn btn-ghost btn-sm"
                    :disabled="busy"
                    @click="setStatus('scheduled')"
                >
                    {{ t('appointments.detail.reinstate') }}
                </button>
            </div>

            <form v-if="cancelling" class="space-y-2" novalidate @submit.prevent="cancel">
                <label class="label" for="cancel-reason">{{ t('appointments.detail.cancelReason') }}</label>
                <textarea
                    id="cancel-reason"
                    v-model="reason"
                    class="input min-h-16"
                    rows="2"
                    maxlength="500"
                    :aria-invalid="reasonError ? 'true' : undefined"
                    aria-describedby="cancel-reason-hint"
                />
                <div v-if="reasonError" class="error">{{ reasonError }}</div>
                <div v-else id="cancel-reason-hint" class="hint">{{ t('appointments.detail.cancelHint') }}</div>
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-danger btn-sm" :disabled="busy">
                        {{ t('appointments.detail.confirmCancel') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm" @click="cancelling = false">
                        {{ t('appointments.detail.keep') }}
                    </button>
                </div>
            </form>

            <div v-if="depositOpen" class="border-t border-line-soft pt-3">
                <h3 class="mb-2 text-sm font-semibold">{{ t('payments.form.depositTitle') }}</h3>
                <PaymentForm
                    :appointment="{ id: appointment.id, starts_at: appointment.starts_at }"
                    :client="appointment.client"
                    compact
                    closable
                    @saved="depositSaved"
                    @cancel="depositOpen = false"
                />
            </div>

            <template v-if="chartState !== 'idle'">
                <p v-if="chartState === 'loading'" class="text-ink-3" role="status">{{ t('chart.loading') }}</p>
                <div v-else-if="chartState === 'failed'" class="notice n-warn" role="alert">
                    {{ t('chart.unavailable') }}
                </div>
                <div v-else-if="!chart" class="notice n-neutral">{{ t('appointments.detail.noChart') }}</div>
                <NatalChart v-else :chart="chart" :name="appointment.client.full_name" compact />
            </template>
        </div>

        <p v-else-if="failed" class="card-body text-ink-3">{{ t('notFound.title') }}</p>
        <p v-else class="card-body text-ink-3">{{ t('common.loading') }}</p>
    </section>
</template>
