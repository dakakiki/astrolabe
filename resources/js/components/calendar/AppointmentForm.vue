<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

import ClientPicker from '@/components/ClientPicker.vue';
import FormField from '@/components/FormField.vue';
import { useForm } from '@/composables/useForm';
import { timeZoneOptions, useLabels } from '@/composables/useLabels';
import { formatDateTime } from '@/lib/datetime';
import http, { idempotencyKey } from '@/lib/http';
import { reminderChoices } from '@/lib/notifications';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

/**
 * Books or changes an appointment. The server checks for overlaps inside the
 * transaction; when it finds one (409), the form lists what is in the way and
 * offers to save anyway — overlaps are allowed, but only on purpose. A new
 * booking carries an Idempotency-Key, so a double click books once.
 */
const props = defineProps({
    /** The appointment to change, or null for a new one. */
    appointment: { type: Object, default: null },
    /** For a new one: { starts_at: "2026-10-05T15:00", client: { id, full_name } }. */
    preset: { type: Object, default: () => ({}) },
    services: { type: Array, default: () => [] },
});
const emit = defineEmits(['saved', 'cancel']);

const { t, locale } = useI18n();
const labels = useLabels();
const auth = useAuthStore();
const toast = useToastStore();

const editing = computed(() => props.appointment !== null);
const client = ref(props.appointment?.client ?? props.preset.client ?? null);
const conflicts = ref([]);
// One key per form: a repeated click or a retry books once.
const requestKey = idempotencyKey('appointment');

const form = useForm({
    service_id: props.appointment?.service_id ?? null,
    starts_at: props.appointment?.starts_at_local ?? props.preset.starts_at ?? '',
    duration_minutes: props.appointment?.duration_minutes ?? null,
    timezone: props.appointment?.timezone ?? auth.user?.timezone ?? 'UTC',
    location_type: props.appointment?.location_type ?? 'online',
    location_details: props.appointment?.location_details ?? '',
    notes: props.appointment?.notes ?? '',
    // Minutes before the start, or null for none; a new one starts from the usual reminder.
    reminder_minutes: props.appointment
        ? props.appointment.reminder_minutes
        : (auth.user?.notification_preferences?.reminder_minutes ?? null),
});

const zones = computed(() => timeZoneOptions(form.data.timezone));
const reminderOptions = computed(() => reminderChoices(form.data.reminder_minutes));
// Reminders go to whoever runs the appointment; only one's own switch is known here.
const remindersPaused = computed(
    () =>
        form.data.reminder_minutes !== null &&
        (!props.appointment || props.appointment.assigned_user?.id === auth.user?.id) &&
        auth.user?.notification_preferences?.appointment_reminders === false,
);
// Active services, plus an inactive one the appointment already has.
const serviceOptions = computed(() =>
    props.services.filter((service) => service.is_active || service.id === form.data.service_id),
);

// A new appointment takes the service's length and place, unless they were set by hand.
watch(
    () => form.data.service_id,
    (id, previousId) => {
        if (editing.value) return;
        const service = props.services.find((item) => item.id === id);
        const previous = props.services.find((item) => item.id === previousId);
        if (!service) return;

        if (!form.data.duration_minutes || form.data.duration_minutes === previous?.duration_minutes) {
            form.data.duration_minutes = service.duration_minutes;
        }
        if (service.location_type !== 'either') form.data.location_type = service.location_type;
    },
);

// A changed form is a new question: the earlier overlap no longer applies.
watch(
    () => JSON.stringify(form.data),
    () => {
        if (!form.processing.value) conflicts.value = [];
    },
);

async function save(allowOverlap = false) {
    conflicts.value = [];

    try {
        const response = await form.submit(
            (data) => {
                const payload = {
                    ...data,
                    duration_minutes: data.duration_minutes || null,
                    location_details: data.location_details || null,
                    notes: data.notes || null,
                    allow_overlap: allowOverlap || undefined,
                };

                return editing.value
                    ? http.patch(`/appointments/${props.appointment.id}`, payload)
                    : http.post(
                          '/appointments',
                          { ...payload, client_id: client.value?.id ?? null },
                          { headers: { 'Idempotency-Key': requestKey } },
                      );
            },
            { handles: [409] },
        );

        toast.success(editing.value ? t('appointments.saved') : t('appointments.created'));
        emit('saved', response.data.data);
    } catch (error) {
        if (error.response?.status === 409 && error.response.data?.conflicts) {
            conflicts.value = error.response.data.conflicts;
        }
    }
}

