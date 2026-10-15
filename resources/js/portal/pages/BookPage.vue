<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import FormField from '@/components/FormField.vue';
import AppointmentItem from '@/portal/components/AppointmentItem.vue';
import ServiceChoice from '@/portal/components/ServiceChoice.vue';
import SlotPicker from '@/portal/components/SlotPicker.vue';
import http, { validationErrors } from '@/portal/http';
import {
    bookingPayload,
    bookingProblem,
    changeRules,
    dayLabel,
    dayOf,
    findSlot,
    idempotencyKey,
    priceText,
    readBookingQuery,
    slotRange,
} from '@/portal/lib/booking';
import { displayZone, zoneLabel } from '@/portal/lib/portal';
import { loadScreen } from '@/portal/load';
import { useSessionStore } from '@/portal/stores/session';
import { useToastStore } from '@/stores/toast';

/**
 * Portal → Book an appointment (docs/spec/12, "Zakazivanje (9b)"): choose a
 * service, then a free time on the client's clock, then confirm with an
 * optional message for the practice. Each step is in the address
 * (`?service=…&at=…`), so the phone's back button steps back. The server
 * checks the time again when booking; if it was just taken, the fresh list
 * comes back with the answer and the client picks again. Prices are shown
 * for information — nothing is paid here.
 */
const { t, locale } = useI18n();
const route = useRoute();
const router = useRouter();
const session = useSessionStore();
const toast = useToastStore();

const STEPS = ['service', 'time', 'confirm'];

const zone = computed(() => displayZone(session.user, session.practice));
const booking = ref(null);
const slots = ref(null);
const slotsFor = ref(null);
const slotsFailed = ref(false);
const selected = ref(null);
const location = ref(null);
const message = ref('');
const errors = ref({});
const notice = ref(null);
const busy = ref(false);
const booked = ref(null);
// One key per confirmation screen: pressing "Book" again after a lost answer books once.
let requestKey = null;

const query = computed(() => readBookingQuery(route.query));
const services = computed(() => booking.value?.services ?? []);
const open = computed(() => Boolean(booking.value?.enabled) && services.value.length > 0);
const service = computed(() =>
    open.value ? (services.value.find((item) => item.id === query.value.serviceId) ?? null) : null,
);
const listed = computed(() => (service.value && slotsFor.value === service.value.id ? slots.value : null));
const slot = computed(() => (listed.value ? findSlot(listed.value, query.value.at) : null));
const chosen = computed(() => (listed.value ? findSlot(listed.value, selected.value) : null));
const step = computed(() => {
    if (booked.value) return 'done';
    if (!service.value) return 'service';

    return slot.value ? 'confirm' : 'time';
});
const pickedWhen = computed(() => (chosen.value ? when(chosen.value) : null));
const slotWhen = computed(() => (slot.value ? when(slot.value) : null));
const rules = computed(() =>
    booking.value ? changeRules(booking.value.reschedule_until_hours, booking.value.cancel_until_hours) : [],
);

onMounted(() => loadScreen(loadBooking));

async function loadBooking() {
    const response = await http.get('/booking');
    booking.value = response.data.data;
}

// A service chosen (or the page opened on one): its free times.
watch(
    () => service.value?.id ?? null,
    (id) => {
        slots.value = null;
        slotsFor.value = null;
        location.value = null;
        selected.value = query.value.at;
        if (id !== null) loadSlots(id);
    },
);

// Back on the second step, the time chosen before stays chosen.
watch(
    () => query.value.at,
    (at) => {
        if (at) selected.value = at;
    },
    { immediate: true },
);

// Stepping back from the success screen starts a new booking.
watch(
    () => query.value.serviceId,
    (id) => {
        if (booked.value && id !== null) booked.value = null;
    },
);

// A fresh confirmation screen: a new key, no old errors.
watch(
    () => (step.value === 'confirm' ? slot.value.starts_at : null),
    (at) => {
        if (!at) return;
        requestKey = idempotencyKey('booking');
        errors.value = {};
    },
    { immediate: true },
);

// An address naming a service that cannot be booked (any more) starts from the list.
watch(booking, () => {
    if (booking.value && !booked.value && query.value.serviceId !== null && !service.value) {
        router.replace({ name: 'book' });
    }
});

async function loadSlots(id) {
    slotsFailed.value = false;

    try {
        const response = await http.get('/booking/slots', { params: { service_id: id } });
        if (service.value?.id !== id) return;

        slots.value = response.data.data.slots;
        slotsFor.value = id;
    } catch (error) {
        const problem = bookingProblem(error);
        if (['generic', 'too_many'].includes(problem.code)) slotsFailed.value = true;
        handle(problem);
    }
}

function choose(item) {
    notice.value = null;
    router.push({ name: 'book', query: { service: item.id } });
}

function next() {
    notice.value = null;
    router.push({ name: 'book', query: { service: service.value.id, at: chosen.value.starts_at } });
}

