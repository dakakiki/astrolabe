<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import ClientPicker from '@/components/ClientPicker.vue';
import FormField from '@/components/FormField.vue';
import ToggleRow from '@/components/ToggleRow.vue';
import { useForm } from '@/composables/useForm';
import http, { idempotencyKey } from '@/lib/http';
import { PRIORITIES, taskPayload } from '@/lib/tasks';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

/**
 * Adds or edits a task. The client is optional and can be chosen here, unless
 * the form was opened from a client or a consultation; a follow-up stays with
 * its consultation's client. A new task carries an Idempotency-Key, so a
 * double click adds it once. Remount (`:key`) to show another task.
 */
const props = defineProps({
    /** The task to edit, or null for a new one. */
    task: { type: Object, default: null },
    /** A fixed client, { id, full_name }: the form was opened from their profile. */
    client: { type: Object, default: null },
    /** For a new follow-up: the consultation, { id }. */
    consultation: { type: Object, default: null },
    /** Starting values for a new task: { title, due_date }. */
    preset: { type: Object, default: () => ({}) },
    /** Show a close button (the form is not always on screen). */
    closable: { type: Boolean, default: false },
});
const emit = defineEmits(['saved', 'cancel']);

const { t } = useI18n();
const auth = useAuthStore();
const toast = useToastStore();

const editing = computed(() => props.task !== null);
const followUp = computed(() => Boolean(props.consultation ?? props.task?.consultation));
// A client can be chosen unless one is given, or the task follows up a consultation.
const choosesClient = computed(() => !props.client && !followUp.value);
const chosen = ref(props.task?.client ?? props.client ?? null);
// One key per form: a repeated click or a retry adds the task once.
const requestKey = idempotencyKey('task');

const form = useForm({
    title: props.task?.title ?? props.preset.title ?? '',
    description: props.task?.description ?? '',
    priority: props.task?.priority ?? 'normal',
    due_date: props.task?.due_date ?? props.preset.due_date ?? '',
    due_time: props.task?.due_time ?? '',
    remind: props.task?.remind ?? true,
});

// "Remind me" counts in the morning email, which may be switched off.
const remindHint = computed(() =>
    auth.user?.notification_preferences?.task_digest === false ? t('tasks.form.remindOff') : t('tasks.form.remindHint'),
);

// A time entered in another zone is edited in that zone, and says so.
const otherZone = computed(() =>
    props.task?.due_time && props.task.timezone && props.task.timezone !== auth.user?.timezone
        ? props.task.timezone
        : null,
);

const heading = computed(() => {
    if (editing.value) return t('tasks.form.editTitle');

    return props.consultation ? t('tasks.form.newFollowUp') : t('tasks.form.newTitle');
});

async function save() {
    try {
        const response = await form.submit((data) => {
            const payload = taskPayload(data);
            if (choosesClient.value) payload.client_id = chosen.value?.id ?? null;

            if (editing.value) return http.patch(`/tasks/${props.task.id}`, payload);

            return http.post(
                '/tasks',
                {
                    ...payload,
                    client_id: chosen.value?.id ?? null,
                    consultation_id: props.consultation?.id ?? null,
                },
                { headers: { 'Idempotency-Key': requestKey } },
            );
        });

        toast.success(editing.value ? t('tasks.saved') : t('tasks.created'));
        emit('saved', response.data.data);
    } catch {
        // Shown next to the fields, or as a toast.
    }
}
</script>

<template>
    <form class="card" novalidate @submit.prevent="save">
        <div class="card-head">
            <h2>{{ heading }}</h2>
            <button
                v-if="closable || editing"
                type="button"
                class="btn btn-ghost btn-sm right"
                :aria-label="t('common.close')"
                @click="emit('cancel')"
            >
                ✕
            </button>
        </div>
        <div class="card-body">
            <FormField v-slot="{ id, aria }" :label="t('tasks.form.title')" :error="form.errors.value.title">
                <input
                    :id="id"
                    v-model="form.data.title"
                    v-bind="aria"
                    class="input"
                    :placeholder="t('tasks.form.titlePlaceholder')"
                    maxlength="200"
                    autocomplete="off"
                    required
                />
            </FormField>

            <FormField
                v-if="choosesClient"
                v-slot="{ id, aria }"
                :label="t('tasks.form.client')"
                :error="form.errors.value.client_id"
                :hint="t('tasks.form.clientHint')"
            >
                <ClientPicker :input-id="id" :aria="aria" :selected="chosen?.full_name" @select="chosen = $event" />
                <p v-if="chosen" class="mt-1.5 flex items-center gap-2 text-xs text-ink-3">
                    ✓ {{ chosen.full_name }}
                    <button type="button" class="btn btn-ghost btn-sm" @click="chosen = null">
                        {{ t('tasks.form.removeClient') }}
                    </button>
                </p>
            </FormField>
            <p v-else-if="chosen && !client" class="mb-3 text-sm font-medium">{{ chosen.full_name }}</p>
            <p v-if="form.errors.value.consultation_id" class="mb-3 text-xs text-danger" role="alert">
                {{ form.errors.value.consultation_id }}
            </p>

            <div class="row">
                <FormField v-slot="{ id, aria }" :label="t('tasks.form.dueDate')" :error="form.errors.value.due_date">
                    <input :id="id" v-model="form.data.due_date" v-bind="aria" class="input" type="date" />
                </FormField>
                <FormField
                    v-slot="{ id, aria }"
                    :label="t('tasks.form.dueTime')"
                    :error="form.errors.value.due_time"
                    :hint="otherZone ? t('tasks.form.otherZone', { zone: otherZone }) : t('tasks.form.dueTimeHint')"
                >
                    <input
                        :id="id"
                        v-model="form.data.due_time"
                        v-bind="aria"
                        class="input"
                        type="time"
                        :disabled="!form.data.due_date"
                    />
                </FormField>
            </div>

            <div class="field">
                <span class="label">{{ t('tasks.form.priority') }}</span>
                <div class="seg" role="group" :aria-label="t('tasks.form.priority')">
                    <button
                        v-for="priority in PRIORITIES"
                        :key="priority"
                        type="button"
                        :aria-pressed="form.data.priority === priority"
                        @click="form.data.priority = priority"
                    >
                        {{ t(`tasks.priorities.${priority}`) }}
                    </button>
                </div>
                <div v-if="form.errors.value.priority" class="error">{{ form.errors.value.priority }}</div>
            </div>

            <ToggleRow
                v-if="form.data.due_date"
                v-model="form.data.remind"
                class="mb-2"
                :label="t('tasks.form.remind')"
                :description="remindHint"
            />

            <FormField
                v-slot="{ id, aria }"
                :label="t('tasks.form.description')"
                :error="form.errors.value.description"
            >
                <textarea
                    :id="id"
                    v-model="form.data.description"
                    v-bind="aria"
                    class="input min-h-20"
                    maxlength="5000"
                />
            </FormField>

            <div class="flex justify-end gap-2">
                <button v-if="closable || editing" type="button" class="btn btn-ghost" @click="emit('cancel')">
                    {{ t('common.cancel') }}
                </button>
                <button type="submit" class="btn btn-primary" :disabled="form.processing.value">
                    {{ editing ? t('tasks.form.save') : t('tasks.form.create') }}
                </button>
            </div>
        </div>
    </form>
</template>
