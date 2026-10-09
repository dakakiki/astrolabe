<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';

import AppointmentItem from '@/portal/components/AppointmentItem.vue';
import http from '@/portal/http';
import { displayZone, zoneLabel } from '@/portal/lib/portal';
import { loadScreen } from '@/portal/load';
import { useSessionStore } from '@/portal/stores/session';

/**
 * Portal → Appointments (docs/spec/12): upcoming (soonest first) and past —
 * held, cancelled, missed — on the client's clock. Viewing only in 9a.
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

async function load(reset = true) {
    if (reset) {
        items.value = null;
        page.value = 1;
    }

    const response = await http.get('/appointments', { params: { when: when.value, page: page.value } });
    items.value = reset ? response.data.data : [...items.value, ...response.data.data];
    lastPage.value = response.data.meta.last_page;
}

function more() {
    page.value += 1;
    load(false);
}

watch(when, () => loadScreen(() => load()), { immediate: true });
</script>

<template>
    <div class="page-head">
        <div class="eyebrow">{{ session.practice?.name }}</div>
        <h1>{{ t('portal.nav.appointments') }}</h1>
        <div class="sub">{{ t('portal.appointments.zone', { zone: zoneLabel(zone, null, locale) }) }}</div>
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
        </div>
    </div>
    <button v-if="items && page < lastPage" type="button" class="btn mt-4" @click="more">{{ t('common.more') }}</button>
</template>
