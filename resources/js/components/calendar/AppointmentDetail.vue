<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

import AppointmentStatusBadge from '@/components/calendar/AppointmentStatusBadge.vue';
import NatalChart from '@/components/NatalChart.vue';
import PaymentForm from '@/components/PaymentForm.vue';
import { useLabels } from '@/composables/useLabels';
import { useMoney } from '@/composables/useMoney';
import { bookedInPortal, cancellation, isOpen, offersCancelEmail } from '@/lib/calendar';
import { formatDateTime } from '@/lib/datetime';
import { formatDate, initials } from '@/lib/format';
import http from '@/lib/http';
import { reminderState } from '@/lib/notifications';
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
 *
 * What the client booked in the client portal says so, with their message
 * (plain text, only the astrologer sees it). A booking request holds its time
 * until it is confirmed or declined here; a decline may carry a message that
 * goes to the client. When the client uses the portal, cancelling a time still
 * ahead can email them (docs/spec/12, Phase 9b). Each appointment starts with
 * empty forms: what was typed for one never carries over to the next.
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
const notifyClient = ref(true);
const declining = ref(false);
const declineMessage = ref('');
const declineError = ref(null);
const statusConflicts = ref([]);
const chart = ref(null);
const chartState = ref('idle');
const depositOpen = ref(false);
const reasonInput = ref(null);
const declineInput = ref(null);

async function load() {
    appointment.value = null;
    failed.value = false;
    // Nothing typed for one appointment may reach another: a decline message goes to the client.
    cancelling.value = false;
    reason.value = '';
    reasonError.value = null;
    notifyClient.value = true;
    declining.value = false;
    declineMessage.value = '';
    declineError.value = null;
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

// The appointment as the server has it now, without blanking the panel.
async function refresh() {
    const { data } = await http.get(`/appointments/${props.appointmentId}`);
    appointment.value = data.data;
}

// An answer to a change carries the appointment, not always its deposits: keep what it leaves out.
function applied(item) {
    appointment.value = { ...appointment.value, ...item };
}

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
        applied(data.data);
        toast.success(t('appointments.statusChanged'));
        emit('changed', data.data);
    } catch (error) {
        if (error.response?.status === 409) statusConflicts.value = error.response.data.conflicts ?? [];
        else toast.error(t('errors.generic'));
    } finally {
        busy.value = false;
    }
}

async function openCancel() {
    cancelling.value = !cancelling.value;
    notifyClient.value = true;
    if (cancelling.value) {
        await nextTick();
        reasonInput.value?.focus();
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
            // Only a client who uses the portal, about a time still ahead (Phase 9b): no box, no email.
            notify_client: offersCancelEmail(appointment.value) && notifyClient.value,
        });
        applied(data.data);
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

// Booking requests from the portal (Phase 9b) -----------------------------------------------

/**
 * The request was answered or changed meanwhile — in another tab, by a
 * colleague, or the client cancelled it (409 not_requested): say so and show
 * the appointment as it is now.
 */
async function answeredMeanwhile(error) {
    if (error.response?.status !== 409) {
        toast.error(t(error.response?.status === 403 ? 'errors.forbidden' : 'errors.generic'));
        return;
    }

    toast.error(error.response.data?.message ?? t('appointments.notRequested'));
    declining.value = false;
    try {
        await refresh();
        emit('changed', appointment.value);
    } catch {
        failed.value = true;
    }
}

async function confirmRequest() {
    busy.value = true;
    try {
        const { data } = await http.post(`/appointments/${props.appointmentId}/confirm`);
        applied(data.data);
        declining.value = false;
        toast.success(t('appointments.confirmed'));
        emit('changed', data.data);
    } catch (error) {
        await answeredMeanwhile(error);
    } finally {
        busy.value = false;
    }
}

async function openDecline() {
    declining.value = !declining.value;
    declineError.value = null;
    if (declining.value) {
        await nextTick();
        declineInput.value?.focus();
    }
}

