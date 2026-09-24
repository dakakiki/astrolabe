<script setup>
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

import { useLabels } from '@/composables/useLabels';
import { formatCoordinate, formatDate } from '@/lib/format';

/**
 * Birth data as entered, with what the server derived from it: the offset in
 * force at birth, clock-change and zone-history warnings, and what is still
 * missing for a chart. Used for clients and for related people alike.
 */
defineProps({
    /** BirthDetailsResource, or null when nothing has been entered. */
    birth: { type: Object, default: null },
    /** Where to enter the data when there is none. */
    editTo: { type: [Object, String], required: true },
});

const { t, locale } = useI18n();
const labels = useLabels();
</script>

<template>
    <section class="card">
        <div class="card-head">
            <h2>{{ t('clients.profile.birth') }}</h2>
            <span v-if="birth?.chart.ready" class="badge b-ok right">{{ t('clients.profile.chartReady') }}</span>
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
                <div v-if="birth.zone_history_uncertain" class="notice n-neutral">
                    {{ t('clients.warnings.zoneHistory') }}
                </div>
            </template>

            <div v-else class="flex items-center justify-between gap-3">
                <span class="text-ink-3">{{ t('clients.profile.noBirth') }}</span>
                <RouterLink :to="editTo" class="btn btn-sm">{{ t('clients.profile.addBirth') }}</RouterLink>
            </div>
        </div>
    </section>
</template>
