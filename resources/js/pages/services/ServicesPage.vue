<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRouter } from 'vue-router';

import AppIcon from '@/components/AppIcon.vue';
import { useLabels } from '@/composables/useLabels';
import http from '@/lib/http';
import { formatMoney } from '@/lib/money';
import { serviceColorClass } from '@/lib/services';
import { useAuthStore } from '@/stores/auth';
import { useReferenceStore } from '@/stores/reference';

/**
 * The practice's services (docs/spec/02, "Usluge"): what is offered, how long it takes, what it
 * costs and where it is held. Everyone sees them; the owner adds and edits them on their own
 * pages (ServiceFormPage.vue). A service in use is deactivated, not deleted, so past
 * consultations keep it. Clients book only the active services marked "Bookable in the client
 * portal" (Phase 9b, docs/spec/12), and only while portal booking is on in Settings → Booking.
 */
const { t, locale } = useI18n();
const labels = useLabels();
const router = useRouter();
const auth = useAuthStore();
const reference = useReferenceStore();

const services = ref(null);

const decimals = (currency) => reference.data?.currency_decimals?.[currency] ?? 2;
const price = (service) => formatMoney(service.price, locale.value, decimals(service.price?.currency));

async function load() {
    const { data } = await http.get('/services');
    services.value = data.data;
}

onMounted(() => Promise.all([reference.load(), load()]));

function openService(service) {
    if (auth.isOwner) router.push({ name: 'services.edit', params: { id: service.id } });
}
</script>

<template>
    <div class="page-head flex flex-wrap items-end gap-4">
        <div>
            <div class="eyebrow">{{ t('services.eyebrow') }}</div>
            <h1>{{ t('services.title') }}</h1>
            <div class="sub">{{ t('services.sub') }}</div>
        </div>
        <RouterLink
            v-if="auth.isOwner"
            :to="{ name: 'services.create' }"
            class="btn btn-primary ml-auto"
            v-tip="t('tips.services.add')"
            ><AppIcon name="add" />{{ t('services.add') }}</RouterLink
        >
    </div>

    <div v-if="!auth.isOwner" class="notice n-neutral mb-4">{{ t('services.ownerOnly') }}</div>

    <section class="card">
        <div class="overflow-x-auto">
            <table v-if="services?.length" class="data">
                <thead>
                    <tr>
                        <th>
                            <span class="sr-only">{{ t('services.form.color') }}</span>
                        </th>
                        <th>{{ t('services.columns.name') }}</th>
                        <th class="text-right!">{{ t('services.columns.duration') }}</th>
                        <th class="text-right!">{{ t('services.columns.price') }}</th>
                        <th>{{ t('services.columns.held') }}</th>
                        <th>{{ t('services.columns.deposit') }}</th>
                        <th>{{ t('services.columns.methods') }}</th>
                        <th>{{ t('services.columns.status') }}</th>
                        <th v-if="auth.isOwner">
                            <span class="sr-only">{{ t('services.edit') }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="service in services"
                        :key="service.id"
                        :class="{ 'cursor-default!': !auth.isOwner, 'opacity-70': !service.is_active }"
                        @click="openService(service)"
                    >
                        <td class="w-6">
                            <span
                                class="inline-block size-2.5 rounded-sm"
                                :class="serviceColorClass(service.color)"
                                :title="labels.serviceColor(service.color)"
                            />
                        </td>
                        <td class="max-w-80">
                            <div class="font-medium text-ink">{{ service.name }}</div>
                            <div v-if="service.description" class="truncate text-xs text-ink-3">
                                {{ service.description }}
                            </div>
                        </td>
                        <td class="text-right whitespace-nowrap">
                            {{ t('services.minutes', { n: service.duration_minutes }) }}
                        </td>
                        <td class="text-right font-mono whitespace-nowrap">
                            <template v-if="service.price">{{ price(service) }}</template>
                            <span v-else class="text-ink-4">—</span>
                        </td>
                        <td class="text-xs whitespace-nowrap text-ink-2">
                            {{ labels.locationType(service.location_type) }}
                        </td>
                        <td>
                            <span v-if="service.requires_deposit">{{ t('services.yes') }}</span>
                            <span v-else class="text-ink-4">{{ t('services.no') }}</span>
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-1">
                                <span v-for="method in service.methods" :key="method.id" class="method-pill">{{
                                    labels.method(method)
                                }}</span>
                                <span v-if="!service.methods?.length" class="text-ink-4">{{
                                    t('services.anyMethod')
                                }}</span>
                            </div>
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-1">
                                <span v-if="service.is_active" class="badge b-ok">{{ t('services.active') }}</span>
                                <span v-else class="badge">{{ t('services.inactive') }}</span>
                                <span
                                    v-if="service.bookable_in_portal"
                                    class="badge b-info"
                                    :title="t('services.form.bookable')"
                                >
                                    {{ t('services.columns.portal') }}
                                    <span class="sr-only">— {{ t('services.form.bookable') }}</span>
                                </span>
                            </div>
                        </td>
                        <td v-if="auth.isOwner" class="text-right">
                            <RouterLink
                                :to="{ name: 'services.edit', params: { id: service.id } }"
                                class="btn btn-sm"
                                v-tip="t('tips.services.edit')"
                                @click.stop
                                ><AppIcon name="edit" />{{ t('services.edit') }}</RouterLink
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else-if="services" class="empty">{{ t('services.empty') }}</p>
            <p v-else class="p-8 text-center text-ink-3">{{ t('common.loading') }}</p>
        </div>
    </section>
</template>
