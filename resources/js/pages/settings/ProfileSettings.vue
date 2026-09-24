<script setup>
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

const form = useForm({ name: auth.user.name, email: auth.user.email });

async function save() {
    const emailChanged = form.data.email !== auth.user.email;

    try {
        await form.submit(async (data) => {
            await http.put('/auth/user/profile-information', data);
            await auth.load({ force: true });
        });
    } catch {
        return;
    }

    if (emailChanged) {
        toast.success(t('settings.profile.emailChanged'));
        router.push({ name: 'verify-email' });
    } else {
        toast.success(t('settings.profile.saved'));
    }
}
</script>

<template>
    <section class="card">
        <div class="card-head">
            <h2>{{ t('settings.profile.title') }}</h2>
        </div>
        <form class="card-body" novalidate @submit.prevent="save">
            <FormField v-slot="{ id, aria }" :label="t('auth.fields.name')" :error="form.errors.value.name">
                <input :id="id" v-model="form.data.name" v-bind="aria" class="input" autocomplete="name" />
            </FormField>
            <FormField
                v-slot="{ id, aria }"
                :label="t('auth.fields.email')"
                :error="form.errors.value.email"
                :hint="t('settings.profile.emailHint')"
            >
                <input
                    :id="id"
                    v-model="form.data.email"
                    v-bind="aria"
                    class="input"
                    type="email"
                    autocomplete="email"
                />
            </FormField>
            <button class="btn btn-primary" type="submit" :disabled="form.processing.value">
                {{ t('common.save') }}
            </button>
        </form>
    </section>
</template>
