<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import FormField from '@/components/FormField.vue';
import { useForm } from '@/composables/useForm';
import { useLabels } from '@/composables/useLabels';
import http from '@/lib/http';
import { formatMoney, fromMinorUnits, toMinorUnits } from '@/lib/money';
import { serviceColorClass } from '@/lib/services';
import { useAuthStore } from '@/stores/auth';
import { useReferenceStore } from '@/stores/reference';
import { useToastStore } from '@/stores/toast';

/**
 * The practice's services (docs/spec/02, "Usluge"): what is offered, how long
 * it takes, what it costs and where it is held. Everyone sees them; the owner
 * adds and edits them in a form above the list. A service in use is
 * deactivated, not deleted, so past consultations keep it.
 */
const { t, locale } = useI18n();
const labels = useLabels();
const auth = useAuthStore();
const reference = useReferenceStore();
const toast = useToastStore();

const services = ref(null);
const methods = ref([]);
const showAllMethods = ref(false);
// null: the form is closed; 'new'; or the service being edited.
const editing = ref(null);
const formCard = ref(null);

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
    method_ids: [],
});
const form = useForm(blank());
const priceError = ref(null);

const decimals = (currency) => reference.data?.currency_decimals?.[currency] ?? 2;
const price = (service) => formatMoney(service.price, locale.value, decimals(service.price?.currency));

const visibleMethods = computed(() =>
    methods.value.filter(
        (method) => showAllMethods.value || method.selected || form.data.method_ids.includes(method.id),
    ),
);

async function load() {
    const { data } = await http.get('/services');
    services.value = data.data;
}

onMounted(async () => {
    const [, , methodResponse] = await Promise.all([reference.load(), load(), http.get('/astrology-methods')]);
    methods.value = methodResponse.data.data;
});

async function open(service = null) {
    if (!auth.isOwner) return;

    editing.value = service ?? 'new';
    priceError.value = null;
    showAllMethods.value = false;
    form.reset(
        service
            ? {
                  name: service.name,
                  description: service.description ?? '',
                  duration_minutes: service.duration_minutes,
                  price: fromMinorUnits(service.price?.amount, decimals(service.currency)),
                  currency: service.currency,
                  location_type: service.location_type,
                  color: service.color,
                  requires_deposit: service.requires_deposit,
                  is_active: service.is_active,
                  method_ids: (service.methods ?? []).map((method) => method.id),
              }
            : blank(),
    );

    await nextTick();
    formCard.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    formCard.value?.querySelector('input')?.focus({ preventScroll: true });
}

function close() {
    editing.value = null;
}

function toggleMethod(id, checked) {
    form.data.method_ids = checked ? [...form.data.method_ids, id] : form.data.method_ids.filter((m) => m !== id);
}

async function save() {
    const amount = toMinorUnits(form.data.price, decimals(form.data.currency));
    priceError.value = Number.isNaN(amount) ? t('services.form.priceInvalid') : null;
    if (priceError.value) return;

    const creating = editing.value === 'new';

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
                method_ids: data.method_ids,
            };

            return creating ? http.post('/services', payload) : http.patch(`/services/${editing.value.id}`, payload);
        });

        await load();
        close();
        toast.success(creating ? t('services.created') : t('services.saved'));
    } catch {
        // Field errors are shown next to the fields; other failures as a toast.
    }
}

async function remove() {
    const service = editing.value;
    if (!window.confirm(t('services.confirmDelete', { name: service.name }))) return;

    try {
        await http.delete(`/services/${service.id}`);
        await load();
        close();
        toast.success(t('services.deleted'));
    } catch (error) {
        toast.error(error.response?.status === 409 ? t('services.form.inUse') : t('errors.generic'));
    }
}
</script>

