<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

import FormField from '@/components/FormField.vue';
import ToggleRow from '@/components/ToggleRow.vue';
import { useForm } from '@/composables/useForm';
import {
    bookingState,
    BUFFER_CHOICES,
    CHANGE_UNTIL_CHOICES,
    choicesWith,
    durationParts,
    MAX_DAYS_CHOICES,
    MIN_NOTICE_CHOICES,
    policyForm,
    policyPayload,
    SLOT_STEP_CHOICES,
} from '@/lib/availability';
import http from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

/**
 * Settings → Booking (docs/spec/12, "Zakazivanje (9b)"): whether clients book in
 * the client portal and on what terms — how soon and how far ahead, the grid
 * of start times, free time around appointments, until when a client may move
 * or cancel, and a note they read when booking. The owner changes it; members
 * see it. The panel on top says from the saved rules whether clients can book
 * now, and what is missing if not.
 */
const { t } = useI18n();
const auth = useAuthStore();
const toast = useToastStore();

// The saved rules with the status fields (astrologer, bookable services, hours, portal address).
const policy = ref(null);
const loadFailed = ref(false);
const form = useForm(policyForm(null));

const state = computed(() => bookingState(policy.value));
const isAstrologer = computed(() => policy.value?.astrologer?.id === auth.user.id);
const stateTone = computed(() => ({ open: 'n-ok', incomplete: 'n-warn', off: 'n-neutral' })[state.value.state]);

const noticeChoices = computed(() => choicesWith(MIN_NOTICE_CHOICES, form.data.min_notice_minutes));
const daysChoices = computed(() => choicesWith(MAX_DAYS_CHOICES, form.data.max_days_ahead));
const stepChoices = computed(() => choicesWith(SLOT_STEP_CHOICES, form.data.slot_step_minutes));
const beforeChoices = computed(() => choicesWith(BUFFER_CHOICES, form.data.buffer_before_minutes));
const afterChoices = computed(() => choicesWith(BUFFER_CHOICES, form.data.buffer_after_minutes));
const rescheduleChoices = computed(() => choicesWith(CHANGE_UNTIL_CHOICES, form.data.client_reschedule_until_hours));
const cancelChoices = computed(() => choicesWith(CHANGE_UNTIL_CHOICES, form.data.client_cancel_until_hours));

function duration(minutes) {
    const { unit, count } = durationParts(minutes);

    return t(`settings.booking.duration.${unit}`, { count }, count);
}

const noticeLabel = (minutes) =>
    minutes === 0
        ? t('settings.booking.noticeNone')
        : t('settings.booking.noticeAhead', { duration: duration(minutes) });
const daysLabel = (days) => t('settings.booking.daysAhead', { count: days }, days);
const bufferLabel = (minutes) => (minutes === 0 ? t('settings.booking.bufferNone') : duration(minutes));
const changeLabel = (hours) => {
    if (hours === null) return t('settings.booking.changeNever');
    if (hours === 0) return t('settings.booking.changeAnyTime');

    return t('settings.booking.changeBefore', { duration: duration(hours * 60) });
};

function apply(data) {
    policy.value = data;
    form.reset(policyForm(data));
}

onMounted(async () => {
    try {
        const { data } = await http.get('/booking-policy');
        apply(data.data);
    } catch {
        loadFailed.value = true;
    }
});

async function save() {
    await form
        .submit(async (data) => {
            const response = await http.put('/booking-policy', policyPayload(data));
            apply(response.data.data);
            toast.success(t('settings.booking.saved'));
        })
        .catch(() => {});
}
</script>

