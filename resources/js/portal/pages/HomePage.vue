<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

import AppointmentItem from '@/portal/components/AppointmentItem.vue';
import http from '@/portal/http';
import { displayZone, suggestedZone } from '@/portal/lib/portal';
import { loadScreen } from '@/portal/load';
import { useSessionStore } from '@/portal/stores/session';
import { useToastStore } from '@/stores/toast';

/**
 * Portal → Home (docs/spec/12): the next appointment, how much was shared
 * since the last visit, and — until the client picks a zone — an offer to
 * show times on their device's clock.
 */
const { t } = useI18n();
const session = useSessionStore();
const toast = useToastStore();

const home = ref(null);
const zone = computed(() => displayZone(session.user, session.practice));
const deviceZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const offerZone = computed(() => suggestedZone(session.user, session.practice, deviceZone));

onMounted(() =>
    loadScreen(async () => {
        const response = await http.get('/home');
        home.value = response.data.data;
    }),
);

async function useDeviceZone() {
    const response = await http.put('/profile', { timezone: deviceZone });
    session.setUser({ timezone: response.data.data.timezone });
    toast.success(t('portal.home.zoneSaved'));
}
</script>

<template>
    <div class="page-head">
        <div class="eyebrow">{{ session.practice?.name }}</div>
        <h1>
            {{ session.user?.name ? t('portal.home.helloName', { name: session.user.name }) : t('portal.home.hello') }}
        </h1>
    </div>

    <div v-if="offerZone" class="notice n-info mb-4 flex flex-wrap items-center gap-3">
        <span class="min-w-0 flex-1">{{
            t('portal.home.zoneOffer', { practice: session.practice.timezone, device: offerZone })
        }}</span>
        <button type="button" class="btn btn-sm" @click="useDeviceZone">{{ t('portal.home.zoneUse') }}</button>
    </div>

    <p v-if="!home" class="text-ink-3">{{ t('common.loading') }}</p>

    <div v-else class="grid grid-cols-1 gap-4 md:grid-cols-[minmax(0,1fr)_260px]">
        <section class="card">
            <div class="card-head">
                <h2>{{ t('portal.home.next') }}</h2>
                <RouterLink :to="{ name: 'appointments' }" class="right">{{
                    t('portal.home.allAppointments')
                }}</RouterLink>
            </div>
            <div class="card-body">
                <AppointmentItem v-if="home.next_appointment" :appointment="home.next_appointment" :time-zone="zone" />
                <p v-else class="text-sm text-ink-3">{{ t('portal.home.noNext') }}</p>
                <p v-if="home.upcoming_count > 1" class="mt-3 text-xs text-ink-3">
                    {{ t('portal.home.moreUpcoming', { count: home.upcoming_count - 1 }, home.upcoming_count - 1) }}
                </p>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <h2>{{ t('portal.home.shared') }}</h2>
            </div>
            <div class="card-body">
                <p class="font-serif text-3xl">{{ home.new_shared }}</p>
                <p class="text-sm text-ink-3">
                    {{ t('portal.home.newShared', { count: home.new_shared }, home.new_shared) }}
                </p>
                <RouterLink :to="{ name: 'shared' }" class="btn btn-sm mt-3">{{
                    t('portal.home.openShared')
                }}</RouterLink>
            </div>
        </section>
    </div>
</template>