<template>
    <div class="page-head flex flex-wrap items-end gap-4">
        <div>
            <div class="eyebrow">{{ t('services.eyebrow') }}</div>
            <h1>{{ t('services.title') }}</h1>
            <div class="sub">{{ t('services.sub') }}</div>
        </div>
        <button v-if="auth.isOwner" type="button" class="btn btn-primary ml-auto" @click="open()">
            + {{ t('services.add') }}
        </button>
    </div>

    <div v-if="!auth.isOwner" class="notice n-neutral mb-4">{{ t('services.ownerOnly') }}</div>

    <section v-if="editing" ref="formCard" class="card mb-4 max-w-4xl scroll-mt-4">
        <div class="card-head">
            <h2>
                {{
                    editing === 'new'
                        ? t('services.form.newTitle')
                        : t('services.form.editTitle', { name: editing.name })
                }}
            </h2>
        </div>
        <form class="card-body" novalidate @submit.prevent="save">
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
                    >
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
            </div>

            <div class="mt-5 flex flex-wrap items-center gap-2">
                <button class="btn btn-primary" type="submit" :disabled="form.processing.value">
                    {{ editing === 'new' ? t('services.form.create') : t('services.form.save') }}
                </button>
                <button type="button" class="btn btn-ghost" @click="close">{{ t('services.form.cancel') }}</button>
                <template v-if="editing !== 'new'">
                    <span v-if="editing.in_use" class="ml-auto text-xs text-ink-3">{{ t('services.form.inUse') }}</span>
                    <button v-else type="button" class="btn btn-ghost ml-auto" @click="remove">
                        {{ t('services.form.delete') }}
                    </button>
                </template>
            </div>
        </form>
    </section>

    <section class="card">
        <div class="overflow-x-auto">
            <table v-if="services?.length" class="data">
                <thead>
                    <tr>
                        <th>
                            <span class="sr-only">{{ t('services.form.color') }}</span>
                        </th>
                        <th>{{ t('services.columns.name') }}</th>
                        <th class="text-right!">{{ t('services.columns.duration') }}</th>
                        <th class="text-right!">{{ t('services.columns.price') }}</th>
                        <th>{{ t('services.columns.held') }}</th>
                        <th>{{ t('services.columns.deposit') }}</th>
                        <th>{{ t('services.columns.methods') }}</th>
                        <th>{{ t('services.columns.status') }}</th>
                        <th v-if="auth.isOwner">
                            <span class="sr-only">{{ t('services.edit') }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="service in services"
                        :key="service.id"
                        :class="{ 'cursor-default!': !auth.isOwner, 'opacity-70': !service.is_active }"
                        @click="open(service)"
                    >
                        <td class="w-6">
                            <span
                                class="inline-block size-2.5 rounded-sm"
                                :class="serviceColorClass(service.color)"
                                :title="labels.serviceColor(service.color)"
                            />
                        </td>
                        <td class="max-w-80">
                            <div class="font-medium text-ink">{{ service.name }}</div>
                            <div v-if="service.description" class="truncate text-xs text-ink-3">
                                {{ service.description }}
                            </div>
                        </td>
                        <td class="text-right whitespace-nowrap">
                            {{ t('services.minutes', { n: service.duration_minutes }) }}
                        </td>
                        <td class="text-right font-mono whitespace-nowrap">
                            <template v-if="service.price">{{ price(service) }}</template>
                            <span v-else class="text-ink-4">—</span>
                        </td>
                        <td class="text-xs whitespace-nowrap text-ink-2">
                            {{ labels.locationType(service.location_type) }}
                        </td>
                        <td>
                            <span v-if="service.requires_deposit">{{ t('services.yes') }}</span>
                            <span v-else class="text-ink-4">{{ t('services.no') }}</span>
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-1">
                                <span v-for="method in service.methods" :key="method.id" class="method-pill">{{
                                    labels.method(method)
                                }}</span>
                                <span v-if="!service.methods?.length" class="text-ink-4">{{
                                    t('services.anyMethod')
                                }}</span>
                            </div>
                        </td>
                        <td>
                            <span v-if="service.is_active" class="badge b-ok">{{ t('services.active') }}</span>
                            <span v-else class="badge">{{ t('services.inactive') }}</span>
                        </td>
                        <td v-if="auth.isOwner" class="text-right">
                            <button type="button" class="btn btn-sm" @click.stop="open(service)">
                                {{ t('services.edit') }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else-if="services" class="empty">{{ t('services.empty') }}</p>
            <p v-else class="p-8 text-center text-ink-3">{{ t('common.loading') }}</p>
        </div>
    </section>
</template>
