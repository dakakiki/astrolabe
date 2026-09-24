<script setup>
import { useI18n } from 'vue-i18n';
import { RouterLink, useRouter } from 'vue-router';

import FormField from '@/components/FormField.vue';
import { useForm } from '@/composables/useForm';
import { useAuthStore } from '@/stores/auth';

const { t, locale } = useI18n();
const auth = useAuthStore();
const router = useRouter();

const form = useForm({
    name: '',
    email: '',
    workspace_name: '',
    password: '',
    password_confirmation: '',
});

async function submit() {
    await form
        .submit((data) =>
            auth.register({
                ...data,
                // The account starts in the browser's time zone and the current language.
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                locale: locale.value,
            }),
        )
        .catch(() => {});

    if (auth.isAuthenticated) {
        router.replace({ name: 'verify-email' });
    }
}
</script>

<template>
    <h2>{{ t('auth.register.title') }}</h2>
    <div class="sub">{{ t('auth.register.sub') }}</div>

    <form novalidate @submit.prevent="submit">
        <FormField v-slot="{ id, aria }" :label="t('auth.fields.name')" :error="form.errors.value.name">
            <input :id="id" v-model="form.data.name" v-bind="aria" class="input" autocomplete="name" required />
        </FormField>
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
        <FormField
            v-slot="{ id, aria }"
            :label="`${t('auth.fields.practiceName')} (${t('common.optional')})`"
            :error="form.errors.value.workspace_name"
            :hint="t('auth.register.practiceHint')"
        >
            <input
                :id="id"
                v-model="form.data.workspace_name"
                v-bind="aria"
                class="input"
                autocomplete="organization"
            />
        </FormField>
        <FormField
            v-slot="{ id, aria }"
            :label="t('auth.fields.password')"
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
            {{ t('auth.register.submit') }}
        </button>
    </form>

    <div class="auth-alt">
        {{ t('auth.register.haveAccount') }}
        <RouterLink :to="{ name: 'login' }">{{ t('auth.register.login') }}</RouterLink>
    </div>
</template>