/** Back to an earlier step: through the history when it came from there, otherwise in place. */
function stepBack(target) {
    const to = router.resolve({ name: 'book', query: target });

    if (window.history.state?.back === to.fullPath) {
        router.back();
    } else {
        router.replace(to);
    }
}

function place(value) {
    location.value = value;
    errors.value = { ...errors.value, location_type: undefined };
}

async function submit() {
    if (service.value.location_type === 'either' && !location.value) {
        errors.value = { location_type: t('portal.booking.whereRequired') };

        return;
    }

    busy.value = true;
    errors.value = {};
    notice.value = null;

    try {
        const response = await http.post(
            '/appointments',
            bookingPayload({
                service: service.value,
                startsAt: slot.value.starts_at,
                location: location.value,
                message: message.value,
            }),
            { headers: { 'Idempotency-Key': requestKey } },
        );
        booked.value = response.data.data;
        message.value = '';
        router.replace({ name: 'book' });
    } catch (error) {
        const problem = bookingProblem(error);

        if (problem.code === 'invalid' && !problem.fields) {
            // This key booked once already — its answer was lost — and the details changed since.
            notice.value = { tone: 'n-warn', text: t('portal.booking.maybeBooked'), appointments: true };
        } else if (problem.code === 'invalid') {
            errors.value = validationErrors(error) ?? {};
            // Anything not under a field of this form (the time, the service, the request itself) is said on top.
            const other = Object.entries(errors.value).find(([field]) => !['message', 'location_type'].includes(field));
            if (other) notice.value = { tone: 'n-warn', text: other[1] };
        } else {
            handle(problem);
        }
    } finally {
        busy.value = false;
    }
}

