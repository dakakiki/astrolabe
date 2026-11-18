<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import AppIcon from '@/components/AppIcon.vue';
import FormField from '@/components/FormField.vue';
import { useForm } from '@/composables/useForm';
import { useLabels } from '@/composables/useLabels';
import http from '@/lib/http';
import { fromMinorUnits, toMinorUnits } from '@/lib/money';
import { serviceColorClass } from '@/lib/services';
import { useAuthStore } from '@/stores/auth';
import { useReferenceStore } from '@/stores/reference';
import { useToastStore } from '@/stores/toast';

/**
 * Adding or editing a service on its own page (/services/new, /services/{id}/edit): the list
 * stays on /services, so nothing above or below the form changes out of sight. The owner manages
 * services; a service in use is deactivated, not deleted (docs/spec/02, "Usluge").
 */
const { t } = useI18n();
const labels = useLabels();
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const reference = useReferenceStore();
const toast = useToastStore();

const serviceId = computed(() => (route.params.id ? Number(route.params.id) : null));
const service = ref(null);
const loaded = ref(false);
const notFound = ref(false);
const methods = ref([]);
const showAllMethods = ref(false);

const blank = () => ({
    name: '',
    description: '',
    duration_minutes: 60,
    price: '',
    currency: auth.workspace?.default_currency ?? 'EUR',
    location_type: 'online',
    color: 'indigo',
    requires_deposit: false,
    is_active: true,
    bookable_in_portal: false,
    method_ids: [],
});
const form = useForm(blank());
const priceError = ref(null);

const decimals = (currency) => reference.data?.currency_decimals?.[currency] ?? 2;

const visibleMethods = computed(() =>
    methods.value.filter(
        (method) => showAllMethods.value || method.selected || form.data.method_ids.includes(method.id),
    ),
);

onMounted(async () => {
    try {
        const [, methodResponse, serviceResponse] = await Promise.all([
            reference.load(),
            http.get('/astrology-methods'),
            serviceId.value ? http.get(`/services/${serviceId.value}`) : null,
        ]);
        methods.value = methodResponse.data.data;

        if (serviceResponse) {
            service.value = serviceResponse.data.data;
            const s = service.value;
            form.reset({
                name: s.name,
                description: s.description ?? '',
                duration_minutes: s.duration_minutes,
                price: fromMinorUnits(s.price?.amount, decimals(s.currency)),
                currency: s.currency,
                location_type: s.location_type,
                color: s.color,
                requires_deposit: s.requires_deposit,
                is_active: s.is_active,
                bookable_in_portal: Boolean(s.bookable_in_portal),
                method_ids: (s.methods ?? []).map((method) => method.id),
            });
        }
        loaded.value = true;
    } catch (error) {
        if (error.response?.status === 404) notFound.value = true;
        else throw error;
    }
});

function toggleMethod(id, checked) {
    form.data.method_ids = checked ? [...form.data.method_ids, id] : form.data.method_ids.filter((m) => m !== id);
}

async function save() {
    const amount = toMinorUnits(form.data.price, decimals(form.data.currency));
    priceError.value = Number.isNaN(amount) ? t('services.form.priceInvalid') : null;
    if (priceError.value) return;

    const creating = !serviceId.value;

    try {
        await form.submit((data) => {
            const payload = {
                name: data.name,
                description: data.description || null,
                duration_minutes: data.duration_minutes || null,
                price: amount === null ? null : { amount, currency: data.currency },
                location_type: data.location_type,
                color: data.color,
                requires_deposit: data.requires_deposit,
                is_active: data.is_active,
                bookable_in_portal: data.bookable_in_portal,
                method_ids: data.method_ids,
            };

            return creating ? http.post('/services', payload) : http.patch(`/services/${serviceId.value}`, payload);
        });

        toast.success(creating ? t('services.created') : t('services.saved'));
        router.push({ name: 'services.index' });
    } catch {
        // Field errors are shown next to the fields; other failures as a toast.
    }
}

async function remove() {
    if (!window.confirm(t('services.confirmDelete', { name: service.value.name }))) return;

    try {
        await http.delete(`/services/${service.value.id}`);
        toast.success(t('services.deleted'));
        router.push({ name: 'services.index' });
    } catch (error) {
        toast.error(error.response?.status === 409 ? t('services.form.inUse') : t('errors.generic'));
    }
}
</script>

