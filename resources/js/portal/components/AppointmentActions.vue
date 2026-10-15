<script setup>
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

import FormField from '@/components/FormField.vue';
import { formatDateTime } from '@/lib/datetime';
import http, { validationErrors } from '@/portal/http';
import { bookingProblem, changeDeadlines, changeOptions, idempotencyKey } from '@/portal/lib/booking';
import { useToastStore } from '@/stores/toast';

/**
 * What the client can do with an upcoming appointment (docs/spec/12,
 * "Zakazivanje (9b)"): reschedule or cancel here until the practice's
 * deadline — cancelling asks once, with an optional message only the
 * practice sees — and afterwards a note to contact the practice instead.
 * Emits `changed` when the list should be read again.
 */
const props = defineProps({
    appointment: { type: Object, required: true },
    timeZone: { type: String, required: true },
});

const emit = defineEmits(['changed']);

const { t, locale } = useI18n();
const toast = useToastStore();

const options = computed(() => changeOptions(props.appointment));
const deadlines = computed(() => changeDeadlines(props.appointment));
const request = computed(() => props.appointment.status === 'requested');
// The reschedule screen draws the appointment at once from the history entry (a plain copy).
const state = computed(() => ({ appointment: JSON.parse(JSON.stringify(props.appointment)) }));

const confirming = ref(false);
const reason = ref('');
const error = ref(null);
const busy = ref(false);
const form = ref(null);
// One key per "Cancel this appointment?": pressing it again after a lost answer cancels once.
let requestKey = null;

function until(at) {
    return formatDateTime(at, locale.value, props.timeZone);
}

async function ask() {
    requestKey = idempotencyKey('cancel');
    confirming.value = true;
    await nextTick();
    form.value?.querySelector('textarea')?.focus();
}

function keep() {
    confirming.value = false;
    reason.value = '';
    error.value = null;
}

async function cancel() {
    busy.value = true;
    error.value = null;
    const text = reason.value.trim();

    try {
        await http.post(`/appointments/${props.appointment.id}/cancel`, text ? { reason: text } : {}, {
            headers: { 'Idempotency-Key': requestKey },
        });
        toast.success(t(request.value ? 'portal.appointments.requestCancelled' : 'portal.appointments.cancelled'));
        keep();
        emit('changed');
    } catch (failure) {
        const problem = bookingProblem(failure);

        if (problem.code === 'invalid') {
            error.value = validationErrors(failure)?.reason ?? t('portal.errors.generic');
        } else if (problem.code === 'too_many') {
            error.value = t('portal.booking.tooMany');
        } else if (['change_too_late', 'not_changeable', 'booking_disabled', 'not_found'].includes(problem.code)) {
            // The deadline passed (or the practice changed it) while the page was open.
            toast.error(t('portal.appointments.tooLate'));
            keep();
            emit('changed');
        } else if (problem.code !== 'handled') {
            toast.error(t('portal.errors.generic'));
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <p v-if="options.contact" class="mt-2 text-xs text-ink-3">{{ t('portal.appointments.contact') }}</p>

    <div v-else>
        <div v-if="!confirming" class="mt-3 flex flex-wrap gap-2">
            <RouterLink
                v-if="options.reschedule"
                :to="{ name: 'reschedule', params: { id: appointment.id }, state }"
                class="btn btn-sm"
                >{{ t('portal.appointments.reschedule') }}</RouterLink
            >
            <button v-if="options.cancel" type="button" class="btn btn-sm btn-danger" @click="ask">
                {{ request ? t('portal.appointments.cancelRequest') : t('portal.appointments.cancel') }}
            </button>
        </div>

        <form v-else ref="form" class="cancel-panel mt-3" novalidate @submit.prevent="cancel">
            <p class="mb-3 text-sm font-medium">
                {{ request ? t('portal.appointments.cancelRequestTitle') : t('portal.appointments.cancelTitle') }}
            </p>
            <FormField
                v-slot="{ id, aria }"
                :label="t('portal.appointments.cancelMessage')"
                :hint="t('portal.booking.messageHint')"
                :error="error"
            >
                <textarea :id="id" v-bind="aria" v-model="reason" class="input block" rows="2" maxlength="500" />
            </FormField>
            <div class="flex flex-wrap gap-2">
                <button type="submit" class="btn btn-sm btn-danger" :disabled="busy">
                    {{
                        request ? t('portal.appointments.cancelRequestConfirm') : t('portal.appointments.cancelConfirm')
                    }}
                </button>
                <button type="button" class="btn btn-sm btn-ghost" :disabled="busy" @click="keep">
                    {{ request ? t('portal.appointments.keepRequest') : t('portal.appointments.keep') }}
                </button>
            </div>
        </form>

        <p v-for="line in deadlines" :key="line.key" class="mt-2 text-xs text-ink-3">
            {{ t(`portal.appointments.until.${line.key}`, { when: until(line.at) }) }}
        </p>
    </div>
</template>
