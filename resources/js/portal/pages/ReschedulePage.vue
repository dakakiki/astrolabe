<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import AppointmentItem from '@/portal/components/AppointmentItem.vue';
import SlotPicker from '@/portal/components/SlotPicker.vue';
import http, { validationErrors } from '@/portal/http';
import {
    bookingProblem,
    dayLabel,
    dayOf,
    daysAhead,
    findSlot,
    idempotencyKey,
    readMoment,
    sameMoment,
    slotRange,
    withoutMoment,
} from '@/portal/lib/booking';
import { displayZone, zoneLabel } from '@/portal/lib/portal';
import { loadScreen } from '@/portal/load';
import { useSessionStore } from '@/portal/stores/session';
import { useToastStore } from '@/stores/toast';

/**
 * Portal → Reschedule (docs/spec/12, "Zakazivanje (9b)"): a new time for an
 * upcoming appointment, from the same free times as a new booking (its own
 * time left out), until the practice's deadline. Service, length, place and
 * status stay as they are. The chosen time is in the address (`?at=…`), so
 * the phone's back button steps back; a time taken meanwhile brings the
 * fresh list back with the answer.
 */
const { t, locale } = useI18n();
const route = useRoute();
const router = useRouter();
const session = useSessionStore();
const toast = useToastStore();

const id = Number(route.params.id);
const zone = computed(() => displayZone(session.user, session.practice));
// Drawn at once from the list it was opened from, then read again from the upcoming list. The
// free times answer whether it can be moved at all (404 not the client's, 409 too late).
const appointment = ref(window.history.state?.appointment?.id === id ? window.history.state.appointment : null);
const slots = ref(null);
const slotsFailed = ref(false);
const reach = ref(1);
const selected = ref(null);
const problem = ref(null);
const notice = ref(null);
const busy = ref(false);
const moved = ref(null);
// The answer kept the time the appointment already had: nothing was moved.
const unchanged = ref(false);
// One key per confirmation screen: pressing the button again after a lost answer moves it once.
let requestKey = null;

const at = computed(() => readMoment(route.query.at));
// The appointment's own time would change nothing: the server leaves it out, and so does this list.
const offered = computed(() => withoutMoment(slots.value, appointment.value?.starts_at ?? null));
const slot = computed(() => (offered.value ? findSlot(offered.value, at.value) : null));
const chosen = computed(() => (offered.value ? findSlot(offered.value, selected.value) : null));
const step = computed(() => {
    if (moved.value) return 'done';

    return slot.value ? 'confirm' : 'time';
});
const pickedWhen = computed(() => (chosen.value ? when(chosen.value) : null));
const nowWhen = computed(() => (appointment.value ? when(appointment.value) : null));
const slotWhen = computed(() => (slot.value ? when(slot.value) : null));

onMounted(() => {
    loadScreen(loadAppointment);
    loadSlots();
});

watch(
    at,
    (value) => {
        if (value) selected.value = value;
    },
    { immediate: true },
);

// A fresh confirmation screen: a new key.
watch(
    () => (step.value === 'confirm' ? slot.value.starts_at : null),
    (value) => {
        if (value) requestKey = idempotencyKey('reschedule');
    },
    { immediate: true },
);

async function loadAppointment() {
    const response = await http.get('/appointments', { params: { when: 'upcoming' } });
    appointment.value = response.data.data.find((item) => item.id === id) ?? appointment.value;
}

async function loadSlots() {
    slotsFailed.value = false;

    try {
        const response = await http.get('/booking/slots', { params: { appointment_id: id } });
        slots.value = response.data.data.slots;
        reach.value = daysAhead(response.data.data.to);
    } catch (error) {
        const answer = bookingProblem(error);
        if (['generic', 'too_many'].includes(answer.code)) slotsFailed.value = true;
        handle(answer);
    }
}

function next() {
    notice.value = null;
    router.push({ name: 'reschedule', params: { id }, query: { at: chosen.value.starts_at } });
}

/** Back to the times: through the history when it came from there, otherwise in place. */
function stepBack() {
    const to = router.resolve({ name: 'reschedule', params: { id } });

    if (window.history.state?.back === to.fullPath) {
        router.back();
    } else {
        router.replace(to);
    }
}

async function submit() {
    busy.value = true;
    notice.value = null;
    const before = appointment.value?.starts_at ?? null;

    try {
        const response = await http.post(
            `/appointments/${id}/reschedule`,
            { starts_at: slot.value.starts_at },
            { headers: { 'Idempotency-Key': requestKey } },
        );
        moved.value = response.data.data;
        unchanged.value = sameMoment(moved.value.starts_at, before);
        router.replace({ name: 'reschedule', params: { id } });
    } catch (error) {
        const answer = bookingProblem(error);

        if (answer.code === 'invalid' && !answer.fields) {
            // A refusal naming no field: this key already went through once, and its answer was lost.
            notice.value = { tone: 'n-warn', text: t('portal.appointments.maybeMoved'), appointments: true };
        } else if (answer.code === 'invalid') {
            const messages = Object.values(validationErrors(error) ?? {});
            notice.value = { tone: 'n-warn', text: messages[0] ?? t('portal.errors.generic') };
        } else {
            handle(answer);
        }
    } finally {
        busy.value = false;
    }
}

/** What an answer from the server means here. */
function handle(answer) {
    switch (answer.code) {
        case 'slot_taken':
            notice.value = { tone: 'n-warn', text: t('portal.booking.slotTaken') };
            // The taken time stays as the hint for the day to show: that day, or the next with times.
            if (answer.slots) {
                slots.value = answer.slots;
            } else {
                loadSlots();
            }
            stepBack();
            break;
        case 'change_too_late':
        case 'not_changeable':
        case 'booking_disabled':
        case 'not_found':
            problem.value = answer.code;
            break;
        case 'too_many':
            notice.value = { tone: 'n-warn', text: t('portal.booking.tooMany') };
            break;
        case 'handled':
            break;
        default:
            toast.error(t('portal.errors.generic'));
    }
}

