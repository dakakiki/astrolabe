<script setup>
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import FormField from '@/components/FormField.vue';
import { useForm } from '@/composables/useForm';
import http, { ensureCsrfCookie } from '@/lib/http';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const form = useForm({
    token: route.params.token,
    email: route.query.email ?? '',
    password: '',
    password_confirmation: '',
});

async function submit() {
    await form
        .submit(async (data) => {
            await ensureCsrfCookie();
            await http.post('/auth/reset-password', data);
            router.replace({ name: 'login', query: { reset: '1', email: data.email } });
        })
        .catch(() => {});
}
</script>

<template>
    <h2>{{ t('auth.reset.title') }}</h2>
    <div class="sub">{{ t('auth.reset.sub', { email: form.data.email }) }}</div>

    <!-- An invalid or expired token is reported by the server on the email field. -->
    <div v-if="form.errors.value.email" class="notice n-danger mb-4" role="alert">
        {{ form.errors.value.email }}
        <RouterLink :to="{ name: 'forgot-password' }" class="ml-1 underline">{{ t('auth.forgot.submit') }}</RouterLink>
    </div>

    <form novalidate @submit.prevent="submit">
        <FormField
            v-slot="{ id, aria }"
            :label="t('auth.fields.newPassword')"
            :error="form.errors.value.password"
            :hint="t('auth.register.passwordHint')"
        >
            <input
                :id="id"
                v-model="form.data.password"
                v-bind="aria"
                class="input"
                type="password"
                autocomplete="new-password"
                required
            />
        </FormField>
        <FormField v-slot="{ id, aria }" :label="t('auth.fields.passwordConfirmation')">
            <input
                :id="id"
                v-model="form.data.password_confirmation"
                v-bind="aria"
                class="input"
                type="password"
                autocomplete="new-password"
                required
            />
        </FormField>
        <button class="btn btn-primary w-full" type="submit" :disabled="form.processing.value">
            {{ t('auth.reset.submit') }}
        </button>
    </form>
</template>
