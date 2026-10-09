<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';

import FormField from '@/components/FormField.vue';
import { normaliseCode } from '@/lib/security';
import { home, safeRedirect } from '@/portal/guard';
import http, { validationErrors } from '@/portal/http';
import { useSessionStore } from '@/portal/stores/session';

/**
 * Signing in without a password (docs/spec/12, "Prijava"): an address, then
 * either the emailed link or its six-digit code typed here. The answer never
 * says whether the address has an account.
 */
const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const session = useSessionStore();

const step = ref('email');
const email = ref('');
const code = ref('');
const errors = ref({});
const busy = ref(false);
const notice = ref('');

async function ask() {
    busy.value = true;
    errors.value = {};
    notice.value = '';

    try {
        await http.post('/sign-in', { email: email.value });
        step.value = 'code';
        code.value = '';
    } catch (error) {
        errors.value = validationErrors(error) ?? {};
        if (error.response?.status === 429) notice.value = t('portal.signIn.tooMany');
        else if (!validationErrors(error)) notice.value = t('portal.errors.generic');
    } finally {
        busy.value = false;
    }
}

async function verify() {
    busy.value = true;
    errors.value = {};

    try {
        const response = await http.post('/sign-in/code', { email: email.value, code: code.value });
        session.apply(response.data.data);
        router.replace(safeRedirect(route.query.redirect) ?? home(session));
    } catch (error) {
        errors.value = validationErrors(error) ?? {};
        if (error.response?.status === 429) notice.value = t('portal.signIn.tooMany');
    } finally {
        busy.value = false;
    }
}

function restart() {
    step.value = 'email';
    notice.value = '';
    errors.value = {};
}
</script>

<template>
    <template v-if="step === 'email'">
        <h1>{{ t('portal.signIn.title') }}</h1>
        <p class="sub">{{ t('portal.signIn.sub') }}</p>
        <div v-if="notice" class="notice n-warn mb-4" role="alert">{{ notice }}</div>
        <form novalidate @submit.prevent="ask">
            <FormField v-slot="{ id, aria }" :label="t('portal.fields.email')" :error="errors.email">
                <input
                    :id="id"
                    v-model="email"
                    v-bind="aria"
                    class="input"
                    type="email"
                    autocomplete="email"
                    inputmode="email"
                    required
                />
            </FormField>
            <button class="btn btn-primary w-full justify-center" type="submit" :disabled="busy || !email">
                {{ t('portal.signIn.send') }}
            </button>
        </form>
        <p class="mt-5 text-xs text-ink-3">{{ t('portal.signIn.noPassword') }}</p>
    </template>

    <template v-else>
        <h1>{{ t('portal.signIn.checkTitle') }}</h1>
        <p class="sub">{{ t('portal.signIn.checkSub', { email }) }}</p>
        <div v-if="notice" class="notice n-warn mb-4" role="alert">{{ notice }}</div>
        <form novalidate @submit.prevent="verify">
            <FormField v-slot="{ id, aria }" :label="t('portal.fields.code')" :error="errors.code">
                <input
                    :id="id"
                    :value="code"
                    v-bind="aria"
                    class="input code-input"
                    autocomplete="one-time-code"
                    inputmode="numeric"
                    maxlength="7"
                    @input="code = normaliseCode($event.target.value)"
                />
            </FormField>
            <button class="btn btn-primary w-full justify-center" type="submit" :disabled="busy || code.length !== 6">
                {{ t('portal.signIn.verify') }}
            </button>
        </form>
        <div class="mt-5 flex flex-wrap justify-between gap-3 text-sm">
            <button type="button" class="link" @click="restart">{{ t('portal.signIn.otherAddress') }}</button>
            <button type="button" class="link" :disabled="busy" @click="ask">{{ t('portal.signIn.resend') }}</button>
        </div>
    </template>
</template>
