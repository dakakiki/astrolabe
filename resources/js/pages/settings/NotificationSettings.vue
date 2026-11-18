<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import AppIcon from '@/components/AppIcon.vue';
import FormField from '@/components/FormField.vue';
import ToggleRow from '@/components/ToggleRow.vue';
import { useForm } from '@/composables/useForm';
import { useLabels } from '@/composables/useLabels';
import http from '@/lib/http';
import { ALWAYS_BY_EMAIL, anyChannel, channelsForm, CHANNELS, NOTIFICATION_GROUPS } from '@/lib/inAppNotifications';
import { preferencesPayload, quietHoursForm, reminderChoices } from '@/lib/notifications';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

/**
 * The signed-in person's notifications (docs/spec/09, "Obaveštenja"; user, 18 Nov 2026):
 * every notice they may turn off has a row with two channels — email and in the app (the
 * bell in the header); neither ticked turns it off. The reminder's usual lead time and the
 * morning email's time sit under their rows while either channel is on. Quiet hours hold
 * back email only. The account and security emails are listed apart, always on.
 * All on the person's own clock; the server plans when each notice goes out.
 */
const { t } = useI18n();
const auth = useAuthStore();
const toast = useToastStore();
const labels = useLabels();

const stored = auth.user.notification_preferences;
const quiet = quietHoursForm(stored);

const form = useForm({
    channels: channelsForm(stored),
    reminder_minutes: stored.reminder_minutes,
    digest_time: stored.digest_time,
    quiet_enabled: quiet.enabled,
    quiet_start: quiet.start,
    quiet_end: quiet.end,
});

const choices = computed(() => reminderChoices(form.data.reminder_minutes));
const remindersOn = computed(() => anyChannel(form.data.channels, 'appointment_reminder'));
const digestOn = computed(() => anyChannel(form.data.channels, 'task_digest'));

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
            <p class="mb-4 text-ink-3">{{ t('settings.notifications.intro', { zone: auth.user.timezone }) }}</p>

            <div class="table-wrap">
                <table class="notif-matrix">
                    <thead>
                        <tr>
                            <th scope="col">{{ t('settings.notifications.columns.notice') }}</th>
                            <th v-for="channel in CHANNELS" :key="channel" scope="col" class="ch">
                                <AppIcon :name="channel === 'mail' ? 'mail' : 'notifications'" />
                                {{ t(`settings.notifications.columns.${channel}`) }}
                            </th>
                        </tr>
                    </thead>
                    <tbody v-for="group in NOTIFICATION_GROUPS" :key="group.key">
                        <tr class="group">
                            <th colspan="3" scope="colgroup">{{ t(`settings.notifications.groups.${group.key}`) }}</th>
                        </tr>
                        <template v-for="row in group.kinds" :key="row.kind">
                            <tr>
                                <th scope="row">
                                    <span class="block font-medium text-ink">{{
                                        t(`settings.notifications.kinds.${row.kind}.label`)
                                    }}</span>
                                    <span class="block text-xs text-ink-3">{{
                                        t(`settings.notifications.kinds.${row.kind}.hint`)
                                    }}</span>
                                </th>
                                <td v-for="channel in CHANNELS" :key="channel" class="ch">
                                    <input
                                        v-if="row.channels.includes(channel)"
                                        :id="`notif-${row.kind}-${channel}`"
                                        v-model="form.data.channels[row.kind][channel]"
                                        type="checkbox"
                                        :aria-label="
                                            t('settings.notifications.checkbox', {
                                                channel: t(`settings.notifications.columns.${channel}`),
                                                notice: t(`settings.notifications.kinds.${row.kind}.label`),
                                            })
                                        "
                                    />
                                    <span v-else class="text-ink-3" :title="t('settings.notifications.notAvailable')"
                                        >—<span class="sr-only">{{
                                            t('settings.notifications.notAvailable')
                                        }}</span></span
                                    >
                                </td>
                            </tr>
                            <!-- The usual lead time and the morning time, while the notice is on at all. -->
                            <tr v-if="row.kind === 'appointment_reminder' && remindersOn" class="extra">
                                <td colspan="3">
                                    <FormField
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
                                </td>
                            </tr>
                            <tr v-if="row.kind === 'task_digest' && digestOn" class="extra">
                                <td colspan="3">
                                    <FormField
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
                                </td>
                            </tr>
                        </template>
                    </tbody>
                    <!-- Kept apart: they keep the account safe and reachable, so they always come by email. -->
                    <tbody>
                        <tr class="group">
                            <th colspan="3" scope="colgroup">
                                {{ t('settings.notifications.groups.always') }}
                                <span class="block text-xs font-normal text-ink-3 normal-case">{{
                                    t('settings.notifications.alwaysHint')
                                }}</span>
                            </th>
                        </tr>
                        <tr v-for="key in ALWAYS_BY_EMAIL" :key="key" class="locked">
                            <th scope="row">
                                <span class="block font-medium text-ink">{{
                                    t(`settings.notifications.always.${key}.label`)
                                }}</span>
                                <span class="block text-xs text-ink-3">{{
                                    t(`settings.notifications.always.${key}.hint`)
                                }}</span>
                            </th>
                            <td class="ch">
                                <span class="badge" :title="t('settings.notifications.alwaysTitle')"
                                    ><AppIcon name="key" />{{ t('settings.notifications.alwaysBadge') }}</span
                                >
                            </td>
                            <td class="ch">
                                <span class="text-ink-3" :title="t('settings.notifications.notAvailable')"
                                    >—<span class="sr-only">{{ t('settings.notifications.notAvailable') }}</span></span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-4 border-t border-line-soft pt-2">
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

            <p class="notice n-neutral mt-3 mb-4">{{ t('settings.notifications.private') }}</p>

            <div class="form-actions">
                <button
                    class="btn btn-primary"
                    type="submit"
                    :disabled="form.processing.value"
                    v-tip="t('tips.common.save')"
                >
                    <AppIcon name="save" />
                    {{ t('common.save') }}
                </button>
            </div>
        </form>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>{{ t('settings.notifications.testTitle') }}</h2>
        </div>
        <div class="card-body">
            <p class="mb-3 text-ink-3">{{ t('settings.notifications.testIntro', { email: auth.user.email }) }}</p>
            <button
                type="button"
                class="btn"
                :disabled="testing"
                @click="sendTest"
                v-tip="t('tips.settings.notifications.testSend')"
            >
                <AppIcon name="send" />
                {{ t('settings.notifications.testSend') }}
            </button>
        </div>
    </section>
</template>
