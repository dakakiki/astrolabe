<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import BirthDataFields from '@/components/BirthDataFields.vue';
import FormField from '@/components/FormField.vue';
import PhoneInput from '@/components/PhoneInput.vue';
import TagInput from '@/components/TagInput.vue';
import { useForm } from '@/composables/useForm';
import { CLIENT_LANGUAGES, timeZoneOptions, useLabels } from '@/composables/useLabels';
import { birthFromApi, birthToPayload, emptyBirth, hasBirthInput } from '@/lib/birth';
import http from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useReferenceStore } from '@/stores/reference';
import { useToastStore } from '@/stores/toast';

const { t } = useI18n();
const labels = useLabels();
const route = useRoute();
const router = useRouter();
const reference = useReferenceStore();
const auth = useAuthStore();
const toast = useToastStore();

const clientId = computed(() => route.params.id ?? null);
const loaded = ref(!clientId.value);
const clientName = ref('');

const form = useForm({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    country_code: '',
    timezone: '',
    preferred_locale: '',
    status: 'active',
    internal_notes: '',
    tags: [],
    method_ids: [],
    default_method_id: null,
});
const birth = ref(emptyBirth());

const methods = ref([]);
const showAllMethods = ref(false);
const tagSuggestions = ref([]);

const visibleMethods = computed(() =>
    methods.value.filter(
        (method) => showAllMethods.value || method.selected || form.data.method_ids.includes(method.id),
    ),
);
const sortedCountries = computed(() =>
    (reference.data?.countries ?? [])
        .map((country) => country.code)
        .sort((a, b) => labels.country(a).localeCompare(labels.country(b))),
);
const zones = computed(() => timeZoneOptions(form.data.timezone));
const languages = computed(() =>
    [...CLIENT_LANGUAGES].sort((a, b) => labels.languageName(a).localeCompare(labels.languageName(b))),
);

onMounted(async () => {
    const requests = [reference.load(), http.get('/astrology-methods'), http.get('/tags')];
    if (clientId.value) requests.push(http.get(`/clients/${clientId.value}`));

    const [, methodResponse, tagResponse, clientResponse] = await Promise.all(requests);
    methods.value = methodResponse.data.data;
    tagSuggestions.value = tagResponse.data.data.map((tag) => tag.name);
    showAllMethods.value = !methods.value.some((method) => method.selected);

    if (clientResponse) {
        const client = clientResponse.data.data;
        clientName.value = client.full_name;
        form.reset({
            ...form.data,
            ...Object.fromEntries(Object.keys(form.data).map((key) => [key, client[key] ?? form.data[key]])),
            tags: client.tags,
            method_ids: client.methods.map((method) => method.id),
            default_method_id: client.methods.find((method) => method.is_default)?.id ?? null,
        });
        birth.value = birthFromApi(client.birth);
        loaded.value = true;
    } else {
        // New clients start with the practice's default method, if one is set.
        const defaultMethod = methods.value.find((method) => method.is_default);
        if (defaultMethod) {
            form.data.method_ids = [defaultMethod.id];
            form.data.default_method_id = defaultMethod.id;
        }
    }
});

function toggleMethod(id, checked) {
    form.data.method_ids = checked ? [...form.data.method_ids, id] : form.data.method_ids.filter((m) => m !== id);
    if (!form.data.method_ids.includes(form.data.default_method_id)) form.data.default_method_id = null;
}

async function save() {
    const nullable = (value) => (value === '' ? null : value);

    try {
        const response = await form.submit((data) => {
            const payload = {
                ...Object.fromEntries(Object.entries(data).map(([key, value]) => [key, nullable(value)])),
                tags: data.tags,
                method_ids: data.method_ids,
            };
            if (hasBirthInput(birth.value)) payload.birth = birthToPayload(birth.value);

            return clientId.value ? http.patch(`/clients/${clientId.value}`, payload) : http.post('/clients', payload);
        });

        toast.success(clientId.value ? t('clients.saved') : t('clients.created'));
        router.push({ name: 'clients.show', params: { id: response.data.data.id } });
    } catch {
        // Field errors are shown next to the fields; other failures as a toast.
    }
}
</script>

