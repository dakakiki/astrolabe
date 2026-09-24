<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import BirthDataFields from '@/components/BirthDataFields.vue';
import FormField from '@/components/FormField.vue';
import PhoneInput from '@/components/PhoneInput.vue';
import { useForm } from '@/composables/useForm';
import { useLabels } from '@/composables/useLabels';
import { birthFromApi, birthToPayload, emptyBirth, hasBirthInput } from '@/lib/birth';
import http from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useReferenceStore } from '@/stores/reference';
import { useToastStore } from '@/stores/toast';

/**
 * Adds a related person to a client (/clients/:clientId/people/new) or edits
 * one (/people/:id/edit). Birth data is entered exactly as for a client; the
 * relationship itself is chosen here on creation and edited on the client's
 * "Related people" tab, since a person can belong with several clients.
 */
const { t } = useI18n();
const labels = useLabels();
const route = useRoute();
const router = useRouter();
const reference = useReferenceStore();
const auth = useAuthStore();
const toast = useToastStore();

const personId = computed(() => route.params.id ?? null);
const clientId = computed(() => route.params.clientId ?? null);
const loaded = ref(false);
const clientName = ref('');
const personName = ref('');

const form = useForm({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    relationship_type: 'partner',
    notes: '',
});
const birth = ref(emptyBirth());

onMounted(async () => {
    await reference.load();

    if (personId.value) {
        const { data } = await http.get(`/related-people/${personId.value}`);
        const person = data.data;
        personName.value = person.full_name;
        form.reset({
            ...form.data,
            first_name: person.first_name,
            last_name: person.last_name ?? '',
            email: person.email ?? '',
            phone: person.phone ?? '',
        });
        birth.value = birthFromApi(person.birth);
    } else {
        const { data } = await http.get(`/clients/${clientId.value}`);
        clientName.value = data.data.full_name;
    }

    loaded.value = true;
});

async function save() {
    const nullable = (value) => (value === '' ? null : value);

    try {
        const response = await form.submit((data) => {
            const payload = {
                first_name: data.first_name,
                last_name: nullable(data.last_name),
                email: nullable(data.email),
                phone: nullable(data.phone),
            };
            if (hasBirthInput(birth.value)) payload.birth = birthToPayload(birth.value);

            if (personId.value) return http.patch(`/related-people/${personId.value}`, payload);

            return http.post('/related-people', {
                ...payload,
                client_id: Number(clientId.value),
                relationship_type: data.relationship_type,
                notes: nullable(data.notes),
            });
        });

        toast.success(personId.value ? t('related.form.saved') : t('related.form.created'));
        router.push({ name: 'related-people.show', params: { id: response.data.data.id } });
    } catch {
        // Field errors are shown next to the fields; other failures as a toast.
    }
}

const cancelRoute = computed(() =>
    personId.value
        ? { name: 'related-people.show', params: { id: personId.value } }
        : { name: 'clients.show', params: { id: clientId.value }, query: { tab: 'related' } },
);
</script>

<template>
    <div class="page-head">
        <div class="eyebrow">{{ t('related.person.eyebrow') }}</div>
        <h1>{{ personId ? t('related.form.editTitle', { name: personName }) : t('related.form.newTitle') }}</h1>
        <div v-if="!personId && clientName" class="sub">{{ t('related.form.newSub', { name: clientName }) }}</div>
    </div>

    <form v-if="loaded" class="max-w-4xl space-y-4" novalidate @submit.prevent="save">
        <section class="card">
            <div class="card-head">
                <h2>{{ t('related.form.person') }}</h2>
            </div>
            <div class="card-body">
                <div class="row">
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('clients.form.firstName')"
                        :error="form.errors.value.first_name"
                    >
                        <input
                            :id="id"
                            v-model="form.data.first_name"
                            v-bind="aria"
                            class="input"
                            autocomplete="off"
                            required
                        />
                    </FormField>
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('clients.form.lastName')"
                        :error="form.errors.value.last_name"
                    >
                        <input :id="id" v-model="form.data.last_name" v-bind="aria" class="input" autocomplete="off" />
                    </FormField>
                </div>
                <div class="row">
                    <FormField v-slot="{ id, aria }" :label="t('clients.form.email')" :error="form.errors.value.email">
                        <input
                            :id="id"
                            v-model="form.data.email"
                            v-bind="aria"
                            class="input"
                            type="email"
                            autocomplete="off"
                        />
                    </FormField>
                    <FormField v-slot="{ id, aria }" :label="t('clients.form.phone')" :error="form.errors.value.phone">
                        <PhoneInput
                            v-model="form.data.phone"
                            :input-id="id"
                            :aria="aria"
                            :countries="reference.data?.countries ?? []"
                            :default-country="auth.workspace?.country_code"
                        />
                    </FormField>
                </div>
                <div v-if="!personId" class="row">
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('related.form.relationship', { name: clientName })"
                        :error="form.errors.value.relationship_type"
                        :hint="t('related.form.relationshipHint')"
                    >
                        <select :id="id" v-model="form.data.relationship_type" v-bind="aria" class="input">
                            <option v-for="type in reference.data?.relationship_types ?? []" :key="type" :value="type">
                                {{ labels.relationship(type) }}
                            </option>
                        </select>
                    </FormField>
                    <FormField v-slot="{ id, aria }" :label="t('related.form.notes')" :error="form.errors.value.notes">
                        <input :id="id" v-model="form.data.notes" v-bind="aria" class="input" autocomplete="off" />
                    </FormField>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <h2>{{ t('clients.birth.title') }}</h2>
            </div>
            <div class="card-body">
                <BirthDataFields
                    v-model="birth"
                    :errors="form.errors.value"
                    :countries="(reference.data?.countries ?? []).map((country) => country.code)"
                />
            </div>
        </section>

        <div class="flex gap-2">
            <button class="btn btn-primary" type="submit" :disabled="form.processing.value">
                {{ t('related.form.save') }}
            </button>
            <RouterLink :to="cancelRoute" class="btn btn-ghost">{{ t('related.form.cancel') }}</RouterLink>
        </div>
    </form>
    <p v-else class="text-ink-3">{{ t('common.loading') }}</p>
</template>