function when(item) {
    return {
        day: dayLabel(dayOf(item.starts_at, zone.value), locale.value).full,
        time: slotRange(item, locale.value, zone.value),
        zone: zoneLabel(zone.value, item.starts_at, locale.value),
    };
}

const problems = {
    change_too_late: 'portal.appointments.tooLate',
    not_changeable: 'portal.appointments.notChangeable',
    booking_disabled: 'portal.appointments.closed',
    not_found: 'portal.appointments.missing',
};
</script>

<template>
    <div class="page-head">
        <div class="eyebrow">{{ session.practice?.name }}</div>
        <h1>{{ t('portal.appointments.rescheduleTitle') }}</h1>
        <div v-if="!problem && step !== 'done'" class="sub">
            {{ t('portal.appointments.rescheduleSub') }}
        </div>
    </div>

    <section v-if="problem" class="card card-body">
        <div class="notice n-warn" role="alert">{{ t(problems[problem]) }}</div>
        <RouterLink :to="{ name: 'appointments' }" class="btn btn-sm mt-4">{{
            t('portal.appointments.back')
        }}</RouterLink>
    </section>

    <template v-else>
        <div v-if="notice" class="notice mb-4 flex-wrap items-center" :class="notice.tone" role="alert">
            <span class="min-w-0 flex-1">{{ notice.text }}</span>
            <RouterLink v-if="notice.appointments" :to="{ name: 'appointments' }" class="btn btn-sm">{{
                t('portal.booking.seeAppointments')
            }}</RouterLink>
        </div>

        <!-- The appointment as it is now. -->
        <section v-if="appointment && step === 'time'" class="card mb-4">
            <div class="card-head">
                <h2>{{ t('portal.appointments.now') }}</h2>
            </div>
            <div class="card-body">
                <AppointmentItem :appointment="appointment" :time-zone="zone" />
            </div>
        </section>

        <!-- The new time. -->
        <section v-if="step === 'time'" class="card">
            <div class="card-head">
                <h2>{{ t('portal.appointments.newTime') }}</h2>
                <RouterLink :to="{ name: 'appointments' }" class="right">{{
                    t('portal.appointments.keepTime')
                }}</RouterLink>
            </div>
            <div class="card-body">
                <div v-if="slots === null && slotsFailed" class="flex flex-wrap items-center gap-3">
                    <span class="text-sm text-ink-3">{{ t('portal.errors.generic') }}</span>
                    <button type="button" class="btn btn-sm" @click="loadSlots">{{ t('portal.booking.retry') }}</button>
                </div>
                <p v-else-if="slots === null" class="text-ink-3">{{ t('common.loading') }}</p>
                <SlotPicker v-else v-model="selected" :slots="offered" :time-zone="zone" :days="reach" />
            </div>
            <div
                v-if="offered?.length"
                class="card-body book-next flex flex-wrap items-center gap-3 border-t border-line-soft"
            >
                <button type="button" class="btn btn-primary" :disabled="!chosen" @click="next">
                    {{ t('portal.booking.continue') }}
                </button>
                <span v-if="pickedWhen" class="text-sm text-ink-2"
                    >{{ pickedWhen.day }} · {{ pickedWhen.time }}
                    <span class="text-xs text-ink-3">· {{ pickedWhen.zone }}</span></span
                >
            </div>
        </section>

        <!-- Check and move. -->
        <section v-else-if="step === 'confirm'" class="card">
            <div class="card-head">
                <h2>{{ t('portal.booking.summary.title') }}</h2>
                <button type="button" class="right link" @click="stepBack">
                    {{ t('portal.booking.changeTime') }}
                </button>
            </div>
            <form class="card-body space-y-4" novalidate @submit.prevent="submit">
                <dl class="book-summary">
                    <template v-if="nowWhen">
                        <dt>{{ t('portal.appointments.from') }}</dt>
                        <dd>
                            {{ nowWhen.day }}<br />
                            {{ nowWhen.time }} <span class="text-xs text-ink-3">· {{ nowWhen.zone }}</span>
                        </dd>
                    </template>
                    <dt>{{ t('portal.appointments.to') }}</dt>
                    <dd class="font-medium">
                        {{ slotWhen.day }}<br />
                        {{ slotWhen.time }} <span class="text-xs font-normal text-ink-3">· {{ slotWhen.zone }}</span>
                    </dd>
                </dl>
                <button type="submit" class="btn btn-primary" :disabled="busy">
                    {{ t('portal.appointments.move') }}
                </button>
            </form>
        </section>

        <!-- Done. -->
        <section v-else class="card">
            <div class="card-body">
                <div v-if="unchanged" class="notice n-info mb-4" role="status">
                    <strong>{{ t('portal.appointments.unchanged') }}</strong>
                </div>
                <div v-else class="notice n-ok mb-4" role="status">
                    <div>
                        <strong>{{ t('portal.appointments.moved') }}</strong>
                        <span v-if="moved.status === 'requested'">{{ t('portal.appointments.movedRequested') }}</span>
                    </div>
                </div>
                <AppointmentItem :appointment="moved" :time-zone="zone" />
                <RouterLink :to="{ name: 'appointments' }" class="btn btn-primary mt-4">{{
                    t('portal.booking.seeAppointments')
                }}</RouterLink>
            </div>
        </section>
    </template>
</template>
