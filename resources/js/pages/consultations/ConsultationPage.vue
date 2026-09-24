<script setup>
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { onBeforeRouteLeave, onBeforeRouteUpdate, RouterLink, useRoute, useRouter } from 'vue-router';

import AttachmentsPanel from '@/components/AttachmentsPanel.vue';
import ClientPicker from '@/components/ClientPicker.vue';
import ConsultationStatusBadge from '@/components/ConsultationStatusBadge.vue';
import FormField from '@/components/FormField.vue';
import NatalChart from '@/components/NatalChart.vue';
import NotesPanel from '@/components/NotesPanel.vue';
import VisibilityBadge from '@/components/VisibilityBadge.vue';
import { useForm } from '@/composables/useForm';
import { timeZoneOptions, useLabels } from '@/composables/useLabels';
import { formatDateTime, localInputNow } from '@/lib/datetime';
import http from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

const RichTextEditor = defineAsyncComponent(() => import('@/components/RichTextEditor.vue'));

/**
 * One consultation: the record of a session (docs/spec/02). Internal notes and
 * the summary for the client are separate fields, each with its own editor.
 * Files and the chart snapshot can be attached once the record exists.
 */
const { t, locale } = useI18n();
const labels = useLabels();
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const toast = useToastStore();

const STATUSES = ['draft', 'scheduled', 'completed', 'cancelled', 'no_show'];

const consultation = ref(null);
const client = ref(null);
const notFound = ref(false);
const loaded = ref(false);
const methods = ref([]);
const showAllMethods = ref(false);
const chartBusy = ref(false);

// Most consultations are recorded after they happened, so a new one starts as completed, now.
const blank = () => ({
    title: '',
    status: 'completed',
    starts_at: '',
    timezone: '',
    duration_minutes: null,
    topics: '',
    internal_notes: '',
    client_summary: '',
    next_steps: '',
    method_ids: [],
});
const form = useForm(blank());

// Unsaved changes are compared against the last saved state.
const saved = ref('');
const snapshot = () => JSON.stringify(form.data);
const dirty = computed(() => loaded.value && snapshot() !== saved.value);

const id = computed(() => consultation.value?.id ?? null);
const zones = computed(() => timeZoneOptions(form.data.timezone));
const visibleMethods = computed(() =>
    methods.value.filter(
        (method) => showAllMethods.value || method.selected || form.data.method_ids.includes(method.id),
    ),
);

function fill(data) {
    consultation.value = data;
    client.value = data.client;
    form.reset({
        title: data.title ?? '',
        status: data.status,
        starts_at: data.starts_at_local ?? '',
        timezone: data.timezone ?? auth.user?.timezone ?? 'UTC',
        duration_minutes: data.duration_minutes,
        topics: data.topics ?? '',
        internal_notes: data.internal_notes ?? '',
        client_summary: data.client_summary ?? '',
        next_steps: data.next_steps ?? '',
        method_ids: (data.methods ?? []).map((method) => method.id),
    });
    saved.value = snapshot();
}

async function load(consultationId) {
    loaded.value = false;
    notFound.value = false;

    if (consultationId) {
        try {
            const { data } = await http.get(`/consultations/${consultationId}`);
            fill(data.data);
        } catch (error) {
            if (error.response?.status === 404) notFound.value = true;
            else throw error;
        }
    } else {
        consultation.value = null;
        client.value = null;
        const zone = auth.user?.timezone ?? 'UTC';
        form.reset({ ...blank(), timezone: zone, starts_at: localInputNow(zone) });

        if (route.query.client) {
            const { data } = await http.get(`/clients/${route.query.client}`);
            selectClient(data.data);
        }
        saved.value = snapshot();
    }

    loaded.value = true;
}

onMounted(async () => {
    const { data } = await http.get('/astrology-methods');
    methods.value = data.data;
    await load(route.params.id);
});

// Following a link from one consultation to another reuses this page.
watch(
    () => route.params.id,
    (next) => {
        if (String(next ?? '') !== String(id.value ?? '')) load(next);
    },
);

function selectClient(chosen) {
    client.value = { id: chosen.id, full_name: chosen.full_name };
    // A new consultation starts with the client's default method, if one is set.
    form.data.method_ids = (chosen.methods ?? []).filter((method) => method.is_default).map((method) => method.id);
}

