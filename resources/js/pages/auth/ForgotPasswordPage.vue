<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

import FormField from '@/components/FormField.vue';
import { useForm } from '@/composables/useForm';
import http, { ensureCsrfCookie } from '@/lib/http';

const { t } = useI18n();

const form = useForm({ email: '' });
const sent = ref(false);

async function submit() {
    await form
        .submit(async (data) => {
            await ensureCsrfCookie();
            await http.post('/auth/forgot-password', data);
            sent.value = true;
        })
        .catch(() => {});
}
</script>

<template>
    <h2>{{ t('auth.forgot.title') }}</h2>
    <div class="sub">{{ t('auth.forgot.sub') }}</div>

    <div v-if="sent" class="notice n-ok mb-4" role="status">{{ t('auth.forgot.sent') }}</div>

    <form v-else novalidate @submit.prevent="submit">
        <FormField v-slot="{ id, aria }" :label="t('auth.fields.email')" :error="form.errors.value.email">
            <input
                :id="id"
                v-model="form.data.email"
                v-bind="aria"
                class="input"
                type="email"
                autocomplete="email"
                required
            />
        </FormField>
        <button class="btn btn-primary w-full" type="submit" :disabled="form.processing.value">
            {{ t('auth.forgot.submit') }}
        </button>
    </form>

    <div class="auth-alt">
        <RouterLink :to="{ name: 'login' }">{{ t('auth.forgot.back') }}</RouterLink>
    </div>
</template>
