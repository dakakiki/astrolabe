<script setup>
import { computed, onMounted, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';

import FormField from '@/components/FormField.vue';
import { useForm } from '@/composables/useForm';
import { timeZoneOptions } from '@/composables/useLabels';
import {
    addStretch,
    canAddStretch,
    copyMondayToWeekdays,
    EXCEPTION_KINDS,
    exceptionForm,
    exceptionPayload,
    exceptionPeriod,
    MAX_STRETCHES,
    removeStretch,
    stretchKey,
    switchDay,
    upcomingExceptions,
    validateException,
    validateWeek,
    weekdayName,
    weekErrors,
    weekForm,
    weekPayload,
    withWholeDays,
} from '@/lib/availability';
import http from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

/**
 * Settings → Availability (docs/spec/12, "Zakazivanje (9b)"): the signed-in
 * person's own working week — stretches of wall-clock time per weekday in one
 * zone — and their time off and extra time. The server turns them into the
 * free times the client portal offers; clients never learn why a time is not
 * offered, and the notes stay here.
 */
const { t, locale } = useI18n();
const auth = useAuthStore();
const toast = useToastStore();
// Prefix for the ids that tie each weekday's switch and errors to their labels.
const uid = useId();

const loaded = ref(false);
const loadFailed = ref(false);
// What the server holds: the empty state and each exception's zone label follow it, not the form.
const savedRules = ref([]);
const savedZone = ref(auth.user.timezone || 'UTC');
const exceptions = ref([]);
// Who receives portal bookings (the owner until teams); null when unknown.
const astrologer = ref(null);

/* The working week */

const week = useForm({ timezone: savedZone.value, days: weekForm([]) });
const attempted = ref(false);
const serverErrors = ref({ stretches: {}, other: {} });

const zones = computed(() => timeZoneOptions(week.data.timezone));
const checks = computed(() => (attempted.value ? validateWeek(week.data.days) : {}));
const generalErrors = computed(() =>
    Object.entries(serverErrors.value.other)
        .filter(([field]) => field !== 'timezone')
        .map(([, message]) => message),
);
const notYours = computed(() => astrologer.value && astrologer.value.id !== auth.user.id);

const dayName = (weekday) => weekdayName(weekday, locale.value);

function stretchError(weekday, index) {
    const key = stretchKey(weekday, index);
    const server = serverErrors.value.stretches[key];
    if (server) return server;

    return checks.value[key] ? t(`settings.availability.errors.${checks.value[key]}`) : null;
}

function dayError(weekday) {
    return checks.value[weekday]
        ? t(`settings.availability.errors.${checks.value[weekday]}`, { max: MAX_STRETCHES })
        : null;
}

/** Replaces one day; a change to the week's shape makes the server's row errors stale. */
function changeDay(weekday, change) {
    week.data.days = week.data.days.map((day) => (day.weekday === weekday ? change(day) : day));
    serverErrors.value = { ...serverErrors.value, stretches: {} };
}

function copyMonday() {
    week.data.days = copyMondayToWeekdays(week.data.days);
    serverErrors.value = { ...serverErrors.value, stretches: {} };
}

function apply(data) {
    const previousZone = savedZone.value;

    savedRules.value = data.rules ?? [];
    savedZone.value = data.timezone || previousZone;
    exceptions.value = upcomingExceptions(data.exceptions ?? []);
    week.reset({ timezone: savedZone.value, days: weekForm(savedRules.value) });

    // New time off is entered in the week's zone unless another one was picked.
    if (exception.data.timezone === previousZone) exception.data.timezone = savedZone.value;
}

async function load() {
    const [availability, policy] = await Promise.allSettled([http.get('/availability'), http.get('/booking-policy')]);

    if (availability.status === 'fulfilled') {
        apply(availability.value.data.data);
    } else {
        loadFailed.value = true;
    }
    if (policy.status === 'fulfilled') astrologer.value = policy.value.data.data.astrologer ?? null;

    loaded.value = true;
}

onMounted(load);

async function save() {
    attempted.value = true;
    serverErrors.value = { stretches: {}, other: {} };

    if (Object.keys(validateWeek(week.data.days)).length) {
        toast.error(t('settings.availability.check'));
        return;
    }

    const days = week.data.days;

    try {
        await week.submit(async (data) => {
            const response = await http.put('/availability', weekPayload(data.days, data.timezone));
            apply(response.data.data);
            attempted.value = false;
            toast.success(t('settings.availability.saved'));
        });
    } catch {
        serverErrors.value = weekErrors(week.errors.value, days);
    }
}

/* Time off and extra time */

const exception = useForm(exceptionForm(savedZone.value));
const exceptionChecks = ref({});
const removing = ref(null);

const exceptionZones = computed(() => timeZoneOptions(exception.data.timezone));
const kindLabel = (kind) => t(`settings.availability.exceptions.kinds.${kind}`);
const period = (item) => exceptionPeriod(item, locale.value);

function exceptionError(field) {
    if (exception.errors.value[field]) return exception.errors.value[field];
    const code = exceptionChecks.value[field];

    return code ? t(`settings.availability.exceptions.errors.${code}`) : null;
}

function setWholeDays(allDay) {
    Object.assign(exception.data, withWholeDays({ ...exception.data }, allDay));
    exceptionChecks.value = {};
}

// Picking a first day after the last one moves the last one along.
watch(
    () => exception.data.first_day,
    (first) => {
        if (first && exception.data.last_day && exception.data.last_day < first) exception.data.last_day = first;
    },
);

async function addException() {
    exceptionChecks.value = validateException(exception.data);
    if (Object.keys(exceptionChecks.value).length) return;

    await exception
        .submit(async (data) => {
            const response = await http.post('/availability/exceptions', exceptionPayload(data));
            const created = response.data.data;
            exceptions.value = upcomingExceptions([...exceptions.value, created]);

            // Over by the time it was saved: it is not listed and counts for nothing, so no "added".
            if (!exceptions.value.some((item) => item.id === created.id)) {
                exceptionChecks.value = { ends_at: 'ended' };
                return;
            }

            // The next entry starts blank but keeps the kind, zone and whole-days choice just used.
            exception.reset({
                ...exceptionForm(savedZone.value),
                kind: data.kind,
                all_day: data.all_day,
                timezone: data.timezone,
            });
            toast.success(t('settings.availability.exceptions.added'));
        })
        .catch(() => {});
}

async function removeException(item) {
    const words = { kind: kindLabel(item.kind), period: period(item) };
    if (!window.confirm(t('settings.availability.exceptions.confirmRemove', words))) return;

    removing.value = item.id;
    try {
        await http.delete(`/availability/exceptions/${item.id}`);
        exceptions.value = exceptions.value.filter((entry) => entry.id !== item.id);
        toast.success(t('settings.availability.exceptions.removed'));
    } catch (error) {
        // Already gone (removed in another tab): the list just catches up.
        if (error.response?.status === 404) {
            exceptions.value = exceptions.value.filter((entry) => entry.id !== item.id);
        } else {
            toast.error(error.response?.status === 429 ? t('errors.tooManyAttempts') : t('errors.generic'));
        }
    } finally {
        removing.value = null;
    }
}
</script>

<template>
    <div v-if="notYours" class="notice n-neutral">
        {{ t('settings.availability.notYours', { name: astrologer.name }) }}
    </div>

    <section class="card">
        <div class="card-head">
            <h2>{{ t('settings.availability.title') }}</h2>
        </div>
        <div v-if="!loaded" class="card-body text-ink-3">{{ t('common.loading') }}</div>
        <div v-else-if="loadFailed" class="card-body">
            <p class="notice n-danger">{{ t('errors.generic') }}</p>
        </div>
        <form v-else class="card-body" novalidate @submit.prevent="save">
            <p class="mb-3 text-ink-3">{{ t('settings.availability.intro') }}</p>

            <p v-if="!savedRules.length" class="notice n-warn mb-4">{{ t('settings.availability.empty') }}</p>

            <FormField
                v-slot="{ id, aria }"
                :label="t('settings.availability.timezone')"
                :error="serverErrors.other.timezone"
                :hint="t('settings.availability.timezoneHint')"
            >
                <select :id="id" v-model="week.data.timezone" v-bind="aria" class="input sm:w-80">
                    <option v-for="zone in zones" :key="zone" :value="zone">{{ zone }}</option>
                </select>
            </FormField>

            <ul class="mb-2 divide-y divide-line-soft border-y border-line-soft">
                <li
                    v-for="day in week.data.days"
                    :key="day.weekday"
                    class="grid grid-cols-1 gap-2 py-3 sm:grid-cols-[150px_minmax(0,1fr)]"
                >
                    <div class="flex min-h-[34px] items-center gap-3 sm:self-start">
                        <span :id="`${uid}-weekday-${day.weekday}`" class="font-medium text-ink">
                            {{ dayName(day.weekday) }}
                        </span>
                        <button
                            type="button"
                            class="switch"
                            role="switch"
                            :aria-checked="day.on ? 'true' : 'false'"
                            :aria-labelledby="`${uid}-weekday-${day.weekday}`"
                            @click="changeDay(day.weekday, (entry) => switchDay(entry, !entry.on))"
                        />
                    </div>

                    <div v-if="day.on" class="min-w-0 space-y-2">
                        <div v-for="(stretch, index) in day.stretches" :key="index">
                            <div class="flex max-w-sm items-center gap-2">
                                <input
                                    v-model="stretch.start"
                                    class="input min-w-0 flex-1"
                                    type="time"
                                    step="900"
                                    required
                                    :aria-label="t('settings.availability.from', { day: dayName(day.weekday) })"
                                    :aria-invalid="stretchError(day.weekday, index) ? 'true' : undefined"
                                    :aria-describedby="
                                        stretchError(day.weekday, index)
                                            ? `${uid}-stretch-${day.weekday}-${index}-error`
                                            : undefined
                                    "
                                />
                                <span class="text-ink-3" aria-hidden="true">–</span>
                                <input
                                    v-model="stretch.end"
                                    class="input min-w-0 flex-1"
                                    type="time"
                                    step="900"
                                    required
                                    :aria-label="t('settings.availability.until', { day: dayName(day.weekday) })"
                                    :aria-invalid="stretchError(day.weekday, index) ? 'true' : undefined"
                                    :aria-describedby="
                                        stretchError(day.weekday, index)
                                            ? `${uid}-stretch-${day.weekday}-${index}-error`
                                            : undefined
                                    "
                                />
                                <button
                                    type="button"
                                    class="icon-btn"
                                    :aria-label="
                                        t('settings.availability.remove', {
                                            from: stretch.start,
                                            until: stretch.end,
                                            day: dayName(day.weekday),
                                        })
                                    "
                                    @click="changeDay(day.weekday, (entry) => removeStretch(entry, index))"
                                >
                                    ×
                                </button>
                            </div>
                            <div
                                v-if="stretchError(day.weekday, index)"
                                :id="`${uid}-stretch-${day.weekday}-${index}-error`"
                                class="mt-1 text-xs text-danger"
                            >
                                {{ stretchError(day.weekday, index) }}
                            </div>
                        </div>
                        <div v-if="dayError(day.weekday)" class="text-xs text-danger">
                            {{ dayError(day.weekday) }}
                        </div>
                        <button
                            v-if="canAddStretch(day)"
                            type="button"
                            class="btn btn-ghost btn-sm"
                            @click="changeDay(day.weekday, addStretch)"
                        >
                            + {{ t('settings.availability.addHours') }}
                        </button>
                    </div>
                    <div v-else class="text-sm text-ink-3 sm:pt-2">{{ t('settings.availability.off') }}</div>
                </li>
            </ul>

            <div class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-2">
                <button type="button" class="btn btn-ghost btn-sm" @click="copyMonday">
                    {{ t('settings.availability.copyMonday') }}
                </button>
                <span class="text-xs text-ink-3">{{ t('settings.availability.midnight') }}</span>
            </div>

            <p v-for="message in generalErrors" :key="message" class="notice n-danger mb-3">{{ message }}</p>

            <p class="notice n-neutral mb-4">{{ t('settings.availability.private') }}</p>

            <button class="btn btn-primary" type="submit" :disabled="week.processing.value">
                {{ t('common.save') }}
            </button>
        </form>
    </section>

    <section v-if="loaded && !loadFailed" class="card">
        <div class="card-head">
            <h2>{{ t('settings.availability.exceptions.title') }}</h2>
        </div>
        <div class="card-body">
            <p class="mb-3 text-ink-3">{{ t('settings.availability.exceptions.intro') }}</p>

            <div class="eyebrow mb-1">{{ t('settings.availability.exceptions.planned') }}</div>
            <ul v-if="exceptions.length" class="mb-2 divide-y divide-line-soft">
                <li v-for="item in exceptions" :key="item.id" class="flex flex-wrap items-start gap-x-3 gap-y-1 py-3">
                    <span class="badge mt-0.5" :class="{ 'b-ok': item.kind === 'available' }">
                        {{ kindLabel(item.kind) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="text-ink">{{ period(item) }}</div>
                        <div v-if="item.timezone && item.timezone !== savedZone" class="text-xs text-ink-3">
                            {{ t('settings.availability.exceptions.inZone', { zone: item.timezone }) }}
                        </div>
                        <div v-if="item.note" class="text-xs break-words text-ink-3">{{ item.note }}</div>
                    </div>
                    <button
                        type="button"
                        class="btn btn-ghost btn-sm"
                        :disabled="removing === item.id"
                        :aria-label="
                            t('settings.availability.exceptions.removeLabel', {
                                kind: kindLabel(item.kind),
                                period: period(item),
                            })
                        "
                        @click="removeException(item)"
                    >
                        {{ t('settings.availability.exceptions.remove') }}
                    </button>
                </li>
            </ul>
            <p v-else class="mb-2 py-2 text-ink-3">{{ t('settings.availability.exceptions.empty') }}</p>

            <form class="border-t border-line-soft pt-4" novalidate @submit.prevent="addException">
                <div class="eyebrow mb-3">{{ t('settings.availability.exceptions.new') }}</div>

                <div class="field">
                    <span class="label">{{ t('settings.availability.exceptions.kind') }}</span>
                    <div class="seg" role="group" :aria-label="t('settings.availability.exceptions.kind')">
                        <button
                            v-for="kind in EXCEPTION_KINDS"
                            :key="kind"
                            type="button"
                            :aria-pressed="exception.data.kind === kind"
                            @click="exception.data.kind = kind"
                        >
                            {{ kindLabel(kind) }}
                        </button>
                    </div>
                </div>

                <label class="mb-3 flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        :checked="exception.data.all_day"
                        @change="setWholeDays($event.target.checked)"
                    />
                    {{ t('settings.availability.exceptions.wholeDays') }}
                </label>

                <div v-if="exception.data.all_day" class="row">
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('settings.availability.exceptions.firstDay')"
                        :error="exceptionError('starts_at')"
                    >
                        <input
                            :id="id"
                            v-model="exception.data.first_day"
                            v-bind="aria"
                            class="input"
                            type="date"
                            required
                        />
                    </FormField>
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('settings.availability.exceptions.lastDay')"
                        :error="exceptionError('ends_at')"
                    >
                        <input
                            :id="id"
                            v-model="exception.data.last_day"
                            v-bind="aria"
                            class="input"
                            type="date"
                            :min="exception.data.first_day || undefined"
                            required
                        />
                    </FormField>
                </div>
                <div v-else class="row">
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('settings.availability.exceptions.starts')"
                        :error="exceptionError('starts_at')"
                    >
                        <input
                            :id="id"
                            v-model="exception.data.starts_local"
                            v-bind="aria"
                            class="input"
                            type="datetime-local"
                            step="900"
                            required
                        />
                    </FormField>
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('settings.availability.exceptions.ends')"
                        :error="exceptionError('ends_at')"
                    >
                        <input
                            :id="id"
                            v-model="exception.data.ends_local"
                            v-bind="aria"
                            class="input"
                            type="datetime-local"
                            step="900"
                            :min="exception.data.starts_local || undefined"
                            required
                        />
                    </FormField>
                </div>

                <div class="row">
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('settings.availability.exceptions.timezone')"
                        :error="exceptionError('timezone')"
                    >
                        <select :id="id" v-model="exception.data.timezone" v-bind="aria" class="input">
                            <option v-for="zone in exceptionZones" :key="zone" :value="zone">{{ zone }}</option>
                        </select>
                    </FormField>
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('settings.availability.exceptions.note')"
                        :error="exceptionError('note')"
                        :hint="t('settings.availability.exceptions.noteHint')"
                    >
                        <input
                            :id="id"
                            v-model="exception.data.note"
                            v-bind="aria"
                            class="input"
                            maxlength="500"
                            autocomplete="off"
                        />
                    </FormField>
                </div>

                <p v-if="exceptionError('kind') || exceptionError('all_day')" class="notice n-danger mb-3">
                    {{ exceptionError('kind') || exceptionError('all_day') }}
                </p>

                <button class="btn btn-primary" type="submit" :disabled="exception.processing.value">
                    {{ t('settings.availability.exceptions.add') }}
                </button>
            </form>
        </div>
    </section>
</template>
