<script setup>
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import FormField from '@/components/FormField.vue';
import { useForm } from '@/composables/useForm';
import http from '@/lib/http';
import { groupSecret, normaliseCode } from '@/lib/security';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

/**
 * Optional two-factor sign-in (TOTP, Phase 8a). Every change asks for the
 * password first (Fortify's password confirmation, valid for a few hours);
 * turning it on needs one code from the app, so a mistyped key never locks
 * anyone out. Recovery codes are shown only on request.
 */
// After a change that the audit log records (the activity list reloads).
const emit = defineEmits(['changed']);

const { t } = useI18n();
const auth = useAuthStore();
const toast = useToastStore();

const enabled = computed(() => auth.user?.two_factor_enabled === true);

// idle | password | setup | codes
const step = ref('idle');
const pending = ref(null);
const qrSource = ref(null);
const secret = ref('');
const codes = ref([]);
const busy = ref(false);

const password = useForm({ password: '' });
const confirmation = useForm({ code: '' });
const passwordInput = ref(null);
const codeInput = ref(null);

/** Runs `action` once the password was confirmed recently, asking for it if needed. */
async function withPassword(action) {
    let confirmed = false;

    try {
        ({ confirmed } = (await http.get('/auth/user/confirmed-password-status')).data);
    } catch {
        toast.error(t('errors.generic'));

        return;
    }

    if (confirmed) {
        return run(action);
    }

    pending.value = action;
    step.value = 'password';
    await nextTick();
    passwordInput.value?.focus();
}

async function run(action) {
    busy.value = true;

    try {
        await action();
    } catch (error) {
        // The confirmation ran out in the meantime.
        if (error.response?.status === 423) {
            pending.value = action;
            step.value = 'password';
        } else {
            toast.error(t('errors.generic'));
        }
    } finally {
        busy.value = false;
    }
}

async function confirmPassword() {
    const confirmed = await password
        .submit((data) => http.post('/auth/user/confirm-password', data))
        .then(
            () => true,
            () => false,
        );

    if (confirmed) {
        const action = pending.value;
        password.reset();
        pending.value = null;
        step.value = 'idle';
        await run(action);
    }
}

