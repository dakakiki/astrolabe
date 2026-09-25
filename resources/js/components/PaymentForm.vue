<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

import ClientPicker from '@/components/ClientPicker.vue';
import FormField from '@/components/FormField.vue';
import { useForm } from '@/composables/useForm';
import { useMoney } from '@/composables/useMoney';
import { todayIn } from '@/lib/calendar';
import { formatDateTime } from '@/lib/datetime';
import http, { idempotencyKey } from '@/lib/http';
import { fromMinorUnits, toMinorUnits } from '@/lib/money';
import { fixedCurrency, PAYMENT_METHODS, paymentDefaults } from '@/lib/payments';
import { useAuthStore } from '@/stores/auth';
import { useReferenceStore } from '@/stores/reference';
import { useToastStore } from '@/stores/toast';

/**
 * Records or corrects a payment (docs/spec/02, "Plaćanja"): money received,
 * or — as a refund — given back. Opened from the payments page (any client,
 * optionally one of their consultations), from a consultation (for it), or
 * from an appointment (a deposit). A consultation's payments stay in its
 * currency, and a new one starts from what it still owes. A new payment
 * carries an Idempotency-Key, so a double click records it once. Remount
 * (`:key`) to show another payment.
 */
const props = defineProps({
    /** The payment to edit, or null for a new one. */
    payment: { type: Object, default: null },
    /** A fixed client, { id, full_name }. */
    client: { type: Object, default: null },
    /** A fixed consultation, with its `fee` and `billing`. */
    consultation: { type: Object, default: null },
    /** A fixed appointment, { id, starts_at }: the payment is a deposit for it. */
    appointment: { type: Object, default: null },
    /** Show a close button (the form is not always on screen). */
    closable: { type: Boolean, default: false },
    /** Title and button for a narrow card. */
    compact: { type: Boolean, default: false },
});
const emit = defineEmits(['saved', 'cancel']);

const { t, locale } = useI18n();
const money = useMoney();
const auth = useAuthStore();
const reference = useReferenceStore();
const toast = useToastStore();

const editing = computed(() => props.payment !== null);
const today = todayIn(auth.user?.timezone ?? 'UTC');
const requestKey = idempotencyKey('payment');

// A deposit — for an appointment, not (yet) for its consultation — stays one when edited.
const depositFor = computed(
    () => props.appointment ?? (props.payment?.consultation_id ? null : (props.payment?.appointment ?? null)),
);

// The client and what the payment is for, unless the form was opened for them.
const choosesClient = computed(() => !props.client && !props.consultation && !props.appointment);
const choosesPurpose = computed(() => !props.consultation && !depositFor.value);
const chosen = ref(props.payment?.client ?? props.client ?? null);
const options = ref([]);
const forId = ref(props.payment?.consultation_id ?? '');

const target = computed(
    () => props.consultation ?? options.value.find((option) => option.id === Number(forId.value)) ?? null,
);
const lockedCurrency = computed(() => fixedCurrency(target.value));

const initial = props.payment
    ? {
          kind: props.payment.kind,
          amount: fromMinorUnits(props.payment.amount, money.decimals(props.payment.currency)),
          currency: props.payment.currency,
          paid_on: props.payment.paid_on,
          method: props.payment.method ?? '',
          reference: props.payment.reference ?? '',
          notes: props.payment.notes ?? '',
      }
    : paymentDefaults({
          consultation: props.consultation,
          currency: auth.workspace?.default_currency ?? 'EUR',
          today,
          decimals: money.decimals,
      });

const form = useForm(initial);
const amountError = ref(null);

onMounted(() => {
    reference.load();
    if (chosen.value && choosesPurpose.value) loadOptions();
});

// The chosen client's consultations, most recent first, to pay one of them.
async function loadOptions() {
    options.value = [];
    if (!chosen.value) return;

    const { data } = await http.get('/consultations', { params: { client_id: chosen.value.id, per_page: 50 } });
    options.value = data.data;
}

