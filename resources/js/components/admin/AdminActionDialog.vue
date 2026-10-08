<script setup>
import { nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import FormField from '@/components/FormField.vue';
import http, { validationErrors } from '@/lib/http';
import { useToastStore } from '@/stores/toast';

/**
 * An account-help action in the admin (Phase 8c): says what will happen, asks
 * for a reason, and — when the server answers 423 — for the operator's password
 * again, then repeats the action. `open({ title, body, submit, reasonRequired,
 * danger, run })`, where `run(reason)` makes the request.
 */
const emit = defineEmits(['done']);

const { t } = useI18n();
const toast = useToastStore();

const dialog = ref(null);
const reasonInput = ref(null);
const passwordInput = ref(null);

const action = ref(null);
const reason = ref('');
const password = ref('');
const needsPassword = ref(false);
const busy = ref(false);
const errors = ref({});

async function open(options) {
    action.value = options;
    reason.value = '';
    password.value = '';
    needsPassword.value = false;
    errors.value = {};
    dialog.value.showModal();
    await nextTick();
    reasonInput.value?.focus();
}

function close() {
    dialog.value.close();
}

async function submit() {
    busy.value = true;
    errors.value = {};

    try {
        if (needsPassword.value) {
            await http.post('/auth/user/confirm-password', { password: password.value });
        }

        const result = await action.value.run(reason.value.trim() || null);
        close();
        emit('done', result);
    } catch (error) {
        const status = error.response?.status;

        if (status === 423) {
            needsPassword.value = true;
            await nextTick();
            passwordInput.value?.focus();
        } else if (status === 422) {
            errors.value = validationErrors(error) ?? {};
        } else {
            toast.error(error.response?.data?.message ?? t('errors.generic'));
        }
    } finally {
        busy.value = false;
    }
}

defineExpose({ open });
</script>

<template>
    <dialog ref="dialog" class="dialog" aria-labelledby="admin-action-title">
        <form v-if="action" novalidate @submit.prevent="submit">
            <div class="card-head">
                <h2 id="admin-action-title" :class="{ 'text-danger': action.danger }">{{ action.title }}</h2>
            </div>
            <div class="card-body space-y-3">
                <p>{{ action.body }}</p>
                <FormField
                    v-slot="{ id, aria }"
                    :label="t('admin.common.reason')"
                    :hint="action.reasonRequired ? t('admin.common.reasonHint') : t('admin.common.reasonOptionalHint')"
                    :error="errors.reason"
                >
                    <textarea
                        :id="id"
                        ref="reasonInput"
                        v-model="reason"
                        v-bind="aria"
                        class="input"
                        rows="3"
                        maxlength="500"
                    />
                </FormField>
                <FormField
                    v-if="needsPassword"
                    v-slot="{ id, aria }"
                    :label="t('admin.common.password')"
                    :hint="t('admin.common.passwordHint')"
                    :error="errors.password"
                >
                    <input
                        :id="id"
                        ref="passwordInput"
                        v-model="password"
                        v-bind="aria"
                        class="input"
                        type="password"
                        autocomplete="current-password"
                    />
                </FormField>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-ghost" @click="close">{{ t('common.cancel') }}</button>
                    <button
                        type="submit"
                        class="btn"
                        :class="action.danger ? 'btn-danger' : 'btn-primary'"
                        :disabled="busy || (action.reasonRequired && !reason.trim()) || (needsPassword && !password)"
                    >
                        {{ action.submit }}
                    </button>
                </div>
            </div>
        </form>
    </dialog>
</template>