<template>
    <div class="page-head">
        <nav class="eyebrow crumbs" :aria-label="t('common.breadcrumb')">
            <RouterLink :to="{ name: 'services.index' }">{{ t('services.title') }}</RouterLink>
        </nav>
        <h1>
            {{ serviceId ? t('services.form.editTitle', { name: service?.name ?? '' }) : t('services.form.newTitle') }}
        </h1>
    </div>

    <div v-if="!auth.isOwner" class="notice n-neutral">{{ t('services.ownerOnly') }}</div>
    <p v-else-if="notFound" class="text-ink-3">{{ t('notFound.title') }}</p>

    <div v-else-if="loaded" class="max-w-4xl space-y-4">
        <form class="card" novalidate @submit.prevent="save">
            <div class="card-body">
                <div class="row">
                    <FormField v-slot="{ id, aria }" :label="t('services.form.name')" :error="form.errors.value.name">
                        <input
                            :id="id"
                            v-model="form.data.name"
                            v-bind="aria"
                            class="input"
                            :placeholder="t('services.form.namePlaceholder')"
                            autocomplete="off"
                            required
                        />
                    </FormField>
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('services.form.duration')"
                        :error="form.errors.value.duration_minutes"
                    >
                        <input
                            :id="id"
                            v-model.number="form.data.duration_minutes"
                            v-bind="aria"
                            class="input"
                            type="number"
                            min="1"
                            :max="reference.data?.services?.max_duration ?? 1440"
                            step="5"
                            required
                        />
                    </FormField>
                </div>

                <FormField
                    v-slot="{ id, aria }"
                    :label="t('services.form.description')"
                    :error="form.errors.value.description"
                >
                    <textarea :id="id" v-model="form.data.description" v-bind="aria" class="input min-h-16" rows="2" />
                </FormField>

                <div class="row">
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('services.form.price')"
                        :error="priceError || form.errors.value['price.amount']"
                        :hint="t('services.form.priceHint')"
                    >
                        <input
                            :id="id"
                            v-model="form.data.price"
                            v-bind="aria"
                            class="input font-mono"
                            inputmode="decimal"
                            autocomplete="off"
                        />
                    </FormField>
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('services.form.currency')"
                        :error="form.errors.value['price.currency']"
                    >
                        <select :id="id" v-model="form.data.currency" v-bind="aria" class="input">
                            <option v-for="code in reference.data?.currencies ?? []" :key="code" :value="code">
                                {{ labels.currency(code) }}
                            </option>
                        </select>
                    </FormField>
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('services.form.held')"
                        :error="form.errors.value.location_type"
                    >
                        <select :id="id" v-model="form.data.location_type" v-bind="aria" class="input">
                            <option
                                v-for="type in reference.data?.services?.location_types ?? []"
                                :key="type"
                                :value="type"
                            >
                                {{ labels.locationType(type) }}
                            </option>
                        </select>
                    </FormField>
                </div>

                <fieldset class="field">
                    <legend class="label">{{ t('services.form.color') }}</legend>
                    <div class="flex flex-wrap gap-x-4 gap-y-2">
                        <label
                            v-for="color in reference.data?.services?.colors ?? []"
                            :key="color"
                            class="flex items-center gap-1.5 text-sm"
                        >
                            <input v-model="form.data.color" type="radio" name="service-color" :value="color" />
                            <span
                                class="inline-block size-3 rounded-sm"
                                :class="serviceColorClass(color)"
                                aria-hidden="true"
                            />
                            {{ labels.serviceColor(color) }}
                        </label>
                    </div>
                    <div v-if="form.errors.value.color" class="error">{{ form.errors.value.color }}</div>
                </fieldset>

                <fieldset class="field">
                    <legend class="label">{{ t('services.form.methods') }}</legend>
                    <div class="flex flex-wrap items-center gap-2">
                        <label
                            v-for="method in visibleMethods"
                            :key="method.id"
                            class="flex items-center gap-1.5 rounded-sm border border-line px-2 py-1 text-xs"
                        >
                            <input
                                type="checkbox"
                                :checked="form.data.method_ids.includes(method.id)"
                                @change="toggleMethod(method.id, $event.target.checked)"
                            />
                            {{ labels.method(method) }}
                        </label>
                        <button
                            v-if="!showAllMethods"
                            type="button"
                            class="btn btn-ghost btn-sm"
                            @click="showAllMethods = true"
                            v-tip="t('tips.clients.form.showAllMethods')"
                        >
                            <AppIcon name="more" />
                            {{ t('clients.form.showAllMethods') }}
                        </button>
                    </div>
                    <div class="hint">{{ t('services.form.methodsHint') }}</div>
                </fieldset>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex items-start gap-2">
                        <input v-model="form.data.requires_deposit" type="checkbox" class="mt-1" />
                        <span>
                            <span class="block font-medium">{{ t('services.form.deposit') }}</span>
                            <span class="block text-xs text-ink-3">{{ t('services.form.depositHint') }}</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input v-model="form.data.is_active" type="checkbox" class="mt-1" />
                        <span>
                            <span class="block font-medium">{{ t('services.form.active') }}</span>
                            <span class="block text-xs text-ink-3">{{ t('services.form.activeHint') }}</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input v-model="form.data.bookable_in_portal" type="checkbox" class="mt-1" />
                        <span>
                            <span class="block font-medium">{{ t('services.form.bookable') }}</span>
                            <span class="block text-xs text-ink-3">{{ t('services.form.bookableHint') }}</span>
                        </span>
                    </label>
                </div>
                <div v-if="form.errors.value.bookable_in_portal" class="mt-1 text-xs text-danger">
                    {{ form.errors.value.bookable_in_portal }}
                </div>

                <div class="form-actions mt-5">
                    <RouterLink
                        :to="{ name: 'services.index' }"
                        class="btn btn-ghost form-cancel"
                        v-tip="t('tips.services.form.cancel')"
                        ><AppIcon name="cancel" />{{ t('services.form.cancel') }}</RouterLink
                    >
                    <button
                        class="btn btn-primary"
                        type="submit"
                        :disabled="form.processing.value"
                        v-tip="serviceId ? t('tips.services.form.save') : t('tips.services.form.create')"
                    >
                        <AppIcon name="save" />
                        {{ serviceId ? t('services.form.save') : t('services.form.create') }}
                    </button>
                </div>
            </div>
        </form>

        <!-- Deleting is not next to Save: its own card at the end, red, with what it does. -->
        <section v-if="service" class="card">
            <div class="card-body manage-actions">
                <p v-if="service.in_use" class="mt-0!">{{ t('services.form.inUse') }}</p>
                <div v-else>
                    <button
                        type="button"
                        class="btn btn-sm btn-danger"
                        @click="remove"
                        v-tip="t('tips.services.form.delete')"
                    >
                        <AppIcon name="delete" />
                        {{ t('services.form.delete') }}
                    </button>
                    <p>{{ t('services.form.deleteHint') }}</p>
                </div>
            </div>
        </section>
    </div>

    <p v-else class="text-ink-3">{{ t('common.loading') }}</p>
</template>
