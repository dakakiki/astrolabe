<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute } from 'vue-router';

import StatusBadge from '@/components/StatusBadge.vue';
import { useLabels } from '@/composables/useLabels';
import { formatCoordinate, formatDate, formatRelative, initials } from '@/lib/format';
import http from '@/lib/http';
import { useToastStore } from '@/stores/toast';

const { t, locale } = useI18n();
const labels = useLabels();
const route = useRoute();
const toast = useToastStore();

const client = ref(null);
const notFound = ref(false);

onMounted(async () => {
    try {
        const { data } = await http.get(`/clients/${route.params.id}`);
        client.value = data.data;
    } catch (error) {
        if (error.response?.status === 404) notFound.value = true;
        else throw error;
    }
});

const birth = computed(() => client.value?.birth ?? null);

const clientLocalTime = computed(() => {
    if (!client.value?.timezone) return null;
    try {
        return new Intl.DateTimeFormat(locale.value, {
            hour: '2-digit',
            minute: '2-digit',
            weekday: 'short',
            timeZone: client.value.timezone,
        }).format(new Date());
    } catch {
        return null;
    }
});

async function toggleArchive() {
    const archived = client.value.status === 'archived';
    const { data } = archived
        ? await http.delete(`/clients/${client.value.id}/archive`)
        : await http.post(`/clients/${client.value.id}/archive`);
    client.value = data.data;
    toast.success(archived ? t('clients.restored') : t('clients.archived'));
}
</script>