async function start() {
    await http.post('/auth/user/two-factor-authentication');
    const [qr, key] = await Promise.all([
        http.get('/auth/user/two-factor-qr-code'),
        http.get('/auth/user/two-factor-secret-key'),
    ]);

    // Shown as an image: no server markup goes into the page itself.
    qrSource.value = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(qr.data.svg)}`;
    secret.value = groupSecret(key.data.secretKey);
    confirmation.reset();
    step.value = 'setup';
    await nextTick();
    codeInput.value?.focus();
}

async function confirmSetup() {
    await confirmation
        .submit(async (data) => {
            await http.post('/auth/user/confirmed-two-factor-authentication', { code: normaliseCode(data.code) });
            await auth.load({ force: true });
            await loadCodes();
            toast.success(t('settings.security.twoFactor.enabled'));
            emit('changed');
        })
        .catch(() => {});
}

async function cancelSetup() {
    step.value = 'idle';
    await run(() => http.delete('/auth/user/two-factor-authentication'));
}

async function loadCodes() {
    const { data } = await http.get('/auth/user/two-factor-recovery-codes');
    codes.value = data;
    step.value = 'codes';
}

async function renewCodes() {
    if (!window.confirm(t('settings.security.twoFactor.newCodesConfirm'))) {
        return;
    }

    await withPassword(async () => {
        await http.post('/auth/user/two-factor-recovery-codes');
        await loadCodes();
        toast.success(t('settings.security.twoFactor.newCodesDone'));
        emit('changed');
    });
}

async function disable() {
    if (!window.confirm(t('settings.security.twoFactor.disableConfirm'))) {
        return;
    }

    await withPassword(async () => {
        await http.delete('/auth/user/two-factor-authentication');
        await auth.load({ force: true });
        codes.value = [];
        step.value = 'idle';
        toast.success(t('settings.security.twoFactor.disabled'));
        emit('changed');
    });
}

function closeCodes() {
    codes.value = [];
    step.value = 'idle';
}
</script>

<template>
    <section class="card">
        <div class="card-head">
            <h2>{{ t('settings.security.twoFactor.title') }}</h2>
            <span class="badge" :class="enabled ? 'b-ok' : 'b-plain'">
                {{ enabled ? t('settings.security.twoFactor.on') : t('settings.security.twoFactor.off') }}
            </span>
        </div>
        <div class="card-body">
            <p class="mb-3 text-ink-2">{{ t('settings.security.twoFactor.intro') }}</p>

            <form v-if="step === 'password'" novalidate @submit.prevent="confirmPassword">
                <p class="mb-3 text-ink-3">{{ t('settings.security.twoFactor.confirmPassword') }}</p>
                <div class="flex items-end gap-2">
                    <div class="flex-1">
                        <FormField
                            v-slot="{ id, aria }"
                            :label="t('auth.fields.password')"
                            :error="password.errors.value.password"
                        >
                            <input
                                :id="id"
                                ref="passwordInput"
                                v-model="password.data.password"
                                v-bind="aria"
                                class="input"
                                type="password"
                                autocomplete="current-password"
                            />
                        </FormField>
                    </div>
                    <button class="btn btn-primary mb-3.5" type="submit" :disabled="password.processing.value">
                        {{ t('settings.security.twoFactor.continue') }}
                    </button>
                    <button class="btn mb-3.5" type="button" @click="step = 'idle'">{{ t('common.cancel') }}</button>
                </div>
            </form>

            <form v-else-if="step === 'setup'" novalidate @submit.prevent="confirmSetup">
                <p class="mb-3">{{ t('settings.security.twoFactor.scan') }}</p>
                <div class="mb-4 flex flex-wrap items-start gap-5">
                    <img :src="qrSource" :alt="t('settings.security.twoFactor.qrAlt')" class="qr-code h-44 w-44" />
                    <div class="min-w-0">
                        <div class="text-xs text-ink-3">{{ t('settings.security.twoFactor.setupKey') }}</div>
                        <code class="font-mono text-sm break-all select-all">{{ secret }}</code>
                    </div>
                </div>
                <div class="flex items-end gap-2">
                    <div class="max-w-56 flex-1">
                        <FormField
                            v-slot="{ id, aria }"
                            :label="t('settings.security.twoFactor.code')"
                            :error="confirmation.errors.value.code"
                        >
                            <input
                                :id="id"
                                ref="codeInput"
                                v-model="confirmation.data.code"
                                v-bind="aria"
                                class="input font-mono tracking-widest"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                maxlength="12"
                            />
                        </FormField>
                    </div>
                    <button class="btn btn-primary mb-3.5" type="submit" :disabled="confirmation.processing.value">
                        {{ t('settings.security.twoFactor.confirm') }}
                    </button>
                    <button class="btn mb-3.5" type="button" :disabled="busy" @click="cancelSetup">
                        {{ t('common.cancel') }}
                    </button>
                </div>
            </form>

            <div v-else-if="step === 'codes'">
                <h3 class="mb-1 font-medium">{{ t('settings.security.twoFactor.codesTitle') }}</h3>
                <p class="mb-3 text-ink-3">{{ t('settings.security.twoFactor.codesIntro') }}</p>
                <ul class="mb-4 grid grid-cols-1 gap-x-6 gap-y-1 font-mono text-sm select-all sm:grid-cols-2">
                    <li v-for="code in codes" :key="code">{{ code }}</li>
                </ul>
                <button class="btn btn-primary" type="button" @click="closeCodes">
                    {{ t('settings.security.twoFactor.codesDone') }}
                </button>
            </div>

            <div v-else-if="enabled" class="flex flex-wrap gap-2">
                <button class="btn" type="button" :disabled="busy" @click="withPassword(loadCodes)">
                    {{ t('settings.security.twoFactor.showCodes') }}
                </button>
                <button class="btn" type="button" :disabled="busy" @click="renewCodes">
                    {{ t('settings.security.twoFactor.newCodes') }}
                </button>
                <button class="btn btn-danger" type="button" :disabled="busy" @click="disable">
                    {{ t('settings.security.twoFactor.disable') }}
                </button>
            </div>

            <button v-else class="btn btn-primary" type="button" :disabled="busy" @click="withPassword(start)">
                {{ t('settings.security.twoFactor.enable') }}
            </button>
        </div>
    </section>
</template>
