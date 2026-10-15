<script setup>
import { useI18n } from 'vue-i18n';

import { priceText } from '@/portal/lib/booking';

/**
 * The services a client can book in the portal (docs/spec/12, "Zakazivanje
 * (9b)"), one card each: name, how long, online or in person, the price for
 * information only — nothing is paid here — and whether the practice asks for
 * a deposit. The description is the practice's plain text.
 */
defineProps({
    services: { type: Array, required: true },
});

const emit = defineEmits(['choose']);

const { t, locale } = useI18n();
</script>

<template>
    <ul class="space-y-3" :aria-label="t('portal.booking.steps.service')">
        <li v-for="service in services" :key="service.id">
            <button type="button" class="svc-card" @click="emit('choose', service)">
                <span class="svc-top">
                    <span class="svc-name">{{ service.name }}</span>
                    <span class="svc-go" aria-hidden="true">→</span>
                </span>
                <span class="svc-meta">
                    {{ t('portal.appointments.minutes', { count: service.duration_minutes }) }} ·
                    {{ t(`portal.appointments.location.${service.location_type}`) }}
                </span>
                <span v-if="service.price" class="svc-price">{{
                    t('portal.booking.price', { price: priceText(service.price, locale) })
                }}</span>
                <span v-if="service.description" class="svc-desc">{{ service.description }}</span>
                <span v-if="service.requires_deposit" class="svc-deposit">{{ t('portal.booking.deposit') }}</span>
            </button>
        </li>
    </ul>
</template>