/** What an answer from the server means here. */
function handle(problem) {
    switch (problem.code) {
        case 'slot_taken':
            notice.value = { tone: 'n-warn', text: t('portal.booking.slotTaken') };
            // The taken time stays as the hint for the day to show: that day, or the next with times.
            if (problem.slots) {
                slots.value = problem.slots;
            } else if (service.value) {
                loadSlots(service.value.id);
            }
            if (service.value) stepBack({ service: service.value.id });
            break;
        case 'booking_disabled':
            booking.value = { ...booking.value, enabled: false, services: [] };
            break;
        case 'not_found':
            notice.value = { tone: 'n-warn', text: t('portal.booking.serviceGone') };
            stepBack({});
            loadScreen(loadBooking);
            break;
        case 'too_many':
            notice.value = { tone: 'n-warn', text: t('portal.booking.tooMany') };
            break;
        case 'too_many_open':
            // The portal books only so many upcoming appointments for one person; the practice can add more.
            notice.value = { tone: 'n-info', text: t('portal.booking.tooManyOpen'), appointments: true };
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

function rule(line) {
    return line.count === undefined
        ? t(`portal.booking.rules.${line.key}`)
        : t(`portal.booking.rules.${line.key}`, { count: line.count }, line.count);
}
</script>

<template>
    <div class="page-head">
        <div class="eyebrow">{{ session.practice?.name }}</div>
        <h1>{{ t('portal.booking.title') }}</h1>
        <div v-if="open && step !== 'done'" class="sub">{{ t(`portal.booking.sub.${step}`) }}</div>
    </div>

    <p v-if="!booking" class="text-ink-3">{{ t('common.loading') }}</p>

    <section v-else-if="!open && !booked" class="card card-body">
        <p class="text-sm text-ink-2">
            {{ booking.enabled ? t('portal.booking.noServices') : t('portal.booking.disabled') }}
        </p>
        <RouterLink :to="{ name: 'home' }" class="btn btn-sm mt-4">{{ t('portal.booking.backHome') }}</RouterLink>
    </section>

    <template v-else>
        <ol v-if="step !== 'done'" class="book-steps mb-4" :aria-label="t('portal.booking.steps.label')">
            <li
                v-for="(name, index) in STEPS"
                :key="name"
                :class="{ 'is-done': STEPS.indexOf(step) > index }"
                :aria-current="step === name ? 'step' : undefined"
            >
                <span class="n" aria-hidden="true">{{ index + 1 }}</span>
                {{ t(`portal.booking.steps.${name}`) }}
            </li>
        </ol>

        <div v-if="notice" class="notice mb-4 flex-wrap items-center" :class="notice.tone" role="alert">
            <span class="min-w-0 flex-1">{{ notice.text }}</span>
            <RouterLink v-if="notice.appointments" :to="{ name: 'appointments' }" class="btn btn-sm">{{
                t('portal.booking.seeAppointments')
            }}</RouterLink>
        </div>

        <!-- Step 1: the service. -->
        <template v-if="step === 'service'">
            <ServiceChoice :services="services" @choose="choose" />
            <p class="mt-3 text-xs text-ink-3">{{ t('portal.booking.priceNote') }}</p>
            <div v-if="booking.client_note" class="notice n-neutral mt-4">
                <div class="min-w-0">
                    <strong>{{ t('portal.booking.fromPractice') }}</strong>
                    <p class="break-words whitespace-pre-line">{{ booking.client_note }}</p>
                </div>
            </div>
        </template>

        <!-- Step 2: the time. -->
        <section v-else-if="step === 'time'" class="card">
            <div class="card-head">
                <h2 class="min-w-0 truncate">{{ service.name }}</h2>
                <button type="button" class="right link" @click="stepBack({})">
                    {{ t('portal.booking.changeService') }}
                </button>
            </div>
            <div class="card-body">
                <div v-if="listed === null && slotsFailed" class="flex flex-wrap items-center gap-3">
                    <span class="text-sm text-ink-3">{{ t('portal.errors.generic') }}</span>
                    <button type="button" class="btn btn-sm" @click="loadSlots(service.id)">
                        {{ t('portal.booking.retry') }}
                    </button>
                </div>
                <p v-else-if="listed === null" class="text-ink-3">{{ t('common.loading') }}</p>
                <SlotPicker
                    v-else
                    v-model="selected"
                    :slots="listed"
                    :time-zone="zone"
                    :days="booking.max_days_ahead"
                />
            </div>
            <div
                v-if="listed?.length"
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

        <!-- Step 3: check and confirm. -->
        <section v-else-if="step === 'confirm'" class="card">
            <div class="card-head">
                <h2>{{ t('portal.booking.summary.title') }}</h2>
                <button type="button" class="right link" @click="stepBack({ service: service.id })">
                    {{ t('portal.booking.changeTime') }}
                </button>
            </div>
            <form class="card-body space-y-4" novalidate @submit.prevent="submit">
                <dl class="book-summary">
                    <dt>{{ t('portal.booking.summary.service') }}</dt>
                    <dd>{{ service.name }}</dd>
                    <dt>{{ t('portal.booking.summary.day') }}</dt>
                    <dd>{{ slotWhen.day }}</dd>
                    <dt>{{ t('portal.booking.summary.time') }}</dt>
                    <dd>
                        {{ slotWhen.time }} <span class="text-xs text-ink-3">· {{ slotWhen.zone }}</span>
                    </dd>
                    <template v-if="service.location_type !== 'either'">
                        <dt>{{ t('portal.booking.summary.where') }}</dt>
                        <dd>{{ t(`portal.appointments.location.${service.location_type}`) }}</dd>
                    </template>
                    <template v-if="service.price">
                        <dt>{{ t('portal.booking.summary.price') }}</dt>
                        <dd>{{ t('portal.booking.price', { price: priceText(service.price, locale) }) }}</dd>
                    </template>
                </dl>

                <div v-if="service.location_type === 'either'" class="field">
                    <span id="book-where" class="label">{{ t('portal.booking.where') }}</span>
                    <div class="seg" role="group" aria-labelledby="book-where">
                        <button
                            v-for="value in ['online', 'in_person']"
                            :key="value"
                            type="button"
                            :aria-pressed="location === value ? 'true' : 'false'"
                            @click="place(value)"
                        >
                            {{ t(`portal.appointments.location.${value}`) }}
                        </button>
                    </div>
                    <div v-if="errors.location_type" class="error" role="alert">{{ errors.location_type }}</div>
                </div>

                <FormField
                    v-slot="{ id, aria }"
                    :label="t('portal.booking.message')"
                    :hint="t('portal.booking.messageHint')"
                    :error="errors.message"
                >
                    <textarea :id="id" v-bind="aria" v-model="message" class="input block" rows="3" maxlength="1000" />
                </FormField>

                <div v-if="booking.client_note" class="notice n-neutral">
                    <div class="min-w-0">
                        <strong>{{ t('portal.booking.fromPractice') }}</strong>
                        <p class="break-words whitespace-pre-line">{{ booking.client_note }}</p>
                    </div>
                </div>

                <ul class="space-y-1 text-xs text-ink-3">
                    <li v-for="line in rules" :key="line.key">{{ rule(line) }}</li>
                    <li v-if="booking.requires_confirmation">{{ t('portal.booking.requestHint') }}</li>
                </ul>

                <button type="submit" class="btn btn-primary" :disabled="busy">
                    {{ booking.requires_confirmation ? t('portal.booking.request') : t('portal.booking.book') }}
                </button>
            </form>
        </section>

        <!-- Done. -->
        <section v-else class="card">
            <div class="card-body">
                <div class="notice n-ok mb-4" role="status">
                    <strong>{{
                        booked.status === 'requested' ? t('portal.booking.requested') : t('portal.booking.booked')
                    }}</strong>
                </div>
                <AppointmentItem :appointment="booked" :time-zone="zone" />
                <RouterLink :to="{ name: 'appointments' }" class="btn btn-primary mt-4">{{
                    t('portal.booking.seeAppointments')
                }}</RouterLink>
            </div>
        </section>
    </template>
</template>