<template>
    <p v-if="notFound" class="text-ink-3">{{ t('notFound.title') }}</p>

    <template v-else-if="client">
        <div class="page-head flex flex-wrap items-center gap-4">
            <div class="person">
                <span class="ini size-12! text-base!" aria-hidden="true">{{ initials(client.full_name) }}</span>
                <div>
                    <div class="eyebrow">
                        <RouterLink :to="{ name: 'clients.index' }" class="text-ink-4 hover:underline">{{
                            t('clients.title')
                        }}</RouterLink>
                    </div>
                    <h1>{{ client.full_name }}</h1>
                    <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                        <StatusBadge :status="client.status" />
                        <span v-for="method in client.methods" :key="method.id" class="method-pill">
                            {{ labels.method(method) }}<template v-if="method.is_default"> ★</template>
                        </span>
                        <span v-for="tag in client.tags" :key="tag" class="tag">{{ tag }}</span>
                    </div>
                </div>
            </div>
            <div class="ml-auto flex gap-2">
                <RouterLink :to="{ name: 'clients.edit', params: { id: client.id } }" class="btn">{{
                    t('clients.edit')
                }}</RouterLink>
                <button type="button" class="btn btn-ghost" @click="toggleArchive">
                    {{ client.status === 'archived' ? t('clients.restore') : t('clients.archive') }}
                </button>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
            <section class="card">
                <div class="card-head">
                    <h2>{{ t('clients.profile.birth') }}</h2>
                    <span v-if="birth?.chart.ready" class="badge b-ok right">{{
                        t('clients.profile.chartReady')
                    }}</span>
                </div>
                <div class="card-body space-y-4">
                    <template v-if="birth">
                        <dl class="facts">
                            <dt>{{ t('clients.profile.born') }}</dt>
                            <dd>{{ formatDate(birth.birth_date, locale) || '—' }}</dd>

                            <dt>{{ t('clients.profile.time') }}</dt>
                            <dd>
                                <span v-if="birth.birth_time" class="font-mono">{{ birth.birth_time }}</span>
                                <span class="tag ml-2">{{ labels.timeAccuracy(birth.time_accuracy) }}</span>
                            </dd>

                            <dt>{{ t('clients.profile.place') }}</dt>
                            <dd>
                                {{ birth.birth_place || '—'
                                }}<template v-if="birth.birth_country_code"
                                    >, {{ labels.country(birth.birth_country_code) }}</template
                                >
                            </dd>

                            <template v-if="birth.latitude !== null">
                                <dt>{{ t('clients.profile.coordinates') }}</dt>
                                <dd class="font-mono text-xs">
                                    {{ formatCoordinate(birth.latitude, 'lat') }}
                                    {{ formatCoordinate(birth.longitude, 'lng') }}
                                    <span class="text-ink-4">({{ birth.latitude }}, {{ birth.longitude }})</span>
                                </dd>
                            </template>

                            <template v-if="birth.birth_timezone">
                                <dt>{{ t('clients.profile.zone') }}</dt>
                                <dd>
                                    <span class="font-mono text-xs">{{ birth.birth_timezone }}</span>
                                    <span v-if="birth.moment" class="ml-2 text-ink-2">
                                        {{
                                            t('clients.profile.offset', {
                                                offset: birth.moment.utc_offset,
                                                abbr: birth.moment.abbreviation,
                                            })
                                        }}<template v-if="birth.moment.is_dst">
                                            · {{ t('clients.profile.summerTime') }}</template
                                        >
                                    </span>
                                </dd>
                            </template>

                            <template v-if="birth.geocode_source">
                                <dt>{{ t('clients.profile.source') }}</dt>
                                <dd>{{ t(`geocodeSource.${birth.geocode_source}`) }}</dd>
                            </template>

                            <template v-if="birth.data_source">
                                <dt>{{ t('clients.profile.dataSource') }}</dt>
                                <dd>{{ birth.data_source }}</dd>
                            </template>
                        </dl>

                        <p v-if="birth.notes" class="whitespace-pre-line text-ink-2">{{ birth.notes }}</p>

                        <div v-if="!birth.chart.ready" class="notice n-warn" role="status">
                            <div>
                                <strong>{{ t('clients.missing.title') }}</strong>
                                {{ birth.chart.missing.map((key) => t(`clients.missing.${key}`)).join(', ') }}
                            </div>
                        </div>
                        <div v-if="birth.clock_change" class="notice n-warn" role="status">
                            {{ t(`clients.warnings.${birth.clock_change}`) }}
                        </div>
                        <div v-if="birth.time_accuracy === 'approximate'" class="notice n-info">
                            {{ t('clients.warnings.approximate') }}
                        </div>
                        <div v-if="birth.zone_history_uncertain" class="notice n-neutral">
                            {{ t('clients.warnings.zoneHistory') }}
                        </div>
                        <p v-if="birth.chart.ready" class="text-xs text-ink-4">{{ t('clients.profile.chartSoon') }}</p>
                    </template>

                    <div v-else class="flex items-center justify-between gap-3">
                        <span class="text-ink-3">{{ t('clients.profile.noBirth') }}</span>
                        <RouterLink :to="{ name: 'clients.edit', params: { id: client.id } }" class="btn btn-sm">{{
                            t('clients.profile.addBirth')
                        }}</RouterLink>
                    </div>
                </div>
            </section>

            <div class="space-y-4">
                <section class="card">
                    <div class="card-head">
                        <h2>{{ t('clients.profile.contact') }}</h2>
                    </div>
                    <dl class="card-body facts">
                        <template v-if="client.email">
                            <dt>{{ t('clients.form.email') }}</dt>
                            <dd class="truncate">
                                <a :href="`mailto:${client.email}`">{{ client.email }}</a>
                            </dd>
                        </template>
                        <template v-if="client.phone">
                            <dt>{{ t('clients.form.phone') }}</dt>
                            <dd>
                                <a :href="`tel:${client.phone}`">{{ client.phone }}</a>
                            </dd>
                        </template>
                        <template v-if="client.country_code">
                            <dt>{{ t('clients.form.country') }}</dt>
                            <dd>{{ labels.country(client.country_code) }}</dd>
                        </template>
                        <template v-if="client.timezone">
                            <dt>{{ t('clients.form.timezone') }}</dt>
                            <dd>
                                <span class="font-mono text-xs">{{ client.timezone }}</span>
                                <span v-if="clientLocalTime" class="block text-xs text-ink-3">
                                    {{ t('clients.profile.localTime') }}: {{ clientLocalTime }}
                                </span>
                            </dd>
                        </template>
                        <template v-if="client.preferred_locale">
                            <dt>{{ t('clients.form.language') }}</dt>
                            <dd>{{ labels.languageName(client.preferred_locale) }}</dd>
                        </template>
                        <dt>{{ t('clients.profile.lastActivity') }}</dt>
                        <dd>{{ formatRelative(client.last_activity_at, locale) }}</dd>
                        <dt>{{ t('clients.profile.created') }}</dt>
                        <dd>{{ formatDate(client.created_at?.slice(0, 10), locale, 'medium') }}</dd>
                    </dl>
                </section>

                <section class="card">
                    <div class="card-head">
                        <h2>{{ t('clients.profile.notes') }}</h2>
                    </div>
                    <div class="card-body">
                        <p v-if="client.internal_notes" class="whitespace-pre-line">{{ client.internal_notes }}</p>
                        <p v-else class="text-ink-3">{{ t('clients.profile.noNotes') }}</p>
                    </div>
                </section>
            </div>
        </div>
    </template>

    <p v-else class="text-ink-3">{{ t('common.loading') }}</p>
</template>
