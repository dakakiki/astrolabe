<script setup>
import { nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';

import FormField from '@/components/FormField.vue';
import { useForm } from '@/composables/useForm';
import http from '@/lib/http';
import { useToastStore } from '@/stores/toast';

/**
 * The "Feedback" button's dialog (Phase 8c): a category and a message to the
 * operator, with the screen it was sent from (the server keeps only its
 * pattern, without ids or search). No screenshot: the screen often shows
 * client data.
 */
const { t } = useI18n();
const route = useRoute();
const toast = useToastStore();

const CATEGORIES = ['bug', 'idea', 'question', 'other'];

const dialog = ref(null);
const textarea = ref(null);
const form = useForm({ category: 'bug', message: '' });

async function open() {
    form.reset();
    dialog.value.showModal();
    await nextTick();
    textarea.value?.focus();
}

function close() {
    dialog.value.close();
}

async function submit() {
    await form
        .submit(async (data) => {
            await http.post('/feedback', { ...data, page: route.path });
            close();
            toast.success(t('feedback.sent'));
        })
        .catch(() => {});
}

defineExpose({ open });
</script>

<template>
    <dialog ref="dialog" class="dialog" aria-labelledby="feedback-title">
        <form novalidate @submit.prevent="submit">
            <div class="card-head">
                <h2 id="feedback-title">{{ t('feedback.title') }}</h2>
            </div>
            <div class="card-body space-y-3">
                <p>{{ t('feedback.intro') }}</p>
                <p class="text-ink-3">{{ t('feedback.privacy') }}</p>
                <FormField v-slot="{ id, aria }" :label="t('feedback.category')" :error="form.errors.value.category">
                    <select :id="id" v-model="form.data.category" v-bind="aria" class="input">
                        <option v-for="category in CATEGORIES" :key="category" :value="category">
                            {{ t(`feedback.categories.${category}`) }}
                        </option>
                    </select>
                </FormField>
                <FormField v-slot="{ id, aria }" :label="t('feedback.message')" :error="form.errors.value.message">
                    <textarea
                        :id="id"
                        ref="textarea"
                        v-model="form.data.message"
                        v-bind="aria"
                        class="input"
                        rows="6"
                        maxlength="5000"
                    />
                </FormField>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-ghost" @click="close">{{ t('common.cancel') }}</button>
                    <button
                        type="submit"
                        class="btn btn-primary"
                        :disabled="form.processing.value || form.data.message.trim().length < 3"
                    >
                        {{ t('feedback.submit') }}
                    </button>
                </div>
            </div>
        </form>
    </dialog>
</template>
