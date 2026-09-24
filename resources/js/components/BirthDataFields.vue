<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import FormField from '@/components/FormField.vue';
import PlaceAutocomplete from '@/components/PlaceAutocomplete.vue';
import { timeZoneOptions, useLabels } from '@/composables/useLabels';
import { formatCoordinate } from '@/lib/format';
import http from '@/lib/http';

/**
 * Birth data inputs. The location is either a place picked from the gazetteer
 * (copied in by the server and frozen) or coordinates and a time zone typed by
 * hand. `errors` uses the server's keys, e.g. "birth.birth_time".
 */
const birth = defineModel({ type: Object, required: true });

const props = defineProps({
    errors: { type: Object, default: () => ({}) },
    countries: { type: Array, default: () => [] },
});

const { t } = useI18n();
const labels = useLabels();

const error = (field) => props.errors[`birth.${field}`];
const hasTime = computed(() => birth.value.time_accuracy !== 'unknown');
const zones = computed(() => timeZoneOptions(birth.value.birth_timezone));
const sortedCountries = computed(() =>
    [...props.countries].sort((a, b) => labels.country(a).localeCompare(labels.country(b))),
);

function selectPlace(place) {
    birth.value.mode = 'place';
    birth.value.place = place;
}

function enterManually() {
    // Start from the chosen place, if any, so small corrections are easy.
    const place = birth.value.place;
    Object.assign(birth.value, {
        mode: 'manual',
        birth_place: place?.label ?? birth.value.birth_place ?? '',
        birth_country_code: place?.country_code ?? birth.value.birth_country_code ?? '',
        latitude: place?.latitude ?? birth.value.latitude ?? '',
        longitude: place?.longitude ?? birth.value.longitude ?? '',
        birth_timezone: place?.timezone ?? birth.value.birth_timezone ?? '',
        place: null,
    });
}

function chooseFromList() {
    birth.value.mode = 'none';
    birth.value.place = null;
}

const suggestion = ref(null);

async function suggestTimezone() {
    const { latitude, longitude } = birth.value;
    if (latitude === '' || longitude === '') return;

    const { data } = await http.get('/places/nearest', { params: { latitude, longitude } });
    if (data.data?.timezone) {
        birth.value.birth_timezone = data.data.timezone;
        suggestion.value = data.data.label;
    }
}
</script>

