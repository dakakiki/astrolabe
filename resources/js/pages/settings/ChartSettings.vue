<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

import FormField from '@/components/FormField.vue';
import { useForm } from '@/composables/useForm';
import { useLabels } from '@/composables/useLabels';
import http from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useReferenceStore } from '@/stores/reference';
import { useToastStore } from '@/stores/toast';

const { t } = useI18n();
const auth = useAuthStore();
const reference = useReferenceStore();
const toast = useToastStore();
const labels = useLabels();

onMounted(() => reference.load());

/* ---------------- Chart defaults ---------------- */

const defaults = useForm({
    default_house_system: auth.workspace.default_house_system,
    default_zodiac_mode: auth.workspace.default_zodiac_mode,
    default_ayanamsa: auth.workspace.default_ayanamsa,
});

const isSidereal = computed(() => defaults.data.default_zodiac_mode === 'sidereal');

watch(isSidereal, (sidereal) => {
    if (!sidereal) defaults.data.default_ayanamsa = null;
    else defaults.data.default_ayanamsa ??= 'lahiri';
});

async function saveDefaults() {
    await defaults
        .submit(async (data) => {
            const response = await http.patch('/workspace', data);
            auth.workspace = response.data.data;
            toast.success(t('settings.chart.saved'));
        })
        .catch(() => {});
}

/* ---------------- Methods ---------------- */

const methods = ref([]);
const selected = ref([]);
const defaultId = ref(null);

async function loadMethods() {
    const { data } = await http.get('/astrology-methods');
    methods.value = data.data;
    selected.value = data.data.filter((m) => m.selected).map((m) => m.id);
    defaultId.value = data.data.find((m) => m.is_default)?.id ?? null;
}
onMounted(loadMethods);

// The default must stay among the selected methods.
watch(selected, (ids) => {
    if (defaultId.value !== null && !ids.includes(defaultId.value)) defaultId.value = null;
});

const selection = useForm({});

async function saveMethods() {
    await selection
        .submit(async () => {
            await http.put('/workspace/astrology-methods', { method_ids: selected.value, default_id: defaultId.value });
            toast.success(t('settings.methods.saved'));
        })
        .catch(() => {});
}

function suggestion(method) {
    const parts = [
        method.suggested_zodiac_mode && labels.zodiacMode(method.suggested_zodiac_mode),
        method.suggested_ayanamsa && labels.ayanamsa(method.suggested_ayanamsa),
        method.suggested_house_system && labels.houseSystem(method.suggested_house_system),
    ].filter(Boolean);

    return parts.length ? parts.join(' · ') : null;
}

function applySuggestion(method) {
    if (method.suggested_zodiac_mode) defaults.data.default_zodiac_mode = method.suggested_zodiac_mode;
    if (method.suggested_house_system) defaults.data.default_house_system = method.suggested_house_system;
    if (method.suggested_ayanamsa) {
        // Set after the zodiac watcher has run, so it is not reset to the fallback.
        queueMicrotask(() => (defaults.data.default_ayanamsa = method.suggested_ayanamsa));
    }
    toast.success(t('settings.methods.applied', { method: labels.method(method) }));
}

const addForm = useForm({ name: '' });

async function addMethod() {
    await addForm
        .submit(async (data) => {
            const response = await http.post('/astrology-methods', data);
            methods.value.push(response.data.data);
            addForm.reset({ name: '' });
            toast.success(t('settings.methods.added'));
        })
        .catch(() => {});
}

async function deleteMethod(method) {
    if (!window.confirm(t('settings.methods.deleteConfirm', { name: method.name }))) return;

    try {
        await http.delete(`/astrology-methods/${method.id}`);
    } catch {
        toast.error(t('errors.generic'));
        return;
    }
    methods.value = methods.value.filter((m) => m.id !== method.id);
    selected.value = selected.value.filter((id) => id !== method.id);
    toast.success(t('settings.methods.deleted'));
}
</script>

