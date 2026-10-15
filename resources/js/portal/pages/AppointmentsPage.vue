<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import AppointmentActions from '@/portal/components/AppointmentActions.vue';
import AppointmentItem from '@/portal/components/AppointmentItem.vue';
import http from '@/portal/http';
import { displayZone, zoneLabel } from '@/portal/lib/portal';
import { loadScreen } from '@/portal/load';
import { useSessionStore } from '@/portal/stores/session';

/**
 * Portal → Appointments (docs/spec/12): upcoming (soonest first) and past —
 * held, cancelled, missed — on the client's clock. Since 9b: "Book an
 * appointment" while the practice takes bookings here, requests waiting for
 * confirmation, and rescheduling or cancelling until the practice's deadline.
 */
const { t, locale } = useI18n();
const route = useRoute();
const router = useRouter();
const session = useSessionStore();

const when = computed(() => (route.query.when === 'past' ? 'past' : 'upcoming'));
const zone = computed(() => displayZone(session.user, session.practice));
const items = ref(null);
const page = ref(1);
const lastPage = ref(1);
const canBook = ref(false);

/** The first page again (`reset`), or the next one appended; an answer for another tab is dropped. */
async function load(reset = true) {
    const tab = when.value;
    const next = reset ? 1 : page.value + 1;

    const response = await http.get('/appointments', { params: { when: tab, page: next } });
    if (tab !== when.value) return;

    items.value = reset ? response.data.data : [...items.value, ...response.data.data];
    page.value = next;
    lastPage.value = response.data.meta.last_page;
}

function more() {
    loadScreen(() => load(false));
}

watch(
    when,
    () => {
        items.value = null;
        loadScreen(() => load());
    },
    { immediate: true },
);

// Only a hint for the button: without an answer it simply does not show.
onMounted(async () => {
    try {
        const response = await http.get('/booking');
        canBook.value = Boolean(response.data.data.enabled) && response.data.data.services.length > 0;
    } catch {
        canBook.value = false;
    }
});
</script>

<template>
    <div class="page-head flex flex-wrap items-end justify-between gap-x-4 gap-y-3">
        <div class="min-w-0">
            <div class="eyebrow">{{ session.practice?.name }}</div>
            <h1>{{ t('portal.nav.appointments') }}</h1>
            <div class="sub">{{ t('portal.appointments.zone', { zone: zoneLabel(zone, null, locale) }) }}</div>
        </div>
        <RouterLink v-if="canBook" :to="{ name: 'book' }" class="btn btn-primary">{{
            t('portal.appointments.book')
        }}</RouterLink>
    </div>

    <nav class="tabs mb-4" :aria-label="t('portal.nav.appointments')">
        <button
            v-for="name in ['upcoming', 'past']"
            :key="name"
            type="button"
            :aria-current="when === name ? 'page' : undefined"
            @click="router.replace({ query: name === 'upcoming' ? {} : { when: name } })"
        >
            {{ t(`portal.appointments.${name}`) }}
        </button>
    </nav>

    <p v-if="items === null" class="text-ink-3">{{ t('common.loading') }}</p>
    <div v-else-if="items.length === 0" class="card card-body text-sm text-ink-3">
        {{ t(`portal.appointments.empty.${when}`) }}
    </div>
    <div v-else class="card portal-list">
        <div v-for="appointment in items" :key="appointment.id" class="portal-item">
            <AppointmentItem :appointment="appointment" :time-zone="zone" />
            <AppointmentActions
                v-if="appointment.upcoming"
                :appointment="appointment"
                :time-zone="zone"
                @changed="loadScreen(() => load())"
            />
        </div>
    </div>
    <button v-if="items && page < lastPage" type="button" class="btn mt-4" @click="more">{{ t('common.more') }}</button>
</template>
