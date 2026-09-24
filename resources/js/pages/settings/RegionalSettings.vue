<script setup>
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';

import FormField from '@/components/FormField.vue';
import { useForm } from '@/composables/useForm';
import { timeZoneOptions, useLabels } from '@/composables/useLabels';
import http from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useReferenceStore } from '@/stores/reference';
import { useToastStore } from '@/stores/toast';

const { t } = useI18n();
const auth = useAuthStore();
const reference = useReferenceStore();
const toast = useToastStore();
const labels = useLabels();

onMounted(() => reference.load());

const locales = computed(() => reference.data?.locales ?? []);

/* The user's own preferences */

const mine = useForm({ locale: auth.user.locale, timezone: auth.user.timezone });
const myZones = computed(() => timeZoneOptions(mine.data.timezone));

async function saveMine() {
    await mine
        .submit(async (data) => {
            await http.put('/auth/user/profile-information', data);
            await auth.load({ force: true });
            toast.success(t('settings.regional.saved'));
        })
        .catch(() => {});
}

/* Practice-wide defaults */

const practice = useForm({
    default_locale: auth.workspace.default_locale,
    timezone: auth.workspace.timezone,
    default_currency: auth.workspace.default_currency,
});
const practiceZones = computed(() => timeZoneOptions(practice.data.timezone));

async function savePractice() {
    await practice
        .submit(async (data) => {
            const response = await http.patch('/workspace', data);
            auth.workspace = response.data.data;
            toast.success(t('settings.regional.saved'));
        })
        .catch(() => {});
}
</script>

<template>
    <section class="card">
        <div class="card-head">
            <h2>{{ t('settings.regional.yoursTitle') }}</h2>
        </div>
        <form class="card-body" novalidate @submit.prevent="saveMine">
            <p class="mb-3 text-ink-3">{{ t('settings.regional.yoursIntro') }}</p>
            <div class="row">
                <FormField
                    v-slot="{ id, aria }"
                    :label="t('settings.regional.language')"
                    :error="mine.errors.value.locale"
                >
                    <select :id="id" v-model="mine.data.locale" v-bind="aria" class="input">
                        <option v-for="option in locales" :key="option.code" :value="option.code">
                            {{ labels.language(option.code, option.name) }}
                        </option>
                    </select>
                </FormField>
                <FormField
                    v-slot="{ id, aria }"
                    :label="t('settings.regional.timezone')"
                    :error="mine.errors.value.timezone"
                >
                    <select :id="id" v-model="mine.data.timezone" v-bind="aria" class="input">
                        <option v-for="zone in myZones" :key="zone" :value="zone">{{ zone }}</option>
                    </select>
                </FormField>
            </div>
            <button class="btn btn-primary" type="submit" :disabled="mine.processing.value">
                {{ t('common.save') }}
            </button>
        </form>
    </section>

    <div v-if="!auth.isOwner" class="notice n-neutral">{{ t('settings.ownerOnly') }}</div>

    <section class="card">
        <div class="card-head">
            <h2>{{ t('settings.regional.practiceTitle') }}</h2>
        </div>
        <form class="card-body" novalidate @submit.prevent="savePractice">
            <fieldset :disabled="!auth.isOwner">
                <p class="mb-3 text-ink-3">{{ t('settings.regional.practiceIntro') }}</p>
                <div class="row">
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('settings.regional.practiceLanguage')"
                        :error="practice.errors.value.default_locale"
                    >
                        <select :id="id" v-model="practice.data.default_locale" v-bind="aria" class="input">
                            <option v-for="option in locales" :key="option.code" :value="option.code">
                                {{ labels.language(option.code, option.name) }}
                            </option>
                        </select>
                    </FormField>
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('settings.regional.practiceTimezone')"
                        :error="practice.errors.value.timezone"
                    >
                        <select :id="id" v-model="practice.data.timezone" v-bind="aria" class="input">
                            <option v-for="zone in practiceZones" :key="zone" :value="zone">{{ zone }}</option>
                        </select>
                    </FormField>
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('settings.regional.currency')"
                        :error="practice.errors.value.default_currency"
                    >
                        <select :id="id" v-model="practice.data.default_currency" v-bind="aria" class="input">
                            <option v-for="code in reference.data?.currencies ?? []" :key="code" :value="code">
                                {{ labels.currency(code) }}
                            </option>
                        </select>
                    </FormField>
                </div>
                <button class="btn btn-primary" type="submit" :disabled="practice.processing.value">
                    {{ t('common.save') }}
                </button>
            </fieldset>
        </form>
    </section>
</template>
