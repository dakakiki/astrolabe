<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import FormField from '@/components/FormField.vue';
import { useForm } from '@/composables/useForm';
import http from '@/lib/http';
import { useAuthStore } from '@/stores/auth';

const { t, locale } = useI18n();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const token = typeof route.query.invitation === 'string' ? route.query.invitation : '';

const form = useForm({
    name: '',
    email: '',
    workspace_name: '',
    password: '',
    password_confirmation: '',
});

/*
 * Closed beta: the server says whether accounts are by invitation and, for the
 * link in the invitation email, whether it still works and for which address.
 */
const mode = ref(null);
const invitation = ref(null);
const loadFailed = ref(false);

onMounted(async () => {
    try {
        const { data } = await http.get('/auth/registration', { params: token ? { invitation: token } : {} });
        mode.value = data.data.mode;
        invitation.value = data.data.invitation;

        if (invitation.value?.status === 'valid') {
            form.data.email = invitation.value.email;
        }
    } catch {
        loadFailed.value = true;
    }
});

/** Why the form is not shown: no link, or a link that no longer works. */
const blocked = computed(() => {
    if (mode.value !== 'invite') {
        return null;
    }

    return invitation.value?.status === 'valid' ? null : (invitation.value?.status ?? 'missing');
});

async function submit() {
    await form
        .submit((data) =>
            auth.register({
                ...data,
                invitation: token || undefined,
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

    <p v-if="loadFailed" class="notice n-warn">{{ t('errors.generic') }}</p>

    <template v-else-if="mode === null">
        <div class="sub">{{ t('common.loading') }}</div>
    </template>

    <template v-else-if="blocked">
        <div class="notice n-info mb-4">
            <strong>{{ t('auth.register.closedBeta') }}</strong>
            {{ t(`auth.register.invitation.${blocked}`) }}
        </div>
    </template>

    <template v-else>
        <div class="sub">
            {{ invitation ? t('auth.register.invitedSub') : t('auth.register.sub') }}
        </div>

        <form novalidate @submit.prevent="submit">
            <p v-if="form.errors.value.invitation" class="notice n-warn mb-4" role="alert">
                {{ form.errors.value.invitation }}
            </p>
            <FormField v-slot="{ id, aria }" :label="t('auth.fields.name')" :error="form.errors.value.name">
                <input :id="id" v-model="form.data.name" v-bind="aria" class="input" autocomplete="name" required />
            </FormField>
            <FormField
                v-slot="{ id, aria }"
                :label="t('auth.fields.email')"
                :error="form.errors.value.email"
                :hint="invitation ? t('auth.register.emailFromInvitation') : null"
            >
                <input
                    :id="id"
                    v-model="form.data.email"
                    v-bind="aria"
                    class="input"
                    type="email"
                    autocomplete="email"
                    :readonly="Boolean(invitation)"
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
    </template>

    <div class="auth-alt">
        {{ t('auth.register.haveAccount') }}
        <RouterLink :to="{ name: 'login' }">{{ t('auth.register.login') }}</RouterLink>
    </div>
</template>
