<script setup>
import { nextTick, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import FormField from '@/components/FormField.vue';
import { useForm } from '@/composables/useForm';
import http from '@/lib/http';
import { normaliseCode } from '@/lib/security';
import { safeRedirect } from '@/router/guard';
import { useAuthStore } from '@/stores/auth';

const { t } = useI18n();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const form = useForm({ email: route.query.email ?? '', password: '', remember: false });

/* Second step: the code from the authenticator app, or a recovery code. */
const challenge = ref(false);
const useRecovery = ref(false);
const code = useForm({ code: '', recovery_code: '' });
const codeInput = ref(null);

/* Closed beta: no "create one" link when accounts are by invitation. */
const registrationOpen = ref(true);
onMounted(async () => {
    try {
        const { data } = await http.get('/auth/registration');
        registrationOpen.value = data.data.mode === 'open';
    } catch {
        // Keep the link; the registration page explains itself.
    }
});

function enter() {
    router.replace(safeRedirect(route.query.redirect) ?? { name: 'dashboard' });
}

async function submit() {
    const result = await form.submit((data) => auth.login(data)).catch(() => null);

    if (result?.twoFactor) {
        challenge.value = true;
        await nextTick();
        codeInput.value?.focus();
    } else if (auth.isAuthenticated) {
        enter();
    }
}

async function submitCode() {
    const payload = useRecovery.value
        ? { recovery_code: code.data.recovery_code.trim() }
        : { code: normaliseCode(code.data.code) };

    await code.submit(() => auth.twoFactorChallenge(payload)).catch(() => {});

    if (auth.isAuthenticated) {
        enter();
    }
}

async function toggleRecovery() {
    useRecovery.value = !useRecovery.value;
    code.reset();
    await nextTick();
    codeInput.value?.focus();
}

function startOver() {
    challenge.value = false;
    useRecovery.value = false;
    code.reset();
    form.reset({ ...form.data, password: '' });
}
</script>

<template>
    <template v-if="!challenge">
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

        <div v-if="registrationOpen" class="auth-alt">
            {{ t('auth.login.noAccount') }}
            <RouterLink :to="{ name: 'register' }">{{ t('auth.login.register') }}</RouterLink>
        </div>
        <div v-else class="auth-alt">{{ t('auth.login.closedBeta') }}</div>
    </template>

    <template v-else>
        <h2>{{ t('auth.twoFactor.title') }}</h2>
        <div class="sub">{{ useRecovery ? t('auth.twoFactor.recoverySub') : t('auth.twoFactor.sub') }}</div>

        <form novalidate @submit.prevent="submitCode">
            <FormField
                v-if="!useRecovery"
                v-slot="{ id, aria }"
                :label="t('auth.twoFactor.code')"
                :error="code.errors.value.code"
            >
                <input
                    :id="id"
                    ref="codeInput"
                    v-model="code.data.code"
                    v-bind="aria"
                    class="input font-mono tracking-widest"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="12"
                    required
                />
            </FormField>
            <FormField
                v-else
                v-slot="{ id, aria }"
                :label="t('auth.twoFactor.recoveryCode')"
                :error="code.errors.value.recovery_code"
            >
                <input
                    :id="id"
                    ref="codeInput"
                    v-model="code.data.recovery_code"
                    v-bind="aria"
                    class="input font-mono"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    required
                />
            </FormField>
            <button class="btn btn-primary w-full" type="submit" :disabled="code.processing.value">
                {{ t('auth.twoFactor.submit') }}
            </button>
        </form>

        <div class="auth-alt flex flex-col gap-1">
            <button type="button" class="link" @click="toggleRecovery">
                {{ useRecovery ? t('auth.twoFactor.useApp') : t('auth.twoFactor.useRecovery') }}
            </button>
            <button type="button" class="link" @click="startOver">{{ t('auth.twoFactor.startOver') }}</button>
        </div>
    </template>
</template>