<template>
    <div v-if="!auth.isOwner" class="notice n-neutral">{{ t('settings.ownerOnly') }}</div>

    <section class="card">
        <div class="card-head">
            <h2>{{ t('settings.chart.title') }}</h2>
        </div>
        <form class="card-body" novalidate @submit.prevent="saveDefaults">
            <fieldset :disabled="!auth.isOwner">
                <div class="notice n-neutral mb-4">{{ t('settings.chart.intro') }}</div>
                <div class="row">
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('settings.chart.houseSystem')"
                        :error="defaults.errors.value.default_house_system"
                    >
                        <select :id="id" v-model="defaults.data.default_house_system" v-bind="aria" class="input">
                            <option v-for="value in reference.data?.house_systems ?? []" :key="value" :value="value">
                                {{ labels.houseSystem(value) }}
                            </option>
                        </select>
                    </FormField>
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('settings.chart.zodiac')"
                        :error="defaults.errors.value.default_zodiac_mode"
                    >
                        <select :id="id" v-model="defaults.data.default_zodiac_mode" v-bind="aria" class="input">
                            <option v-for="value in reference.data?.zodiac_modes ?? []" :key="value" :value="value">
                                {{ labels.zodiacMode(value) }}
                            </option>
                        </select>
                    </FormField>
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('settings.chart.ayanamsa')"
                        :error="defaults.errors.value.default_ayanamsa"
                        :hint="t('settings.chart.ayanamsaHint')"
                    >
                        <select
                            :id="id"
                            v-model="defaults.data.default_ayanamsa"
                            v-bind="aria"
                            class="input"
                            :disabled="!isSidereal"
                        >
                            <option v-if="!isSidereal" :value="null">—</option>
                            <option v-for="value in reference.data?.ayanamsas ?? []" :key="value" :value="value">
                                {{ labels.ayanamsa(value) }}
                            </option>
                        </select>
                    </FormField>
                </div>
                <button class="btn btn-primary" type="submit" :disabled="defaults.processing.value">
                    {{ t('common.save') }}
                </button>
            </fieldset>
        </form>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>{{ t('settings.methods.title') }}</h2>
        </div>
        <div class="card-body">
            <p class="mb-3 text-ink-3">{{ t('settings.methods.intro') }}</p>

            <fieldset :disabled="!auth.isOwner">
                <legend class="sr-only">{{ t('settings.methods.title') }}</legend>
                <ul class="divide-y divide-line-soft">
                    <li
                        v-for="method in methods"
                        :key="method.id"
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2"
                    >
                        <label class="flex min-w-0 flex-1 items-center gap-2">
                            <input v-model="selected" type="checkbox" :value="method.id" />
                            <span>{{ labels.method(method) }}</span>
                            <span v-if="!method.is_system" class="tag">{{ t('settings.methods.custom') }}</span>
                        </label>
                        <span v-if="suggestion(method)" class="text-xs text-ink-3">
                            {{ t('settings.methods.suggests', { value: suggestion(method) }) }}
                        </span>
                        <button
                            v-if="suggestion(method) && auth.isOwner"
                            type="button"
                            class="btn btn-ghost btn-sm"
                            @click="applySuggestion(method)"
                        >
                            {{ t('settings.methods.applySuggestion') }}
                        </button>
                        <label v-if="selected.includes(method.id)" class="flex items-center gap-1 text-xs text-ink-2">
                            <input v-model="defaultId" type="radio" name="default-method" :value="method.id" />
                            {{ t('settings.methods.default') }}
                        </label>
                        <button
                            v-if="!method.is_system"
                            type="button"
                            class="btn btn-ghost btn-sm text-danger"
                            @click="deleteMethod(method)"
                        >
                            {{ t('common.delete') }}
                        </button>
                    </li>
                </ul>

                <div v-if="selection.errors.value.default_id" class="mt-2 text-xs text-danger">
                    {{ selection.errors.value.default_id }}
                </div>

                <button
                    class="btn btn-primary mt-4"
                    type="button"
                    :disabled="selection.processing.value"
                    @click="saveMethods"
                >
                    {{ t('common.save') }}
                </button>

                <div class="divider" />

                <form class="flex items-end gap-2" novalidate @submit.prevent="addMethod">
                    <div class="flex-1">
                        <FormField
                            v-slot="{ id, aria }"
                            :label="t('settings.methods.addLabel')"
                            :error="addForm.errors.value.name"
                        >
                            <input
                                :id="id"
                                v-model="addForm.data.name"
                                v-bind="aria"
                                class="input"
                                :placeholder="t('settings.methods.addPlaceholder')"
                            />
                        </FormField>
                    </div>
                    <button class="btn mb-3.5" type="submit" :disabled="addForm.processing.value || !addForm.data.name">
                        {{ t('common.add') }}
                    </button>
                </form>
            </fieldset>
        </div>
    </section>
</template>