async function decline() {
    declineError.value = null;
    const message = declineMessage.value.trim();

    busy.value = true;
    try {
        // The message is optional and goes to the client with the answer.
        const { data } = await http.post(
            `/appointments/${props.appointmentId}/decline`,
            message ? { reason: message } : {},
        );
        applied(data.data);
        declining.value = false;
        declineMessage.value = '';
        toast.success(t('appointments.declined'));
        emit('changed', data.data);
    } catch (error) {
        if (error.response?.status === 422) {
            declineError.value =
                error.response.data?.errors?.reason?.[0] ?? error.response.data?.message ?? t('errors.generic');
        } else {
            await answeredMeanwhile(error);
        }
    } finally {
        busy.value = false;
    }
}

// How a cancelled appointment ended: declined, cancelled by the client in the portal, or by the practice.
const ended = computed(() => {
    const result = cancellation(appointment.value);
    if (!result) return null;

    return { ...result, when: result.at ? formatDateTime(result.at, locale.value, zone.value) : '' };
});

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

// The email reminder to the astrologer (Phase 7c): planned, sent, none, switched off or too late.
const reminder = computed(() => {
    const item = appointment.value;
    const own = !item.assigned_user || item.assigned_user.id === auth.user?.id;
    const state = reminderState(item, own ? auth.user?.notification_preferences : null);
    if (!state) return null;

    const lead = item.reminder_minutes ? labels.leadTime(item.reminder_minutes) : null;
    const at = state.at ? formatDateTime(state.at, locale.value, zone.value) : null;
    const text = {
        planned: () => t('appointments.detail.reminderPlanned', { lead, at }),
        sent: () => t('appointments.detail.reminderSent', { at }),
        none: () => t('notifications.noReminder'),
        paused: () => t('appointments.detail.reminderPaused', { lead }),
        passed: () => t('appointments.detail.reminderPassed', { lead }),
    }[state.state]();

    return { text, shifted: state.shifted === true };
});

// Deposits: what was paid before the session; the service may ask for one, a request included.
const payments = computed(() => appointment.value?.payments ?? []);
const depositExpected = computed(
    () => appointment.value?.service?.requires_deposit && isOpen(appointment.value) && !payments.value.length,
);

