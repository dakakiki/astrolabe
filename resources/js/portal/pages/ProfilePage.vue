<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import FormField from '@/components/FormField.vue';
import PhoneInput from '@/components/PhoneInput.vue';
import http, { validationErrors } from '@/portal/http';
import { loadScreen } from '@/portal/load';
import { useSessionStore } from '@/portal/stores/session';
import { useToastStore } from '@/stores/toast';

/**
 * Portal → Profile (docs/spec/12): the name the portal greets with, the phone
 * number the practice has, and the zone and language times and text are
 * shown in. The address is the practice's to change.
 */
const { t } = useI18n();
const session = useSessionStore();
const toast = useToastStore();

const profile = ref(null);
const form = ref(null);
const errors = ref({});
const busy = ref(false);

const zones = Intl.supportedValuesOf ? Intl.supportedValuesOf('timeZone') : [];
const deviceZone = Intl.DateTimeFormat().resolvedOptions().timeZone;

onMounted(() => loadScreen(loadProfile));

async function loadProfile() {
    const response = await http.get('/profile');
    profile.value = response.data.data;
    form.value = {
        name: profile.value.name ?? '',
        phone: profile.value.phone,
        timezone: profile.value.timezone ?? '',
        locale: profile.value.locale ?? '',
    };
}

async function save() {
    busy.value = true;
    errors.value = {};

    try {
        const response = await http.put('/profile', {
            name: form.value.name || null,
            phone: form.value.phone || null,
            timezone: form.value.timezone || null,
            locale: form.value.locale || null,
        });
        profile.value = response.data.data;
        session.setUser({ name: profile.value.name, timezone: profile.value.timezone, locale: profile.value.locale });
        toast.success(t('portal.profile.saved'));
    } catch (error) {
        errors.value = validationErrors(error) ?? {};
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="page-head">
        <div class="eyebrow">{{ session.practice?.name }}</div>
        <h1>{{ t('portal.nav.profile') }}</h1>
    </div>

    <p v-if="!form" class="text-ink-3">{{ t('common.loading') }}</p>

    <section v-else class="card max-w-xl">
        <form class="card-body" novalidate @submit.prevent="save">
            <FormField
                v-slot="{ id }"
                :label="t('portal.fields.email')"
                :hint="t('portal.profile.emailHint', { practice: session.practice?.name })"
            >
                <input :id="id" :value="profile.email" class="input" readonly />
            </FormField>
            <FormField v-slot="{ id, aria }" :label="t('portal.fields.name')" :error="errors.name">
                <input :id="id" v-model="form.name" v-bind="aria" class="input" autocomplete="name" maxlength="120" />
            </FormField>
            <FormField
                v-slot="{ id, aria }"
                :label="t('portal.fields.phone')"
                :error="errors.phone"
                :hint="t('portal.profile.phoneHint', { practice: session.practice?.name })"
            >
                <PhoneInput
                    v-model="form.phone"
                    :input-id="id"
                    :aria="aria"
                    :countries="profile.countries"
                    :default-country="profile.country_code"
                />
            </FormField>
            <FormField
                v-slot="{ id, aria }"
                :label="t('portal.fields.timezone')"
                :error="errors.timezone"
                :hint="t('portal.profile.timezoneHint', { zone: profile.practice_timezone })"
            >
                <select :id="id" v-model="form.timezone" v-bind="aria" class="input">
                    <option value="">
                        {{ t('portal.profile.practiceZone', { zone: profile.practice_timezone }) }}
                    </option>
                    <option v-if="deviceZone && !zones.includes(deviceZone)" :value="deviceZone">
                        {{ deviceZone }}
                    </option>
                    <option v-for="zone in zones" :key="zone" :value="zone">{{ zone }}</option>
                </select>
            </FormField>
            <FormField v-slot="{ id, aria }" :label="t('portal.fields.language')" :error="errors.locale">
                <select :id="id" v-model="form.locale" v-bind="aria" class="input">
                    <option value="">{{ t('portal.profile.defaultLanguage') }}</option>
                    <option v-for="item in profile.locales" :key="item.code" :value="item.code">{{ item.name }}</option>
                </select>
            </FormField>
            <button class="btn btn-primary" type="submit" :disabled="busy">{{ t('common.save') }}</button>
        </form>
    </section>
</template>
