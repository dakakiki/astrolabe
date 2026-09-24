<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

import { useLabels } from '@/composables/useLabels';
import { joinPhone, splitPhone } from '@/lib/phone';

/**
 * A dialling-code picker next to the number. The value stays one string,
 * "+381 60 1234567", so nothing about how numbers are stored changes.
 */
const model = defineModel({ type: String, default: null });

const props = defineProps({
    inputId: { type: String, default: undefined },
    aria: { type: Object, default: () => ({}) },
    countries: { type: Array, default: () => [] },
    /** Country to start with when the number has no prefix yet (the client's country). */
    defaultCountry: { type: String, default: null },
});

const { t } = useI18n();
const labels = useLabels();

const initial = splitPhone(model.value, props.countries, props.defaultCountry);
const country = ref(initial.country);
const number = ref(initial.number);

const options = computed(() =>
    props.countries
        .filter((c) => c.phone_code)
        .map((c) => ({ code: c.code, label: `${labels.country(c.code)} +${c.phone_code}` }))
        .sort((a, b) => a.label.localeCompare(b.label)),
);

watch([country, number], () => {
    model.value = joinPhone(country.value, number.value, props.countries);
});

// Reference data may arrive after the form; re-read the stored number once it does.
watch(
    () => props.countries.length,
    () => {
        const parts = splitPhone(model.value, props.countries, props.defaultCountry);
        country.value = parts.country;
        number.value = parts.number;
    },
);

// Choosing the client's country preselects its code while no number is typed.
watch(
    () => props.defaultCountry,
    (code) => {
        if (!number.value && code) country.value = code;
    },
);
</script>

<template>
    <div class="flex gap-2">
        <select
            v-model="country"
            class="input w-40 shrink-0"
            :aria-label="t('clients.form.dialCode')"
            :disabled="number.startsWith('+')"
        >
            <option :value="null">{{ t('clients.form.none') }}</option>
            <option v-for="option in options" :key="option.code" :value="option.code">{{ option.label }}</option>
        </select>
        <input
            :id="inputId"
            v-model="number"
            v-bind="aria"
            class="input min-w-0 flex-1"
            type="tel"
            autocomplete="off"
            inputmode="tel"
        />
    </div>
</template>
