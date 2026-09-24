<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import FormField from '@/components/FormField.vue';
import { useForm } from '@/composables/useForm';
import http from '@/lib/http';
import { useToastStore } from '@/stores/toast';

const { t } = useI18n();
const toast = useToastStore();

/* Password */

const password = useForm({ current_password: '', password: '', password_confirmation: '' });

async function changePassword() {
    await password
        .submit(async (data) => {
            await http.put('/auth/user/password', data);
            password.reset();
            toast.success(t('settings.security.passwordSaved'));
        })
        .catch(() => {});
}

/* Other sessions */

const otherSessions = ref(null);
const sessions = useForm({ password: '' });

async function loadSessions() {
    const { data } = await http.get('/auth/other-sessions');
    otherSessions.value = data.data.count;
}
onMounted(loadSessions);

async function signOutOthers() {
    await sessions
        .submit(async (data) => {
            await http.delete('/auth/other-sessions', { data });
            sessions.reset();
            otherSessions.value = 0;
            toast.success(t('settings.security.sessionsDone'));
        })
        .catch(() => {});
}
</script>

<template>
    <section class="card">
        <div class="card-head">
            <h2>{{ t('settings.security.passwordTitle') }}</h2>
        </div>
        <form class="card-body" novalidate @submit.prevent="changePassword">
            <FormField
                v-slot="{ id, aria }"
                :label="t('auth.fields.currentPassword')"
                :error="password.errors.value.current_password"
            >
                <input
                    :id="id"
                    v-model="password.data.current_password"
                    v-bind="aria"
                    class="input"
                    type="password"
                    autocomplete="current-password"
                />
            </FormField>
            <div class="row">
                <FormField
                    v-slot="{ id, aria }"
                    :label="t('auth.fields.newPassword')"
                    :error="password.errors.value.password"
                    :hint="t('auth.register.passwordHint')"
                >
                    <input
                        :id="id"
                        v-model="password.data.password"
                        v-bind="aria"
                        class="input"
                        type="password"
                        autocomplete="new-password"
                    />
                </FormField>
                <FormField v-slot="{ id, aria }" :label="t('auth.fields.passwordConfirmation')">
                    <input
                        :id="id"
                        v-model="password.data.password_confirmation"
                        v-bind="aria"
                        class="input"
                        type="password"
                        autocomplete="new-password"
                    />
                </FormField>
            </div>
            <button class="btn btn-primary" type="submit" :disabled="password.processing.value">
                {{ t('common.save') }}
            </button>
        </form>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>{{ t('settings.security.sessionsTitle') }}</h2>
        </div>
        <div class="card-body">
            <p v-if="otherSessions === 0" class="text-ink-3">{{ t('settings.security.sessionsNone') }}</p>
            <form v-else-if="otherSessions" novalidate @submit.prevent="signOutOthers">
                <p class="mb-1">{{ t('settings.security.sessionsSome', { count: otherSessions }, otherSessions) }}</p>
                <p class="mb-3 text-ink-3">{{ t('settings.security.sessionsIntro') }}</p>
                <div class="flex items-end gap-2">
                    <div class="flex-1">
                        <FormField
                            v-slot="{ id, aria }"
                            :label="t('auth.fields.password')"
                            :error="sessions.errors.value.password"
                        >
                            <input
                                :id="id"
                                v-model="sessions.data.password"
                                v-bind="aria"
                                class="input"
                                type="password"
                                autocomplete="current-password"
                            />
                        </FormField>
                    </div>
                    <button class="btn btn-danger mb-3.5" type="submit" :disabled="sessions.processing.value">
                        {{ t('settings.security.sessionsSubmit') }}
                    </button>
                </div>
            </form>
        </div>
    </section>
</template>
