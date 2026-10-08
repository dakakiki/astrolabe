<script setup>
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import FormField from '@/components/FormField.vue';
import { useForm } from '@/composables/useForm';
import http from '@/lib/http';

/**
 * Deleting a client for good, at the client's request (Phase 8b; owner only).
 * Says what goes and what stays, suggests archiving instead, and asks for the
 * client's name typed out — the server checks it again.
 */
const props = defineProps({
    client: { type: Object, required: true },
});

const emit = defineEmits(['deleted']);

const { t } = useI18n();

const dialog = ref(null);
const input = ref(null);
const form = useForm({ confirmation: '' });

const normalise = (value) => value.trim().replace(/\s+/g, ' ').toLocaleLowerCase();
const matches = computed(() => normalise(form.data.confirmation) === normalise(props.client.full_name));

async function open() {
    form.reset();
    dialog.value.showModal();
    await nextTick();
    input.value?.focus();
}

function close() {
    dialog.value.close();
}

async function submit() {
    await form
        .submit(async (data) => {
            await http.delete(`/clients/${props.client.id}`, { data });
            close();
            emit('deleted');
        })
        .catch(() => {});
}

defineExpose({ open });
</script>

<template>
    <dialog ref="dialog" class="dialog" :aria-labelledby="`delete-client-${client.id}`">
        <form novalidate @submit.prevent="submit">
            <div class="card-head">
                <h2 :id="`delete-client-${client.id}`" class="text-danger">
                    {{ t('clients.deleteDialog.title', { name: client.full_name }) }}
                </h2>
            </div>
            <div class="card-body space-y-2.5">
                <p>{{ t('clients.deleteDialog.intro') }}</p>
                <ul class="list-disc space-y-1.5 pl-5 text-ink-2">
                    <li>{{ t('clients.deleteDialog.gone') }}</li>
                    <li>{{ t('clients.deleteDialog.kept') }}</li>
                    <li>{{ t('clients.deleteDialog.exports') }}</li>
                </ul>
                <p class="text-ink-3">{{ t('clients.deleteDialog.archiveInstead') }}</p>
                <FormField
                    v-slot="{ id, aria }"
                    :label="t('clients.deleteDialog.label', { name: client.full_name })"
                    :error="form.errors.value.confirmation"
                >
                    <input
                        :id="id"
                        ref="input"
                        v-model="form.data.confirmation"
                        v-bind="aria"
                        class="input"
                        autocomplete="off"
                        spellcheck="false"
                    />
                </FormField>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-ghost" @click="close">{{ t('common.cancel') }}</button>
                    <button type="submit" class="btn btn-danger" :disabled="!matches || form.processing.value">
                        {{ t('clients.deleteDialog.submit') }}
                    </button>
                </div>
            </div>
        </form>
    </dialog>
</template>
