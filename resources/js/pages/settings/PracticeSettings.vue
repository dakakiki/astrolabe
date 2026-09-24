<script setup>
import { useI18n } from 'vue-i18n';

import FormField from '@/components/FormField.vue';
import { useForm } from '@/composables/useForm';
import http from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

const { t } = useI18n();
const auth = useAuthStore();
const toast = useToastStore();

const form = useForm({ name: auth.workspace.name });

async function save() {
    await form
        .submit(async (data) => {
            const response = await http.patch('/workspace', data);
            auth.workspace = response.data.data;
            toast.success(t('settings.practice.saved'));
        })
        .catch(() => {});
}
</script>

<template>
    <div v-if="!auth.isOwner" class="notice n-neutral">{{ t('settings.ownerOnly') }}</div>

    <section class="card">
        <div class="card-head">
            <h2>{{ t('settings.practice.title') }}</h2>
        </div>
        <form class="card-body" novalidate @submit.prevent="save">
            <fieldset :disabled="!auth.isOwner">
                <FormField
                    v-slot="{ id, aria }"
                    :label="t('auth.fields.practiceName')"
                    :error="form.errors.value.name"
                    :hint="t('settings.practice.nameHint')"
                >
                    <input :id="id" v-model="form.data.name" v-bind="aria" class="input" autocomplete="organization" />
                </FormField>
                <button class="btn btn-primary" type="submit" :disabled="form.processing.value">
                    {{ t('common.save') }}
                </button>
            </fieldset>
        </form>
    </section>
</template>