<template>
    <div v-if="!auth.isOwner" class="notice n-neutral">{{ t('settings.ownerOnly') }}</div>

    <section class="card">
        <div class="card-head">
            <h2>{{ t('settings.booking.status.title') }}</h2>
        </div>
        <div class="card-body">
            <p v-if="loadFailed" class="notice n-danger">{{ t('errors.generic') }}</p>
            <p v-else-if="!policy" class="text-ink-3">{{ t('common.loading') }}</p>
            <template v-else>
                <div class="notice mb-4" :class="stateTone" role="status">
                    <div>
                        <template v-if="state.state === 'incomplete'">
                            <strong>{{ t('settings.booking.status.incomplete') }}</strong>
                            <ul class="list-disc pl-4">
                                <li v-for="missing in state.missing" :key="missing">
                                    {{ t(`settings.booking.status.missing.${missing}`) }}
                                </li>
                            </ul>
                        </template>
                        <template v-else>{{ t(`settings.booking.status.${state.state}`) }}</template>
                    </div>
                </div>

                <dl class="facts">
                    <dt>{{ t('settings.booking.status.astrologer') }}</dt>
                    <dd>
                        {{ policy.astrologer?.name ?? t('settings.booking.status.noAstrologer') }}
                        <div class="text-xs text-ink-3">{{ t('settings.booking.status.astrologerHint') }}</div>
                    </dd>

                    <dt>{{ t('settings.booking.status.services') }}</dt>
                    <dd class="flex flex-wrap gap-x-3">
                        <span>
                            {{
                                t(
                                    'settings.booking.status.servicesCount',
                                    { count: policy.bookable_services ?? 0 },
                                    policy.bookable_services ?? 0,
                                )
                            }}
                        </span>
                        <RouterLink v-if="auth.isOwner" :to="{ name: 'services.index' }">
                            {{ t('settings.booking.status.chooseServices') }}
                        </RouterLink>
                    </dd>

                    <dt>{{ t('settings.booking.status.hours') }}</dt>
                    <dd class="flex flex-wrap gap-x-3">
                        <span>
                            {{
                                policy.has_availability
                                    ? t('settings.booking.status.hoursSet')
                                    : t('settings.booking.status.hoursMissing')
                            }}
                        </span>
                        <RouterLink v-if="isAstrologer" :to="{ name: 'settings.availability' }">
                            {{ t('settings.booking.status.editHours') }}
                        </RouterLink>
                    </dd>

                    <template v-if="policy.portal_url">
                        <dt>{{ t('settings.booking.status.portal') }}</dt>
                        <dd>
                            <a :href="policy.portal_url" target="_blank" rel="noopener noreferrer" class="break-all">
                                {{ policy.portal_url }}
                            </a>
                        </dd>
                    </template>
                </dl>
            </template>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>{{ t('settings.booking.title') }}</h2>
        </div>
        <form class="card-body" novalidate @submit.prevent="save">
            <fieldset :disabled="!auth.isOwner || !policy">
                <p class="mb-2 text-ink-3">{{ t('settings.booking.intro') }}</p>

                <div class="divide-y divide-line-soft">
                    <ToggleRow
                        v-model="form.data.enabled"
                        :label="t('settings.booking.enabled')"
                        :description="t('settings.booking.enabledHint')"
                        :disabled="!auth.isOwner || !policy"
                    />
                    <ToggleRow
                        v-model="form.data.requires_confirmation"
                        :label="t('settings.booking.confirm')"
                        :description="t('settings.booking.confirmHint')"
                        :disabled="!auth.isOwner || !policy"
                    />
                </div>
                <div v-if="form.errors.value.enabled" class="text-xs text-danger">{{ form.errors.value.enabled }}</div>

                <div class="divider" />
                <div class="eyebrow mb-3">{{ t('settings.booking.timesTitle') }}</div>

                <div class="row">
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('settings.booking.minNotice')"
                        :error="form.errors.value.min_notice_minutes"
                        :hint="t('settings.booking.minNoticeHint')"
                    >
                        <select :id="id" v-model="form.data.min_notice_minutes" v-bind="aria" class="input">
                            <option v-for="minutes in noticeChoices" :key="minutes" :value="minutes">
                                {{ noticeLabel(minutes) }}
                            </option>
                        </select>
                    </FormField>
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('settings.booking.maxDays')"
                        :error="form.errors.value.max_days_ahead"
                    >
                        <select :id="id" v-model="form.data.max_days_ahead" v-bind="aria" class="input">
                            <option v-for="days in daysChoices" :key="days" :value="days">
                                {{ daysLabel(days) }}
                            </option>
                        </select>
                    </FormField>
                </div>

                <FormField
                    v-slot="{ id, aria }"
                    :label="t('settings.booking.step')"
                    :error="form.errors.value.slot_step_minutes"
                    :hint="t('settings.booking.stepHint')"
                >
                    <select :id="id" v-model="form.data.slot_step_minutes" v-bind="aria" class="input sm:w-64">
                        <option v-for="minutes in stepChoices" :key="minutes" :value="minutes">
                            {{ duration(minutes) }}
                        </option>
                    </select>
                </FormField>

                <div class="row">
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('settings.booking.bufferBefore')"
                        :error="form.errors.value.buffer_before_minutes"
                    >
                        <select :id="id" v-model="form.data.buffer_before_minutes" v-bind="aria" class="input">
                            <option v-for="minutes in beforeChoices" :key="minutes" :value="minutes">
                                {{ bufferLabel(minutes) }}
                            </option>
                        </select>
                    </FormField>
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('settings.booking.bufferAfter')"
                        :error="form.errors.value.buffer_after_minutes"
                    >
                        <select :id="id" v-model="form.data.buffer_after_minutes" v-bind="aria" class="input">
                            <option v-for="minutes in afterChoices" :key="minutes" :value="minutes">
                                {{ bufferLabel(minutes) }}
                            </option>
                        </select>
                    </FormField>
                </div>
                <p class="-mt-1 mb-3 text-xs text-ink-3">{{ t('settings.booking.bufferHint') }}</p>

                <div class="divider" />
                <div class="eyebrow mb-3">{{ t('settings.booking.changesTitle') }}</div>

                <div class="row">
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('settings.booking.rescheduleUntil')"
                        :error="form.errors.value.client_reschedule_until_hours"
                    >
                        <select :id="id" v-model="form.data.client_reschedule_until_hours" v-bind="aria" class="input">
                            <option v-for="hours in rescheduleChoices" :key="hours ?? 'never'" :value="hours">
                                {{ changeLabel(hours) }}
                            </option>
                        </select>
                    </FormField>
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('settings.booking.cancelUntil')"
                        :error="form.errors.value.client_cancel_until_hours"
                    >
                        <select :id="id" v-model="form.data.client_cancel_until_hours" v-bind="aria" class="input">
                            <option v-for="hours in cancelChoices" :key="hours ?? 'never'" :value="hours">
                                {{ changeLabel(hours) }}
                            </option>
                        </select>
                    </FormField>
                </div>
                <p class="-mt-1 mb-3 text-xs text-ink-3">{{ t('settings.booking.changesHint') }}</p>

                <div class="divider" />

                <FormField
                    v-slot="{ id, aria }"
                    :label="t('settings.booking.clientNote')"
                    :error="form.errors.value.client_note"
                    :hint="t('settings.booking.clientNoteHint')"
                >
                    <textarea
                        :id="id"
                        v-model="form.data.client_note"
                        v-bind="aria"
                        class="input min-h-20"
                        rows="3"
                        maxlength="2000"
                    />
                </FormField>

                <button class="btn btn-primary" type="submit" :disabled="form.processing.value">
                    {{ t('common.save') }}
                </button>
            </fieldset>
        </form>
    </section>
</template>
