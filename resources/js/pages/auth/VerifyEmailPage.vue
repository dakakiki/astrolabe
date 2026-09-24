<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';

import FormField from '@/components/FormField.vue';
import { useForm } from '@/composables/useForm';
import http from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

const { t } = useI18n();
const auth = useAuthStore();
const toast = useToastStore();
const router = useRouter();

const resend = useForm({});
const changingEmail = ref(false);
const emailForm = useForm({ email: auth.user?.email ?? '' });

async function sendAgain() {
    await resend
        .submit(() => http.post('/auth/email/verification-notification'))
        .then(() => toast.success(t('auth.verify.resent')))
        .catch(() => {});
}

// Unverified users cannot reach Settings, so a mistyped address is fixed here.
async function updateEmail() {
    await emailForm
        .submit(async (data) => {
            await http.put('/auth/user/profile-information', { email: data.email });
            await auth.load({ force: true });
        })
        .then(() => {
            changingEmail.value = false;
            toast.success(t('auth.verify.resent'));
        })
        .catch(() => {});
}

async function signOut() {
    await auth.logout();
    router.push({ name: 'login' });
}
</script>

<template>
    <h2>{{ t('auth.verify.title') }}</h2>
    <div class="sub">{{ t('auth.verify.sub', { email: auth.user?.email }) }}</div>

    <button class="btn btn-primary w-full" type="button" :disabled="resend.processing.value" @click="sendAgain">
        {{ t('auth.verify.resend') }}
    </button>

    <div class="divider" />

    <form v-if="changingEmail" novalidate @submit.prevent="updateEmail">
        <FormField v-slot="{ id, aria }" :label="t('auth.fields.email')" :error="emailForm.errors.value.email">
            <input
                :id="id"
                v-model="emailForm.data.email"
                v-bind="aria"
                class="input"
                type="email"
                autocomplete="email"
                required
            />
        </FormField>
        <div class="flex gap-2">
            <button class="btn" type="submit" :disabled="emailForm.processing.value">
                {{ t('auth.verify.updateEmail') }}
            </button>
            <button class="btn btn-ghost" type="button" @click="changingEmail = false">{{ t('common.cancel') }}</button>
        </div>
    </form>

    <div class="flex items-center justify-between text-xs text-ink-3">
        <span v-if="!changingEmail">
            {{ t('auth.verify.wrongAddress') }}
            <button type="button" class="cursor-pointer text-link underline" @click="changingEmail = true">
                {{ t('auth.verify.changeEmail') }}
            </button>
        </span>
        <button type="button" class="ml-auto cursor-pointer text-link underline" @click="signOut">
            {{ t('auth.verify.signOut') }}
        </button>
    </div>
</template>