function pickClient(client) {
    chosen.value = client;
    forId.value = '';
    loadOptions();
}

// A new payment for a consultation starts from what it owes, in its currency.
watch(forId, () => {
    if (editing.value) return;
    const defaults = paymentDefaults({
        consultation: target.value,
        currency: form.data.currency,
        today,
        decimals: money.decimals,
    });
    form.data.amount = defaults.amount;
    form.data.currency = defaults.currency;
});

watch(lockedCurrency, (currency) => {
    if (currency) form.data.currency = currency;
});

function optionLabel(option) {
    const title = option.title ?? option.service?.name ?? t('consultations.untitled');
    const date = option.starts_at
        ? formatDateTime(option.starts_at, locale.value, auth.user?.timezone, { dateStyle: 'medium' })
        : '—';
    const balance = option.billing?.balance;

    return balance && balance.amount > 0
        ? t('payments.form.owes', { title, date, amount: money.format(balance) })
        : t('payments.form.settled', { title, date });
}

const heading = computed(() => {
    if (editing.value) return t('payments.form.editTitle');

    return props.appointment ? t('payments.form.depositTitle') : t('payments.form.newTitle');
});

async function save() {
    const amount = toMinorUnits(form.data.amount, money.decimals(form.data.currency));
    amountError.value = amount === null || Number.isNaN(amount) ? t('payments.form.amountInvalid') : null;
    if (amountError.value) return;

    try {
        const response = await form.submit((data) => {
            const payload = {
                kind: data.kind,
                amount,
                currency: data.currency,
                paid_on: data.paid_on,
                method: data.method || null,
                reference: data.reference.trim() || null,
                notes: data.notes.trim() || null,
            };

            if (choosesPurpose.value) payload.consultation_id = forId.value ? Number(forId.value) : null;
            if (choosesClient.value && chosen.value) payload.client_id = chosen.value.id;

            if (editing.value) return http.patch(`/payments/${props.payment.id}`, payload);

            return http.post(
                '/payments',
                {
                    ...payload,
                    client_id: chosen.value?.id ?? props.client?.id ?? null,
                    consultation_id: props.consultation?.id ?? payload.consultation_id ?? null,
                    appointment_id: props.appointment?.id ?? null,
                },
                { headers: { 'Idempotency-Key': requestKey } },
            );
        });

        const saved = response.data.data;
        toast.success(
            editing.value
                ? t('payments.saved')
                : saved.kind === 'refund'
                  ? t('payments.refundCreated')
                  : t('payments.created'),
        );
        emit('saved', saved);
    } catch {
        // Shown next to the fields, or as a toast.
    }
}
</script>

