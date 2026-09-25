<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import FormField from '@/components/FormField.vue';
import ToggleRow from '@/components/ToggleRow.vue';
import { useForm } from '@/composables/useForm';
import { useLabels } from '@/composables/useLabels';
import http from '@/lib/http';
import { preferencesPayload, quietHoursForm, reminderChoices } from '@/lib/notifications';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

/**
 * The signed-in person's email notifications (docs/spec/09): reminders before
 * appointments, the morning email about tasks due today, and quiet hours. All
 * on their own clock; the server plans when each email goes out.
 */
const { t } = useI18n();
const auth = useAuthStore();
const toast = useToastStore();
const labels = useLabels();

const stored = auth.user.notification_preferences;
const quiet = quietHoursForm(stored);

const form = useForm({
    appointment_reminders: stored.appointment_reminders,
    reminder_minutes: stored.reminder_minutes,
    task_digest: stored.task_digest,
    digest_time: stored.digest_time,
    quiet_enabled: quiet.enabled,
    quiet_start: quiet.start,
    quiet_end: quiet.end,
});

const choices = computed(() => reminderChoices(form.data.reminder_minutes));

async function save() {
    await form
        .submit(async (data) => {
            const response = await http.put('/notification-preferences', preferencesPayload(data));
            auth.user = response.data.data;
            toast.success(t('settings.notifications.saved'));
        })
        .catch(() => {});
}

/* Test email */

const testing = ref(false);

async function sendTest() {
    testing.value = true;
    try {
        await http.post('/notification-preferences/test');
        toast.success(t('settings.notifications.testQueued', { email: auth.user.email }));
    } catch (error) {
        toast.error(error.response?.status === 429 ? t('errors.tooManyAttempts') : t('errors.generic'));
    } finally {
        testing.value = false;
    }
}
</script>

<template>
    <section class="card">
        <div class="card-head">
            <h2>{{ t('settings.notifications.title') }}</h2>
        </div>
        <form class="card-body" novalidate @submit.prevent="save">
            <p class="mb-2 text-ink-3">{{ t('settings.notifications.intro', { zone: auth.user.timezone }) }}</p>

            <div class="divide-y divide-line-soft">
                <div>
                    <ToggleRow
                        v-model="form.data.appointment_reminders"
                        :label="t('settings.notifications.reminders')"
                        :description="t('settings.notifications.remindersHint')"
                    />
                    <FormField
                        v-if="form.data.appointment_reminders"
                        v-slot="{ id, aria }"
                        :label="t('settings.notifications.reminderMinutes')"
                        :error="form.errors.value.reminder_minutes"
                        :hint="t('settings.notifications.reminderMinutesHint')"
                    >
                        <select
                            :id="id"
                            v-model.number="form.data.reminder_minutes"
                            v-bind="aria"
                            class="input sm:w-64"
                        >
                            <option v-for="minutes in choices" :key="minutes" :value="minutes">
                                {{ labels.leadTime(minutes) }}
                            </option>
                        </select>
                    </FormField>
                </div>

                <div>
                    <ToggleRow
                        v-model="form.data.task_digest"
                        :label="t('settings.notifications.digest')"
                        :description="t('settings.notifications.digestHint')"
                    />
                    <FormField
                        v-if="form.data.task_digest"
                        v-slot="{ id, aria }"
                        :label="t('settings.notifications.digestTime')"
                        :error="form.errors.value.digest_time"
                    >
                        <input
                            :id="id"
                            v-model="form.data.digest_time"
                            v-bind="aria"
                            class="input sm:w-40"
                            type="time"
                            step="900"
                            required
                        />
                    </FormField>
                </div>

                <div>
                    <ToggleRow
                        v-model="form.data.quiet_enabled"
                        :label="t('settings.notifications.quiet')"
                        :description="t('settings.notifications.quietHint')"
                    />
                    <div v-if="form.data.quiet_enabled" class="row">
                        <FormField
                            v-slot="{ id, aria }"
                            :label="t('settings.notifications.quietFrom')"
                            :error="form.errors.value['quiet_hours.start']"
                        >
                            <input
                                :id="id"
                                v-model="form.data.quiet_start"
                                v-bind="aria"
                                class="input"
                                type="time"
                                step="900"
                                required
                            />
                        </FormField>
                        <FormField
                            v-slot="{ id, aria }"
                            :label="t('settings.notifications.quietTo')"
                            :error="form.errors.value['quiet_hours.end']"
                        >
                            <input
                                :id="id"
                                v-model="form.data.quiet_end"
                                v-bind="aria"
                                class="input"
                                type="time"
                                step="900"
                                required
                            />
                        </FormField>
                    </div>
                </div>
            </div>

            <p class="notice n-neutral mt-3 mb-4">{{ t('settings.notifications.private') }}</p>

            <button class="btn btn-primary" type="submit" :disabled="form.processing.value">
                {{ t('common.save') }}
            </button>
        </form>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>{{ t('settings.notifications.testTitle') }}</h2>
        </div>
        <div class="card-body">
            <p class="mb-3 text-ink-3">{{ t('settings.notifications.testIntro', { email: auth.user.email }) }}</p>
            <button type="button" class="btn" :disabled="testing" @click="sendTest">
                {{ t('settings.notifications.testSend') }}
            </button>
        </div>
    </section>
</template>