<template>
    <div class="row">
        <FormField v-slot="{ id, aria }" :label="t('clients.birth.date')" :error="error('birth_date')">
            <input :id="id" v-model="birth.birth_date" v-bind="aria" class="input" type="date" min="1800-01-01" />
        </FormField>
        <FormField
            v-slot="{ id, aria }"
            :label="t('clients.birth.accuracy')"
            :error="error('time_accuracy')"
            :hint="t(`timeAccuracy.${birth.time_accuracy}.hint`)"
        >
            <select :id="id" v-model="birth.time_accuracy" v-bind="aria" class="input">
                <option v-for="value in ['exact', 'rectified', 'approximate', 'unknown']" :key="value" :value="value">
                    {{ labels.timeAccuracy(value) }}
                </option>
            </select>
        </FormField>
        <FormField v-if="hasTime" v-slot="{ id, aria }" :label="t('clients.birth.time')" :error="error('birth_time')">
            <input :id="id" v-model="birth.birth_time" v-bind="aria" class="input" type="time" />
        </FormField>
    </div>

    <!-- Place picked from the list: shown as frozen values. -->
    <div v-if="birth.mode === 'place' && birth.place" class="field">
        <div class="label">{{ t('clients.birth.place') }}</div>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-md border border-line bg-surface-2 px-3 py-2">
            <div class="min-w-0 flex-1">
                <div class="text-ink">{{ birth.place.label }}, {{ labels.country(birth.place.country_code) }}</div>
                <div class="font-mono text-xs text-ink-3">
                    {{ formatCoordinate(birth.place.latitude, 'lat') }}
                    {{ formatCoordinate(birth.place.longitude, 'lng') }} · {{ birth.place.timezone }}
                </div>
            </div>
            <button type="button" class="btn btn-ghost btn-sm" @click="chooseFromList">
                {{ t('clients.birth.change') }}
            </button>
            <button type="button" class="btn btn-ghost btn-sm" @click="enterManually">
                {{ t('clients.birth.enterManually') }}
            </button>
        </div>
        <div class="hint">{{ t('clients.birth.frozenHint') }}</div>
        <div v-if="error('place_id')" class="error">{{ error('place_id') }}</div>
    </div>

    <!-- Coordinates and zone typed by hand. -->
    <template v-else-if="birth.mode === 'manual'">
        <div class="row">
            <FormField v-slot="{ id, aria }" :label="t('clients.birth.placeName')" :error="error('birth_place')">
                <input :id="id" v-model="birth.birth_place" v-bind="aria" class="input" />
            </FormField>
            <FormField v-slot="{ id, aria }" :label="t('clients.birth.country')" :error="error('birth_country_code')">
                <select :id="id" v-model="birth.birth_country_code" v-bind="aria" class="input">
                    <option value="">{{ t('clients.form.none') }}</option>
                    <option v-for="code in sortedCountries" :key="code" :value="code">
                        {{ labels.country(code) }}
                    </option>
                </select>
            </FormField>
        </div>
        <div class="row">
            <FormField
                v-slot="{ id, aria }"
                :label="t('clients.birth.latitude')"
                :error="error('latitude')"
                :hint="t('clients.birth.latitudeHint')"
            >
                <input :id="id" v-model="birth.latitude" v-bind="aria" class="input font-mono" inputmode="decimal" />
            </FormField>
            <FormField
                v-slot="{ id, aria }"
                :label="t('clients.birth.longitude')"
                :error="error('longitude')"
                :hint="t('clients.birth.longitudeHint')"
            >
                <input :id="id" v-model="birth.longitude" v-bind="aria" class="input font-mono" inputmode="decimal" />
            </FormField>
        </div>
        <FormField
            v-slot="{ id, aria }"
            :label="t('clients.birth.timezone')"
            :error="error('birth_timezone')"
            :hint="suggestion ? t('clients.birth.suggested', { place: suggestion }) : null"
        >
            <div class="flex gap-2">
                <select :id="id" v-model="birth.birth_timezone" v-bind="aria" class="input">
                    <option value="">{{ t('clients.form.none') }}</option>
                    <option v-for="zone in zones" :key="zone" :value="zone">{{ zone }}</option>
                </select>
                <button
                    type="button"
                    class="btn"
                    :disabled="birth.latitude === '' || birth.longitude === ''"
                    @click="suggestTimezone"
                >
                    {{ t('clients.birth.suggestTimezone') }}
                </button>
            </div>
        </FormField>
        <button type="button" class="btn btn-ghost btn-sm mb-3" @click="chooseFromList">
            {{ t('clients.birth.backToList') }}
        </button>
    </template>

    <!-- Nothing chosen yet: search the list. -->
    <template v-else>
        <FormField v-slot="{ id, aria }" :label="t('clients.birth.place')" :error="error('place_id')">
            <PlaceAutocomplete :input-id="id" :aria="aria" @select="selectPlace" />
        </FormField>
        <button type="button" class="btn btn-ghost btn-sm -mt-2 mb-3" @click="enterManually">
            {{ t('clients.birth.enterManually') }}
        </button>
    </template>

    <div class="row">
        <FormField
            v-slot="{ id, aria }"
            :label="t('clients.birth.dataSource')"
            :error="error('data_source')"
            :hint="t('clients.birth.dataSourceHint')"
        >
            <input :id="id" v-model="birth.data_source" v-bind="aria" class="input" />
        </FormField>
    </div>
    <FormField v-slot="{ id, aria }" :label="t('clients.birth.notes')" :error="error('notes')">
        <textarea :id="id" v-model="birth.notes" v-bind="aria" class="input min-h-16" rows="2" />
    </FormField>
</template>