<template>
    <form :class="compact ? 'space-y-1' : 'card'" novalidate @submit.prevent="save">
        <div v-if="!compact" class="card-head">
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
        <div :class="{ 'card-body': !compact }">
            <FormField
                v-if="choosesClient && !editing"
                v-slot="{ id, aria }"
                :label="t('payments.form.client')"
                :error="form.errors.value.client_id"
            >
                <ClientPicker :input-id="id" :aria="aria" :selected="chosen?.full_name" @select="pickClient" />
                <p v-if="chosen" class="mt-1.5 text-xs text-ink-3">✓ {{ chosen.full_name }}</p>
            </FormField>
            <p v-else-if="chosen && !client && !consultation" class="mb-3 text-sm font-medium">
                {{ chosen.full_name }}
            </p>
            <p v-if="depositFor && !appointment" class="mb-3 text-sm text-ink-3">
                {{
                    t('payments.depositFor', {
                        date: formatDateTime(depositFor.starts_at, locale, auth.user?.timezone, {
                            dateStyle: 'medium',
                        }),
                    })
                }}
            </p>

            <FormField
                v-if="choosesPurpose && chosen"
                v-slot="{ id, aria }"
                :label="t('payments.form.for')"
                :error="form.errors.value.consultation_id || form.errors.value.appointment_id"
                :hint="t('payments.form.forHint')"
            >
                <select :id="id" v-model="forId" v-bind="aria" class="input">
                    <option value="">{{ t('payments.form.forNothing') }}</option>
                    <option v-for="option in options" :key="option.id" :value="option.id">
                        {{ optionLabel(option) }}
                    </option>
                </select>
            </FormField>

            <div class="field">
                <span class="label">{{ t('payments.form.kind') }}</span>
                <div class="seg" role="group" :aria-label="t('payments.form.kind')">
                    <button
                        v-for="kind in ['payment', 'refund']"
                        :key="kind"
                        type="button"
                        :aria-pressed="form.data.kind === kind"
                        @click="form.data.kind = kind"
                    >
                        {{ t(`payments.kinds.${kind}`) }}
                    </button>
                </div>
            </div>

            <div class="row">
                <FormField
                    v-slot="{ id, aria }"
                    :label="t('payments.form.amount')"
                    :error="amountError || form.errors.value.amount"
                >
                    <input
                        :id="id"
                        v-model="form.data.amount"
                        v-bind="aria"
                        class="input font-mono"
                        inputmode="decimal"
                        autocomplete="off"
                        required
                    />
                </FormField>
                <FormField
                    v-slot="{ id, aria }"
                    :label="t('payments.form.currency')"
                    :error="form.errors.value.currency"
                    :hint="lockedCurrency ? t('payments.form.currencyFixed', { currency: lockedCurrency }) : null"
                >
                    <select
                        :id="id"
                        v-model="form.data.currency"
                        v-bind="aria"
                        class="input"
                        :disabled="Boolean(lockedCurrency)"
                    >
                        <option
                            v-for="code in reference.data?.currencies ?? [form.data.currency]"
                            :key="code"
                            :value="code"
                        >
                            {{ code }}
                        </option>
                    </select>
                </FormField>
            </div>

            <div class="row">
                <FormField
                    v-slot="{ id, aria }"
                    :label="form.data.kind === 'refund' ? t('payments.form.refundedOn') : t('payments.form.paidOn')"
                    :error="form.errors.value.paid_on"
                >
                    <input
                        :id="id"
                        v-model="form.data.paid_on"
                        v-bind="aria"
                        class="input"
                        type="date"
                        :max="today"
                        required
                    />
                </FormField>
                <FormField v-slot="{ id, aria }" :label="t('payments.form.method')" :error="form.errors.value.method">
                    <select :id="id" v-model="form.data.method" v-bind="aria" class="input">
                        <option value="">{{ t('payments.form.methodNone') }}</option>
                        <option v-for="method in PAYMENT_METHODS" :key="method" :value="method">
                            {{ t(`payments.methods.${method}`) }}
                        </option>
                    </select>
                </FormField>
            </div>

            <FormField
                v-slot="{ id, aria }"
                :label="t('payments.form.reference')"
                :error="form.errors.value.reference"
                :hint="t('payments.form.referenceHint')"
            >
                <input
                    :id="id"
                    v-model="form.data.reference"
                    v-bind="aria"
                    class="input"
                    maxlength="100"
                    autocomplete="off"
                />
            </FormField>

            <FormField v-slot="{ id, aria }" :label="t('payments.form.notes')" :error="form.errors.value.notes">
                <textarea :id="id" v-model="form.data.notes" v-bind="aria" class="input min-h-16" maxlength="2000" />
            </FormField>

            <div class="flex justify-end gap-2">
                <button v-if="closable || editing" type="button" class="btn btn-ghost" @click="emit('cancel')">
                    {{ t('common.cancel') }}
                </button>
                <button type="submit" class="btn btn-primary" :disabled="form.processing.value">
                    {{ editing ? t('payments.form.save') : t('payments.form.create') }}
                </button>
            </div>
        </div>
    </form>
</template>
