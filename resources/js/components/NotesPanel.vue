<script setup>
import { computed, defineAsyncComponent, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

import FormField from '@/components/FormField.vue';
import RichText from '@/components/RichText.vue';
import VisibilityBadge from '@/components/VisibilityBadge.vue';
import { useForm } from '@/composables/useForm';
import { formatDateTime } from '@/lib/datetime';
import http from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

const RichTextEditor = defineAsyncComponent(() => import('@/components/RichTextEditor.vue'));

/**
 * A client's notes, or those on one consultation, with a form to add or edit
 * one. New notes are private (docs/spec/02).
 */
const props = defineProps({
    clientId: { type: [Number, String], required: true },
    /** Show only this consultation's notes and tie new ones to it. */
    consultationId: { type: [Number, String], default: null },
    /** Consultations a note may be tied to, for the select; none shows no select. */
    consultations: { type: Array, default: () => [] },
    compact: { type: Boolean, default: false },
});
const emit = defineEmits(['changed']);

const { t, locale } = useI18n();
const auth = useAuthStore();
const toast = useToastStore();

const notes = ref([]);
const meta = ref(null);
const loading = ref(true);
const editing = ref(null);

const blank = () => ({
    title: '',
    content: '',
    visibility: 'private',
    consultation_id: props.consultationId ? Number(props.consultationId) : null,
});
const form = useForm(blank());

const filter = computed(() =>
    props.consultationId ? { consultation_id: props.consultationId } : { client_id: props.clientId },
);

async function load(page = 1) {
    loading.value = true;
    try {
        const { data } = await http.get('/notes', { params: { ...filter.value, page } });
        notes.value = page === 1 ? data.data : [...notes.value, ...data.data];
        meta.value = data.meta;
    } finally {
        loading.value = false;
    }
}

onMounted(() => load());

function edit(note) {
    editing.value = note;
    form.reset({
        title: note.title ?? '',
        content: note.content,
        visibility: note.visibility,
        consultation_id: note.consultation?.id ?? null,
    });
}

function cancel() {
    editing.value = null;
    form.reset(blank());
}

async function save() {
    try {
        await form.submit((data) =>
            editing.value
                ? http.patch(`/notes/${editing.value.id}`, data)
                : http.post('/notes', { ...data, client_id: props.clientId }),
        );
        toast.success(t('notes.saved'));
        cancel();
        await load();
        emit('changed');
    } catch {
        // Shown next to the fields, or as a toast.
    }
}

async function remove(note) {
    if (!window.confirm(t('notes.confirmDelete'))) return;

    await http.delete(`/notes/${note.id}`);
    toast.success(t('notes.deleted'));
    if (editing.value?.id === note.id) cancel();
    await load();
    emit('changed');
}

const when = (iso) => formatDateTime(iso, locale.value, auth.user?.timezone);
const consultationLabel = (consultation) =>
    [when(consultation.starts_at) || t('consultationStatuses.draft'), consultation.title].filter(Boolean).join(' · ');
</script>

<template>
    <div class="grid grid-cols-1 gap-4" :class="compact ? '' : 'lg:grid-cols-[minmax(0,1fr)_380px]'">
        <div class="space-y-3">
            <article v-for="note in notes" :key="note.id" class="card">
                <div class="card-head">
                    <h3 class="min-w-0 truncate text-sm font-semibold">
                        {{ note.title || t('timeline.note') }}
                    </h3>
                    <span class="right flex shrink-0 items-center gap-2">
                        <VisibilityBadge :visibility="note.visibility" />
                    </span>
                </div>
                <div class="card-body space-y-2">
                    <RichText :html="note.content" />
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-3">
                        <span>{{
                            t('notes.meta', { name: note.author?.name ?? '—', when: when(note.created_at) })
                        }}</span>
                        <span v-if="note.updated_at !== note.created_at">{{
                            t('notes.edited', { when: when(note.updated_at) })
                        }}</span>
                        <RouterLink
                            v-if="note.consultation && !consultationId"
                            :to="{ name: 'consultations.show', params: { id: note.consultation.id } }"
                            class="hover:underline"
                            >{{ consultationLabel(note.consultation) }}</RouterLink
                        >
                        <span v-if="note.can_edit" class="ml-auto flex gap-1">
                            <button type="button" class="btn btn-ghost btn-sm" @click="edit(note)">
                                {{ t('common.edit') }}
                            </button>
                            <button type="button" class="btn btn-ghost btn-sm" @click="remove(note)">
                                {{ t('common.delete') }}
                            </button>
                        </span>
                    </div>
                </div>
            </article>

            <p v-if="!loading && !notes.length" class="card empty">{{ t('notes.empty') }}</p>
            <button
                v-if="meta && meta.current_page < meta.last_page"
                type="button"
                class="btn btn-sm"
                @click="load(meta.current_page + 1)"
            >
                {{ t('common.more') }}
            </button>
        </div>

        <form class="card self-start" novalidate @submit.prevent="save">
            <div class="card-head">
                <h2>{{ editing ? t('notes.edit') : t('notes.add') }}</h2>
            </div>
            <div class="card-body">
                <FormField v-slot="{ id, aria }" :label="t('notes.titleLabel')" :error="form.errors.value.title">
                    <input
                        :id="id"
                        v-model="form.data.title"
                        v-bind="aria"
                        class="input"
                        :placeholder="t('notes.titlePlaceholder')"
                        autocomplete="off"
                    />
                </FormField>
                <div class="field">
                    <span class="label">{{ t('notes.content') }}</span>
                    <RichTextEditor
                        v-model="form.data.content"
                        :label="t('notes.content')"
                        :invalid="Boolean(form.errors.value.content)"
                        height="140px"
                    />
                    <div v-if="form.errors.value.content" class="error">{{ form.errors.value.content }}</div>
                </div>
                <FormField
                    v-if="consultations.length && !consultationId"
                    v-slot="{ id, aria }"
                    :label="t('notes.consultation')"
                    :error="form.errors.value.consultation_id"
                >
                    <select :id="id" v-model="form.data.consultation_id" v-bind="aria" class="input">
                        <option :value="null">{{ t('notes.noConsultation') }}</option>
                        <option v-for="consultation in consultations" :key="consultation.id" :value="consultation.id">
                            {{ consultationLabel(consultation) }}
                        </option>
                    </select>
                </FormField>
                <FormField v-slot="{ id, aria }" :label="t('visibility.label')" :error="form.errors.value.visibility">
                    <select :id="id" v-model="form.data.visibility" v-bind="aria" class="input">
                        <option v-for="value in ['private', 'team', 'shared_with_client']" :key="value" :value="value">
                            {{ t(`visibility.${value}`) }} — {{ t(`visibility.hints.${value}`) }}
                        </option>
                    </select>
                </FormField>
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary" :disabled="form.processing.value">
                        {{ editing ? t('notes.update') : t('notes.save') }}
                    </button>
                    <button v-if="editing" type="button" class="btn btn-ghost" @click="cancel">
                        {{ t('common.cancel') }}
                    </button>
                </div>
            </div>
        </form>
    </div>
</template>