function toggleMethod(methodId, checked) {
    form.data.method_ids = checked
        ? [...form.data.method_ids, methodId]
        : form.data.method_ids.filter((existing) => existing !== methodId);
}

async function save() {
    const creating = !id.value;

    try {
        const response = await form.submit((data) => {
            const payload = {
                ...data,
                duration_minutes: data.duration_minutes || null,
                starts_at: data.starts_at || null,
            };

            return creating
                ? http.post('/consultations', { ...payload, client_id: client.value?.id ?? null })
                : http.patch(`/consultations/${id.value}`, payload);
        });

        fill(response.data.data);
        toast.success(creating ? t('consultations.created') : t('consultations.saved'));

        if (creating) {
            router.replace({ name: 'consultations.show', params: { id: response.data.data.id } });
        }
    } catch {
        // Field errors are shown next to the fields; other failures as a toast.
    }
}

async function remove() {
    if (!window.confirm(t('consultations.confirmDelete'))) return;

    await http.delete(`/consultations/${id.value}`);
    saved.value = snapshot();
    toast.success(t('consultations.deleted'));
    router.push({ name: 'clients.show', params: { id: client.value.id }, query: { tab: 'consultations' } });
}

async function attachChart() {
    chartBusy.value = true;
    try {
        const { data } = await http.post(`/consultations/${id.value}/chart`);
        consultation.value = { ...consultation.value, chart: data.data.chart, has_chart: true };
        toast.success(t('consultations.chart.attached'));
    } catch (error) {
        const status = error.response?.status;
        toast.error(
            status === 422
                ? t('consultations.chart.incomplete')
                : status === 503
                  ? t('chart.unavailable')
                  : t('errors.generic'),
        );
    } finally {
        chartBusy.value = false;
    }
}

async function detachChart() {
    chartBusy.value = true;
    try {
        await http.delete(`/consultations/${id.value}/chart`);
        consultation.value = { ...consultation.value, chart: null, has_chart: false };
        toast.success(t('consultations.chart.detached'));
    } finally {
        chartBusy.value = false;
    }
}

// Long notes are expensive to lose: warn before leaving with unsaved changes,
// also when a link leads straight to another consultation.
const confirmLeaving = () => {
    if (dirty.value && !window.confirm(t('consultations.unsaved'))) return false;
};
onBeforeRouteLeave(confirmLeaving);
onBeforeRouteUpdate(confirmLeaving);

function warnBeforeUnload(event) {
    if (dirty.value) event.preventDefault();
}
onMounted(() => window.addEventListener('beforeunload', warnBeforeUnload));
onBeforeUnmount(() => window.removeEventListener('beforeunload', warnBeforeUnload));

const heading = computed(() =>
    id.value
        ? form.data.title || consultation.value?.title || t('consultations.untitled')
        : t('consultations.form.newTitle'),
);
const when = computed(() =>
    consultation.value?.starts_at
        ? formatDateTime(consultation.value.starts_at, locale.value, auth.user?.timezone)
        : '',
);
const otherZone = computed(() => {
    const entered = consultation.value;
    if (!entered?.starts_at || !entered.timezone || entered.timezone === auth.user?.timezone) return null;

    return t('consultations.otherZone', {
        zone: entered.timezone,
        time: formatDateTime(entered.starts_at, locale.value, entered.timezone),
    });
});
</script>