async function depositSaved() {
    depositOpen.value = false;
    await refresh();
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

                <template v-if="bookedInPortal(appointment)">
                    <dt>{{ t('appointments.detail.source') }}</dt>
                    <dd>
                        {{ t('appointments.detail.sourcePortal') }}
                        <span v-if="appointment.created_at" class="block text-xs text-ink-3">{{
                            formatDateTime(appointment.created_at, locale, zone)
                        }}</span>
                    </dd>
                </template>

                <template v-if="reminder">
                    <dt>{{ t('appointments.detail.reminder') }}</dt>
                    <dd>
                        {{ reminder.text }}
                        <span v-if="reminder.shifted" class="block text-xs text-ink-3">{{
                            t('appointments.detail.reminderShifted')
                        }}</span>
                    </dd>
                </template>
            </dl>

            <!-- What the client wrote when booking: plain text, for the astrologer only, read before answering. -->
            <div v-if="appointment.client_note" class="field">
                <div class="label">{{ t('appointments.detail.clientNote') }}</div>
                <p class="break-words whitespace-pre-line text-ink-2">{{ appointment.client_note }}</p>
            </div>

            <!-- A booking request from the portal: it waits for an answer and holds its time meanwhile. -->
            <div v-if="appointment.status === 'requested'" class="notice n-warn">
                <div class="min-w-0">
                    <strong>{{ t('appointments.detail.requestedTitle') }}</strong>
                    {{ t('appointments.detail.requested') }}
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button type="button" class="btn btn-primary btn-sm" :disabled="busy" @click="confirmRequest">
                            {{ t('appointments.detail.confirm') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm"
                            :disabled="busy"
                            :aria-expanded="declining"
                            @click="openDecline"
                        >
                            {{ t('appointments.detail.decline') }}
                        </button>
                    </div>
                </div>
            </div>

            <form v-if="declining" novalidate @submit.prevent="decline">
                <div class="field">
                    <label class="label" for="decline-message">{{ t('appointments.detail.declineMessage') }}</label>
                    <textarea
                        id="decline-message"
                        ref="declineInput"
                        v-model="declineMessage"
                        class="input min-h-16"
                        rows="3"
                        maxlength="500"
                        :aria-invalid="declineError ? 'true' : undefined"
                        :aria-describedby="declineError ? 'decline-message-error' : 'decline-message-hint'"
                    />
                    <div v-if="declineError" id="decline-message-error" class="error" role="alert">
                        {{ declineError }}
                    </div>
                    <div v-else id="decline-message-hint" class="hint">
                        {{
                            appointment.client_portal
                                ? t('appointments.detail.declineHint')
                                : t('appointments.detail.declineHintNoPortal')
                        }}
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-danger btn-sm" :disabled="busy">
                        {{ t('appointments.detail.confirmDecline') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm" @click="declining = false">
                        {{ t('appointments.detail.keepRequest') }}
                    </button>
                </div>
            </form>

            <div v-if="overlaps.length && appointment.status !== 'cancelled'" class="notice n-warn" role="status">
                ⚠
                {{
                    t('appointments.detail.conflict', {
                        names: overlaps.map((item) => `${item.client?.full_name} (${moment(item)})`).join(', '),
                    })
                }}
            </div>

            <div v-if="ended" class="notice n-neutral">
                <div class="min-w-0">
                    <!-- Declined: the message went to the client with the answer. -->
                    <template v-if="ended.by === 'declined'">
                        {{ t('appointments.detail.declinedOn', { when: ended.when }) }}
                        <template v-if="ended.reason">
                            <strong class="mt-1.5">{{ t('appointments.detail.declineSent') }}</strong>
                            <span class="block break-words whitespace-pre-line">{{ ended.reason }}</span>
                        </template>
                    </template>
                    <!-- Cancelled by the client in the portal, with their message if they wrote one. -->
                    <template v-else-if="ended.by === 'client'">
                        {{ t('appointments.detail.cancelledByClient', { when: ended.when }) }}
                        <template v-if="ended.reason">
                            <strong class="mt-1.5">{{ t('appointments.detail.clientReason') }}</strong>
                            <span class="block break-words whitespace-pre-line">{{ ended.reason }}</span>
                        </template>
                    </template>
                    <template v-else-if="ended.reason">
                        {{ t('appointments.detail.cancelledBecause', { when: ended.when, reason: ended.reason }) }}
                    </template>
                    <template v-else>{{ t('appointments.detail.cancelledOn', { when: ended.when }) }}</template>
                </div>
            </div>

            <div v-if="appointment.notes" class="field">
                <div class="label">{{ t('appointments.detail.notes') }}</div>
                <p class="break-words whitespace-pre-line text-ink-2">{{ appointment.notes }}</p>
            </div>

            <div v-if="payments.length || depositExpected" class="field">
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
                    v-else-if="appointment.status !== 'cancelled' && appointment.status !== 'requested'"
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

            <!-- A request is answered above; it is never "scheduled again" from here, and neither
                 is a declined one: the client was told no, and a new booking is theirs to make. -->
            <div
                v-if="appointment.status !== 'requested' && !appointment.declined_at"
                class="flex flex-wrap gap-2 border-t border-line-soft pt-3"
            >
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
                        @click="openCancel"
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

            <form v-if="cancelling" novalidate @submit.prevent="cancel">
                <div class="field">
                    <label class="label" for="cancel-reason">{{ t('appointments.detail.cancelReason') }}</label>
                    <textarea
                        id="cancel-reason"
                        ref="reasonInput"
                        v-model="reason"
                        class="input min-h-16"
                        rows="2"
                        maxlength="500"
                        :aria-invalid="reasonError ? 'true' : undefined"
                        :aria-describedby="reasonError ? 'cancel-reason-error' : 'cancel-reason-hint'"
                    />
                    <div v-if="reasonError" id="cancel-reason-error" class="error" role="alert">
                        {{ reasonError }}
                    </div>
                    <div v-else id="cancel-reason-hint" class="hint">{{ t('appointments.detail.cancelHint') }}</div>
                </div>
                <!-- The client uses the portal and the time is still ahead: they can hear of it (never of the reason). -->
                <label v-if="offersCancelEmail(appointment)" class="mb-3 flex items-start gap-2 text-sm">
                    <input v-model="notifyClient" type="checkbox" class="mt-1" aria-describedby="cancel-notify-hint" />
                    <span>
                        <span class="block">{{ t('appointments.detail.notifyCancel') }}</span>
                        <span id="cancel-notify-hint" class="block text-xs text-ink-3">{{
                            t('appointments.detail.notifyCancelHint')
                        }}</span>
                    </span>
                </label>
                <div class="flex flex-wrap gap-2">
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
