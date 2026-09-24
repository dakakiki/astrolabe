<script setup>
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import FormField from '@/components/FormField.vue';
import { useForm } from '@/composables/useForm';
import { safeRedirect } from '@/router/guard';
import { useAuthStore } from '@/stores/auth';

const { t } = useI18n();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const form = useForm({ email: route.query.email ?? '', password: '', remember: false });

async function submit() {
    await form.submit((data) => auth.login(data)).catch(() => {});

    if (auth.isAuthenticated) {
        router.replace(safeRedirect(route.query.redirect) ?? { name: 'dashboard' });
    }
}
</script>

<template>
    <h2>{{ t('auth.login.title') }}</h2>
    <div class="sub">{{ t('auth.login.sub') }}</div>

    <div v-if="route.query.reset" class="notice n-ok mb-4">{{ t('auth.reset.done') }}</div>

    <form novalidate @submit.prevent="submit">
        <FormField v-slot="{ id, aria }" :label="t('auth.fields.email')" :error="form.errors.value.email">
            <input
                :id="id"
                v-model="form.data.email"
                v-bind="aria"
                class="input"
                type="email"
                autocomplete="username"
                required
            />
        </FormField>
        <FormField v-slot="{ id, aria }" :label="t('auth.fields.password')" :error="form.errors.value.password">
            <input
                :id="id"
                v-model="form.data.password"
                v-bind="aria"
                class="input"
                type="password"
                autocomplete="current-password"
                required
            />
        </FormField>
        <div class="mb-4 flex items-center justify-between gap-3 text-xs">
            <label class="flex items-center gap-2 text-ink-2">
                <input v-model="form.data.remember" type="checkbox" />
                {{ t('auth.fields.remember') }}
            </label>
            <RouterLink :to="{ name: 'forgot-password' }">{{ t('auth.login.forgot') }}</RouterLink>
        </div>
        <button class="btn btn-primary w-full" type="submit" :disabled="form.processing.value">
            {{ t('auth.login.submit') }}
        </button>
    </form>

    <div class="auth-alt">
        {{ t('auth.login.noAccount') }}
        <RouterLink :to="{ name: 'register' }">{{ t('auth.login.register') }}</RouterLink>
    </div>
</template>
