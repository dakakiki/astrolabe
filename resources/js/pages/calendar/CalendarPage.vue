<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';

import AppointmentDetail from '@/components/calendar/AppointmentDetail.vue';
import AppointmentForm from '@/components/calendar/AppointmentForm.vue';
import CalendarAgenda from '@/components/calendar/CalendarAgenda.vue';
import CalendarGrid from '@/components/calendar/CalendarGrid.vue';
import CalendarMonth from '@/components/calendar/CalendarMonth.vue';
import { useLabels } from '@/composables/useLabels';
import { conflictingIds, daysBetween, shiftDate, todayIn, VIEWS, viewRange, wallClock } from '@/lib/calendar';
import http from '@/lib/http';
import { useAuthStore } from '@/stores/auth';

/**
 * The astrologer's calendar (docs/spec/10): day, week, month and agenda over
 * the same appointments, in the astrologer's own zone. View, date, filters and
 * the open appointment live in the URL, so a timeline entry can link straight
 * to an appointment and the back button works. The agenda is the default on a
 * phone.
 */
const { t, locale } = useI18n();
const labels = useLabels();
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const zone = computed(() => auth.user?.timezone ?? 'UTC');
const today = ref(todayIn(zone.value));
const nowMinutes = ref(wallClock(new Date().toISOString(), zone.value).minutes);

const narrow = window.matchMedia?.('(max-width: 640px)').matches ?? false;
const view = computed(() => (VIEWS.includes(route.query.view) ? route.query.view : narrow ? 'agenda' : 'week'));
const date = computed(() => (/^\d{4}-\d{2}-\d{2}$/.test(route.query.date ?? '') ? route.query.date : today.value));
const filters = computed(() => ({
    service_id: route.query.service_id ?? '',
    status: route.query.status ?? '',
    location_type: route.query.location_type ?? '',
}));
const range = computed(() => viewRange(view.value, date.value));
const days = computed(() => daysBetween(range.value.from, range.value.to));

const appointments = ref([]);
const loading = ref(false);
const services = ref([]);

async function load() {
    loading.value = true;
    const params = {
        ...range.value,
        ...Object.fromEntries(Object.entries(filters.value).filter(([, value]) => value)),
    };

    try {
        const { data } = await http.get('/appointments', { params });
        appointments.value = data.data;
    } finally {
        loading.value = false;
    }
}

const conflicts = computed(() => conflictingIds(appointments.value));

function go(changes) {
    router.replace({ query: { ...route.query, ...changes } });
}

// Everything that changes the period or the filters reloads the list.
watch(
    () => [
        range.value.from,
        range.value.to,
        filters.value.service_id,
        filters.value.status,
        filters.value.location_type,
    ],
    load,
);

// The side panel: one appointment's details, or the form.
const selectedId = computed(() => (route.query.appointment ? Number(route.query.appointment) : null));
const formFor = ref(null); // null, 'new' or the appointment being edited
const preset = ref({});
const panel = ref(null);

async function showPanel() {
    await nextTick();
    if (window.matchMedia?.('(max-width: 1023px)').matches) panel.value?.scrollIntoView({ behavior: 'smooth' });
}

function select(appointment) {
    formFor.value = null;
    go({ appointment: appointment.id });
    showPanel();
}

function closePanel() {
    formFor.value = null;
    go({ appointment: undefined, new: undefined, client: undefined });
}

function closeForm() {
    formFor.value = null;
    go({ new: undefined, client: undefined });
}

function create(startsAt = null, client = null) {
    preset.value = { starts_at: startsAt ?? `${date.value}T10:00`, client };
    formFor.value = 'new';
    go({ appointment: undefined });
    showPanel();
}

function edit(appointment) {
    formFor.value = appointment;
    showPanel();
}

async function saved(appointment) {
    formFor.value = null;
    const start = wallClock(appointment.starts_at, zone.value).date;
    go({
        appointment: appointment.id,
        new: undefined,
        client: undefined,
        date: start < range.value.from || start > range.value.to ? start : route.query.date,
    });
    await load();
}

async function changed() {
    await load();
}

// Opened from a link (timeline, client profile) without a date: go to the appointment's own day.
function arrived(appointment) {
    const start = wallClock(appointment.starts_at, zone.value).date;
    if (!route.query.date && (start < range.value.from || start > range.value.to)) go({ date: start });
}

const overlaps = computed(() => {
    const current = appointments.value.find((item) => item.id === selectedId.value);
    if (!current || !conflicts.value.has(current.id)) return [];

    return appointments.value.filter(
        (item) =>
            item.id !== current.id &&
            item.status !== 'cancelled' &&
            (item.assigned_user?.id ?? null) === (current.assigned_user?.id ?? null) &&
            item.starts_at < current.ends_at &&
            current.starts_at < item.ends_at,
    );
});

const title = computed(() => {
    const format = (options) => new Intl.DateTimeFormat(locale.value, { ...options, timeZone: 'UTC' });
    const from = new Date(`${range.value.from}T00:00:00Z`);
    const to = new Date(`${range.value.to}T00:00:00Z`);

    if (view.value === 'day') return format({ dateStyle: 'full' }).format(from);
    if (view.value === 'month')
        return format({ month: 'long', year: 'numeric' }).format(new Date(`${date.value}T00:00:00Z`));

    return format({ day: 'numeric', month: 'short', year: 'numeric' }).formatRange(from, to);
});