<template>
    <div class="page-head">
        <div class="eyebrow">{{ t('clients.title') }}</div>
        <h1>{{ clientId ? t('clients.form.editTitle', { name: clientName }) : t('clients.form.newTitle') }}</h1>
    </div>

    <form v-if="loaded" class="max-w-4xl space-y-4" novalidate @submit.prevent="save">
        <section class="card">
            <div class="card-head">
                <h2>{{ t('clients.form.person') }}</h2>
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
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('clients.form.status')"
                        :error="form.errors.value.status"
                    >
                        <select :id="id" v-model="form.data.status" v-bind="aria" class="input">
                            <option
                                v-for="status in ['lead', 'active', 'inactive', ...(clientId ? ['archived'] : [])]"
                                :key="status"
                                :value="status"
                            >
                                {{ labels.status(status) }}
                            </option>
                        </select>
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
                            :default-country="form.data.country_code || auth.workspace?.country_code"
                        />
                    </FormField>
                </div>
                <div class="row">
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('clients.form.country')"
                        :error="form.errors.value.country_code"
                    >
                        <select :id="id" v-model="form.data.country_code" v-bind="aria" class="input">
                            <option value="">{{ t('clients.form.none') }}</option>
                            <option v-for="code in sortedCountries" :key="code" :value="code">
                                {{ labels.country(code) }}
                            </option>
                        </select>
                    </FormField>
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('clients.form.timezone')"
                        :error="form.errors.value.timezone"
                    >
                        <select :id="id" v-model="form.data.timezone" v-bind="aria" class="input">
                            <option value="">{{ t('clients.form.none') }}</option>
                            <option v-for="zone in zones" :key="zone" :value="zone">{{ zone }}</option>
                        </select>
                    </FormField>
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('clients.form.language')"
                        :error="form.errors.value.preferred_locale"
                        :hint="t('clients.form.languageHint')"
                    >
                        <select :id="id" v-model="form.data.preferred_locale" v-bind="aria" class="input">
                            <option value="">{{ t('clients.form.none') }}</option>
                            <option v-for="code in languages" :key="code" :value="code">
                                {{ labels.languageName(code) }}
                            </option>
                        </select>
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

        <section class="card">
            <div class="card-head">
                <h2>{{ t('clients.form.practice') }}</h2>
            </div>
            <div class="card-body">
                <fieldset class="field">
                    <legend class="label">{{ t('clients.form.methods') }}</legend>
                    <ul class="grid gap-x-6 gap-y-1 sm:grid-cols-2">
                        <li v-for="method in visibleMethods" :key="method.id" class="flex items-center gap-2">
                            <label class="flex flex-1 items-center gap-2">
                                <input
                                    type="checkbox"
                                    :checked="form.data.method_ids.includes(method.id)"
                                    @change="toggleMethod(method.id, $event.target.checked)"
                                />
                                {{ labels.method(method) }}
                            </label>
                            <label
                                v-if="form.data.method_ids.includes(method.id) && form.data.method_ids.length > 1"
                                class="flex items-center gap-1 text-xs text-ink-3"
                            >
                                <input
                                    v-model="form.data.default_method_id"
                                    type="radio"
                                    name="client-default-method"
                                    :value="method.id"
                                />
                                {{ t('clients.form.defaultMethod') }}
                            </label>
                        </li>
                    </ul>
                    <button
                        v-if="!showAllMethods"
                        type="button"
                        class="btn btn-ghost btn-sm mt-1"
                        @click="showAllMethods = true"
                    >
                        {{ t('clients.form.showAllMethods') }}
                    </button>
                    <div class="hint">{{ t('clients.form.methodsHint') }}</div>
                    <div v-if="form.errors.value['method_ids.0']" class="error">
                        {{ form.errors.value['method_ids.0'] }}
                    </div>
                </fieldset>

                <FormField v-slot="{ id }" :label="t('clients.form.tags')" :error="form.errors.value.tags">
                    <TagInput :input-id="id" v-model="form.data.tags" :suggestions="tagSuggestions" />
                </FormField>

                <FormField
                    v-slot="{ id, aria }"
                    :label="t('clients.form.notes')"
                    :error="form.errors.value.internal_notes"
                    :hint="t('clients.form.notesHint')"
                >
                    <textarea
                        :id="id"
                        v-model="form.data.internal_notes"
                        v-bind="aria"
                        class="input min-h-28"
                        rows="5"
                    />
                </FormField>
            </div>
        </section>

        <div class="flex gap-2">
            <button class="btn btn-primary" type="submit" :disabled="form.processing.value">
                {{ t('clients.form.save') }}
            </button>
            <RouterLink
                :to="clientId ? { name: 'clients.show', params: { id: clientId } } : { name: 'clients.index' }"
                class="btn btn-ghost"
                >{{ t('clients.form.cancel') }}</RouterLink
            >
        </div>
    </form>
    <p v-else class="text-ink-3">{{ t('common.loading') }}</p>
</template>