const when = (appointment) =>
    formatDateTime(appointment.starts_at, locale.value, auth.user?.timezone, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
</script>

<template>
    <form class="card" novalidate @submit.prevent="save()">
        <div class="card-head">
            <h2>{{ editing ? t('appointments.form.editTitle') : t('appointments.form.newTitle') }}</h2>
            <button
                type="button"
                class="btn btn-ghost btn-sm right"
                :aria-label="t('appointments.detail.close')"
                @click="emit('cancel')"
            >
                ✕
            </button>
        </div>
        <div class="card-body">
            <FormField
                v-if="!editing"
                v-slot="{ id, aria }"
                :label="t('appointments.form.client')"
                :error="form.errors.value.client_id"
            >
                <ClientPicker :input-id="id" :aria="aria" :selected="client?.full_name" @select="client = $event" />
                <p v-if="client" class="mt-1.5 text-xs text-ink-3">✓ {{ client.full_name }}</p>
            </FormField>
            <p v-else class="mb-3 font-medium">{{ client?.full_name }}</p>

            <FormField
                v-slot="{ id, aria }"
                :label="t('appointments.form.service')"
                :error="form.errors.value.service_id"
            >
                <select :id="id" v-model="form.data.service_id" v-bind="aria" class="input">
                    <option :value="null">{{ t('appointments.form.noService') }}</option>
                    <option v-for="service in serviceOptions" :key="service.id" :value="service.id">
                        {{
                            service.is_active
                                ? service.name
                                : t('consultations.form.inactiveService', { name: service.name })
                        }}
                    </option>
                </select>
            </FormField>

            <div class="row">
                <FormField
                    v-slot="{ id, aria }"
                    :label="t('appointments.form.startsAt')"
                    :error="form.errors.value.starts_at"
                >
                    <input
                        :id="id"
                        v-model="form.data.starts_at"
                        v-bind="aria"
                        class="input"
                        type="datetime-local"
                        required
                    />
                </FormField>
                <FormField
                    v-slot="{ id, aria }"
                    :label="t('appointments.form.duration')"
                    :error="form.errors.value.duration_minutes"
                    :hint="form.data.service_id ? t('appointments.form.durationHint') : null"
                >
                    <input
                        :id="id"
                        v-model.number="form.data.duration_minutes"
                        v-bind="aria"
                        class="input"
                        type="number"
                        min="5"
                        max="1440"
                        step="5"
                    />
                </FormField>
            </div>

            <FormField
                v-slot="{ id, aria }"
                :label="t('appointments.form.timezone')"
                :error="form.errors.value.timezone"
                :hint="t('appointments.form.timezoneHint')"
            >
                <select :id="id" v-model="form.data.timezone" v-bind="aria" class="input">
                    <option v-for="zone in zones" :key="zone" :value="zone">{{ zone }}</option>
                </select>
            </FormField>

            <div class="row">
                <FormField
                    v-slot="{ id, aria }"
                    :label="t('appointments.form.location')"
                    :error="form.errors.value.location_type"
                >
                    <select :id="id" v-model="form.data.location_type" v-bind="aria" class="input">
                        <option v-for="type in ['online', 'in_person']" :key="type" :value="type">
                            {{ labels.locationType(type) }}
                        </option>
                    </select>
                </FormField>
                <FormField
                    v-slot="{ id, aria }"
                    :label="t('appointments.form.details')"
                    :error="form.errors.value.location_details"
                >
                    <input
                        :id="id"
                        v-model="form.data.location_details"
                        v-bind="aria"
                        class="input"
                        :placeholder="t('appointments.form.detailsPlaceholder')"
                        autocomplete="off"
                    />
                </FormField>
            </div>

            <FormField
                v-slot="{ id, aria }"
                :label="t('appointments.form.reminder')"
                :error="form.errors.value.reminder_minutes"
                :hint="remindersPaused ? t('appointments.form.reminderPaused') : t('appointments.form.reminderHint')"
            >
                <select :id="id" v-model="form.data.reminder_minutes" v-bind="aria" class="input">
                    <option :value="null">{{ t('notifications.noReminder') }}</option>
                    <option v-for="minutes in reminderOptions" :key="minutes" :value="minutes">
                        {{ labels.leadTime(minutes) }}
                    </option>
                </select>
            </FormField>

            <FormField
                v-slot="{ id, aria }"
                :label="t('appointments.form.notes')"
                :error="form.errors.value.notes"
                :hint="t('appointments.form.notesHint')"
            >
                <textarea :id="id" v-model="form.data.notes" v-bind="aria" class="input min-h-16" rows="2" />
            </FormField>

            <div v-if="conflicts.length" class="notice n-warn mb-3" role="alert">
                <div>
                    <strong>{{ t('appointments.form.overlapTitle') }}</strong>
                    {{ t('appointments.form.overlapText') }}
                    <ul class="mt-1 list-disc pl-5">
                        <li v-for="conflict in conflicts" :key="conflict.id">
                            {{ when(conflict) }} · {{ conflict.client?.full_name
                            }}<template v-if="conflict.service"> · {{ conflict.service.name }}</template>
                        </li>
                    </ul>
                    <p class="mt-1 text-xs">{{ t('appointments.form.overlapHint') }}</p>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    v-if="conflicts.length"
                    type="button"
                    class="btn btn-primary"
                    :disabled="form.processing.value"
                    @click="save(true)"
                >
                    {{ t('appointments.form.saveAnyway') }}
                </button>
                <button v-else class="btn btn-primary" type="submit" :disabled="form.processing.value">
                    {{ editing ? t('appointments.form.save') : t('appointments.form.create') }}
                </button>
                <button type="button" class="btn btn-ghost" @click="emit('cancel')">
                    {{ t('appointments.form.cancel') }}
                </button>
            </div>
        </div>
    </form>
</template>