// The red "now" line and "today" follow the clock.
let clock;
onMounted(async () => {
    clock = setInterval(() => {
        today.value = todayIn(zone.value);
        nowMinutes.value = wallClock(new Date().toISOString(), zone.value).minutes;
    }, 60_000);

    const [serviceResponse] = await Promise.all([http.get('/services'), load()]);
    services.value = serviceResponse.data.data;

    // "New appointment" from a client profile.
    if (route.query.new) {
        let client = null;
        if (route.query.client) {
            const { data } = await http.get(`/clients/${route.query.client}`);
            client = { id: data.data.id, full_name: data.data.full_name };
        }
        create(null, client);
    }
});
onBeforeUnmount(() => clearInterval(clock));
</script>

<template>
    <div class="page-head flex flex-wrap items-end gap-4">
        <div class="min-w-0">
            <div class="eyebrow">{{ title }}</div>
            <h1>{{ t('calendar.title') }}</h1>
            <div class="sub">
                {{ t('calendar.zoneNote', { zone }) }}
            </div>
        </div>
        <button type="button" class="btn btn-primary ml-auto" @click="create()">+ {{ t('calendar.new') }}</button>
    </div>

    <div class="mb-3 flex flex-wrap items-center gap-2">
        <div class="seg" role="group" :aria-label="t('calendar.view')">
            <button
                v-for="option in VIEWS"
                :key="option"
                type="button"
                :aria-pressed="view === option"
                @click="go({ view: option })"
            >
                {{ t(`calendar.views.${option}`) }}
            </button>
        </div>
        <button type="button" class="btn btn-sm" @click="go({ date: undefined })">{{ t('calendar.today') }}</button>
        <button
            type="button"
            class="btn btn-sm"
            :aria-label="t('calendar.previous')"
            @click="go({ date: shiftDate(view, date, -1) })"
        >
            ‹
        </button>
        <button
            type="button"
            class="btn btn-sm"
            :aria-label="t('calendar.next')"
            @click="go({ date: shiftDate(view, date, 1) })"
        >
            ›
        </button>
        <input
            class="input w-auto"
            type="date"
            :value="date"
            :aria-label="t('calendar.date')"
            @change="$event.target.value && go({ date: $event.target.value })"
        />
        <select
            v-if="services.length"
            class="input w-auto"
            :aria-label="t('calendar.filters.service')"
            :value="filters.service_id"
            @change="go({ service_id: $event.target.value || undefined })"
        >
            <option value="">{{ t('calendar.filters.anyService') }}</option>
            <option v-for="service in services" :key="service.id" :value="String(service.id)">
                {{ service.name }}
            </option>
        </select>
        <select
            class="input w-auto"
            :aria-label="t('calendar.filters.status')"
            :value="filters.status"
            @change="go({ status: $event.target.value || undefined })"
        >
            <option value="">{{ t('calendar.filters.anyStatus') }}</option>
            <option v-for="status in ['scheduled', 'completed', 'cancelled', 'no_show']" :key="status" :value="status">
                {{ t(`appointmentStatuses.${status}`) }}
            </option>
        </select>
        <select
            class="input w-auto"
            :aria-label="t('calendar.filters.place')"
            :value="filters.location_type"
            @change="go({ location_type: $event.target.value || undefined })"
        >
            <option value="">{{ t('calendar.filters.anyPlace') }}</option>
            <option v-for="type in ['online', 'in_person']" :key="type" :value="type">
                {{ labels.locationType(type) }}
            </option>
        </select>
        <span v-if="loading" class="text-xs text-ink-3" role="status">{{ t('calendar.loading') }}</span>
    </div>

    <div class="grid gap-4" :class="{ 'lg:grid-cols-[minmax(0,1fr)_380px]': formFor || selectedId }">
        <div ref="panel" class="order-first min-w-0 lg:order-last" :class="{ hidden: !formFor && !selectedId }">
            <AppointmentForm
                v-if="formFor"
                :key="formFor === 'new' ? `new-${preset.starts_at}` : formFor.id"
                :appointment="formFor === 'new' ? null : formFor"
                :preset="preset"
                :services="services"
                @saved="saved"
                @cancel="closeForm"
            />
            <AppointmentDetail
                v-else-if="selectedId"
                :appointment-id="selectedId"
                :overlaps="overlaps"
                @edit="edit"
                @changed="changed"
                @loaded="arrived"
                @close="closePanel"
            />
        </div>

        <div class="min-w-0" :aria-busy="loading">
            <CalendarGrid
                v-if="view === 'day' || view === 'week'"
                :days="days"
                :appointments="appointments"
                :time-zone="zone"
                :today="today"
                :now-minutes="nowMinutes"
                :conflicts="conflicts"
                :selected-id="selectedId"
                @select="select"
                @create="create"
            />
            <CalendarMonth
                v-else-if="view === 'month'"
                :days="days"
                :month="date"
                :appointments="appointments"
                :time-zone="zone"
                :today="today"
                :conflicts="conflicts"
                :selected-id="selectedId"
                @select="select"
                @open-day="go({ view: 'day', date: $event })"
            />
            <CalendarAgenda
                v-else
                :appointments="appointments"
                :time-zone="zone"
                :today="today"
                :conflicts="conflicts"
                :selected-id="selectedId"
                @select="select"
            />
        </div>
    </div>
</template>