<template>
    <p v-if="notFound" class="text-ink-3">{{ t('notFound.title') }}</p>

    <template v-else-if="loaded">
        <div class="page-head flex flex-wrap items-start gap-4">
            <div class="min-w-0">
                <div class="eyebrow">
                    <RouterLink :to="{ name: 'consultations.index' }" class="text-ink-4 hover:underline">{{
                        t('consultations.title')
                    }}</RouterLink>
                    <template v-if="when"> · {{ when }}</template>
                </div>
                <h1 class="truncate">{{ heading }}</h1>
                <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                    <RouterLink
                        v-if="client && id"
                        :to="{ name: 'clients.show', params: { id: client.id } }"
                        class="font-medium hover:underline"
                        >{{ client.full_name }}</RouterLink
                    >
                    <template v-if="id">
                        <ConsultationStatusBadge :status="consultation.status" />
                        <span v-for="method in consultation.methods" :key="method.id" class="method-pill">{{
                            labels.method(method)
                        }}</span>
                    </template>
                </div>
                <div v-if="otherZone" class="mt-1 text-xs text-ink-3">{{ otherZone }}</div>
            </div>
            <div class="ml-auto flex gap-2">
                <button v-if="id" type="button" class="btn btn-ghost" @click="remove">
                    {{ t('consultations.form.delete') }}
                </button>
                <button type="button" class="btn btn-primary" :disabled="form.processing.value" @click="save">
                    {{ id ? t('consultations.form.save') : t('consultations.form.create') }}
                </button>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px]">
            <form class="space-y-4" novalidate @submit.prevent="save">
                <section class="card">
                    <div class="card-head">
                        <h2>{{ t('consultations.form.details') }}</h2>
                    </div>
                    <div class="card-body">
                        <FormField
                            v-if="!id"
                            v-slot="{ id: fieldId, aria }"
                            :label="t('consultations.form.client')"
                            :error="form.errors.value.client_id"
                        >
                            <ClientPicker
                                :input-id="fieldId"
                                :aria="aria"
                                :selected="client?.full_name"
                                @select="selectClient"
                            />
                            <p v-if="client" class="mt-1.5 text-xs text-ink-3">✓ {{ client.full_name }}</p>
                        </FormField>
                        <div class="row">
                            <FormField
                                v-slot="{ id: fieldId, aria }"
                                :label="t('consultations.form.title')"
                                :error="form.errors.value.title"
                            >
                                <input
                                    :id="fieldId"
                                    v-model="form.data.title"
                                    v-bind="aria"
                                    class="input"
                                    :placeholder="t('consultations.form.titlePlaceholder')"
                                    autocomplete="off"
                                />
                            </FormField>
                            <FormField
                                v-slot="{ id: fieldId, aria }"
                                :label="t('consultations.form.status')"
                                :error="form.errors.value.status"
                            >
                                <select :id="fieldId" v-model="form.data.status" v-bind="aria" class="input">
                                    <option v-for="status in STATUSES" :key="status" :value="status">
                                        {{ t(`consultationStatuses.${status}`) }}
                                    </option>
                                </select>
                            </FormField>
                        </div>
                        <div class="row">
                            <FormField
                                v-slot="{ id: fieldId, aria }"
                                :label="t('consultations.form.startsAt')"
                                :error="form.errors.value.starts_at"
                            >
                                <input
                                    :id="fieldId"
                                    v-model="form.data.starts_at"
                                    v-bind="aria"
                                    class="input"
                                    type="datetime-local"
                                />
                            </FormField>
                            <FormField
                                v-slot="{ id: fieldId, aria }"
                                :label="t('consultations.form.timezone')"
                                :error="form.errors.value.timezone"
                                :hint="t('consultations.form.timezoneHint')"
                            >
                                <select :id="fieldId" v-model="form.data.timezone" v-bind="aria" class="input">
                                    <option v-for="zone in zones" :key="zone" :value="zone">{{ zone }}</option>
                                </select>
                            </FormField>
                            <FormField
                                v-slot="{ id: fieldId, aria }"
                                :label="t('consultations.form.duration')"
                                :error="form.errors.value.duration_minutes"
                            >
                                <input
                                    :id="fieldId"
                                    v-model.number="form.data.duration_minutes"
                                    v-bind="aria"
                                    class="input"
                                    type="number"
                                    min="1"
                                    max="1440"
                                    step="5"
                                />
                            </FormField>
                        </div>

                        <div class="field">
                            <span class="label">{{ t('consultations.form.methods') }}</span>
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
                        </div>

                        <FormField
                            v-slot="{ id: fieldId, aria }"
                            :label="t('consultations.form.topics')"
                            :error="form.errors.value.topics"
                        >
                            <textarea
                                :id="fieldId"
                                v-model="form.data.topics"
                                v-bind="aria"
                                class="input min-h-20"
                                :placeholder="t('consultations.form.topicsPlaceholder')"
                            />
                        </FormField>
                    </div>
                </section>

                <section class="card">
                    <div class="card-head">
                        <h2>{{ t('consultations.form.internalNotes') }}</h2>
                        <span class="right"><VisibilityBadge visibility="private" /></span>
                    </div>
                    <div class="card-body space-y-3">
                        <RichTextEditor
                            v-model="form.data.internal_notes"
                            :label="t('consultations.form.internalNotes')"
                            :invalid="Boolean(form.errors.value.internal_notes)"
                            height="160px"
                        />
                        <div class="notice n-neutral">
                            <span aria-hidden="true">⚿</span>
                            <div>{{ t('consultations.form.internalNotesHint') }}</div>
                        </div>
                    </div>
                </section>

                <section class="card">
                    <div class="card-head">
                        <h2>{{ t('consultations.form.clientSummary') }}</h2>
                        <span class="right"><VisibilityBadge visibility="shared_with_client" /></span>
                    </div>
                    <div class="card-body space-y-2">
                        <RichTextEditor
                            v-model="form.data.client_summary"
                            :label="t('consultations.form.clientSummary')"
                            :invalid="Boolean(form.errors.value.client_summary)"
                            height="130px"
                        />
                        <p class="text-xs text-ink-3">{{ t('consultations.form.clientSummaryHint') }}</p>
                    </div>
                </section>

                <section class="card">
                    <div class="card-head">
                        <h2>{{ t('consultations.form.nextSteps') }}</h2>
                    </div>
                    <div class="card-body">
                        <RichTextEditor
                            v-model="form.data.next_steps"
                            :label="t('consultations.form.nextSteps')"
                            :invalid="Boolean(form.errors.value.next_steps)"
                        />
                    </div>
                </section>

                <div class="flex justify-end">
                    <button type="submit" class="btn btn-primary" :disabled="form.processing.value">
                        {{ id ? t('consultations.form.save') : t('consultations.form.create') }}
                    </button>
                </div>
            </form>

            <div class="space-y-4">
                <p v-if="!id" class="notice n-info">{{ t('consultations.saveFirst') }}</p>

                <template v-else>
                    <section class="card">
                        <div class="card-head">
                            <h2>{{ t('consultations.chart.title') }}</h2>
                            <span v-if="consultation.chart" class="right font-mono">{{
                                t('consultations.snapshot').toLowerCase()
                            }}</span>
                        </div>
                        <div class="card-body space-y-3">
                            <template v-if="consultation.chart">
                                <NatalChart :chart="consultation.chart" :name="client?.full_name" compact />
                                <p class="text-xs text-ink-3">{{ t('consultations.chart.snapshotNote') }}</p>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-sm" :disabled="chartBusy" @click="attachChart">
                                        {{ t('consultations.chart.replace') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-ghost btn-sm"
                                        :disabled="chartBusy"
                                        @click="detachChart"
                                    >
                                        {{ t('consultations.chart.detach') }}
                                    </button>
                                </div>
                            </template>
                            <div v-else class="empty py-4!">
                                <div class="e-glyph" aria-hidden="true">◌</div>
                                <p class="font-medium text-ink">{{ t('consultations.chart.none') }}</p>
                                <p class="mt-1 text-xs">{{ t('consultations.chart.attachHint') }}</p>
                                <button
                                    type="button"
                                    class="btn btn-primary btn-sm mt-3"
                                    :disabled="chartBusy"
                                    @click="attachChart"
                                >
                                    {{ t('consultations.chart.attach') }}
                                </button>
                            </div>
                        </div>
                    </section>

                    <section class="card">
                        <div class="card-head">
                            <h2>{{ t('consultations.attachments') }}</h2>
                        </div>
                        <div class="card-body">
                            <AttachmentsPanel :consultation-id="id" compact />
                        </div>
                    </section>

                    <section>
                        <h2 class="mb-2 text-sm font-semibold">{{ t('consultations.notes') }}</h2>
                        <NotesPanel :client-id="client.id" :consultation-id="id" compact />
                    </section>
                </template>
            </div>
        </div>
    </template>

    <p v-else class="text-ink-3">{{ t('common.loading') }}</p>
</template>
