<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import BillingBadge from '@/components/BillingBadge.vue';
import PaymentForm from '@/components/PaymentForm.vue';
import { useMoney } from '@/composables/useMoney';
import { formatDate } from '@/lib/format';
import http from '@/lib/http';
import { signedAmount } from '@/lib/payments';
import { useToastStore } from '@/stores/toast';

/**
 * A consultation's money (docs/spec/02, "Plaćanja"): its fee, what came in,
 * what went back and what is still to pay, with the payments themselves —
 * deposits for its appointment among them — and a form to record the next.
 * The fee itself is set with the consultation's details.
 */
const props = defineProps({
    /** The consultation with `fee`, `billing`, `payments`, `client` and `status`. */
    consultation: { type: Object, required: true },
});
const emit = defineEmits(['changed']);

const { t, locale } = useI18n();
const money = useMoney();
const toast = useToastStore();

const billing = computed(() => props.consultation.billing ?? {});
const payments = computed(() => props.consultation.payments ?? []);
const overpaid = computed(() => billing.value.balance && billing.value.balance.amount < 0);
// A fee not yet due — the session is still to come, or was cancelled — is not owed.
const notDue = computed(
    () =>
        billing.value.balance?.amount > 0 &&
        props.consultation.fee?.amount > 0 &&
        !billing.value.owed &&
        props.consultation.status !== 'completed',
);

const form = ref(null);
const formKey = ref(0);
const busyId = ref(null);

function open(payment = null) {
    form.value = { payment };
    formKey.value++;
}

function saved() {
    form.value = null;
    emit('changed');
}

async function remove(payment) {
    if (!window.confirm(t('payments.confirmDelete'))) return;

    busyId.value = payment.id;
    try {
        await http.delete(`/payments/${payment.id}`);
        toast.success(t('payments.deleted'));
        emit('changed');
    } catch {
        toast.error(t('errors.generic'));
    } finally {
        busyId.value = null;
    }
}

const negate = (value) => (value ? { ...value, amount: -value.amount } : null);
</script>

<template>
    <section class="card">
        <div class="card-head">
            <h2>{{ t('billing.title') }}</h2>
            <span class="right"><BillingBadge :status="billing.status" /></span>
        </div>
        <div class="card-body space-y-3">
            <dl class="facts">
                <dt>{{ t('billing.fee') }}</dt>
                <dd class="font-mono">
                    <template v-if="consultation.fee?.amount === 0">{{ t('billing.statuses.no_charge') }}</template>
                    <template v-else-if="consultation.fee">{{ money.format(consultation.fee) }}</template>
                    <span v-else class="font-sans text-ink-3">{{ t('billing.noFee') }}</span>
                </dd>
                <template v-if="billing.paid">
                    <dt>{{ t('billing.received') }}</dt>
                    <dd class="font-mono">{{ money.format(billing.paid) }}</dd>
                </template>
                <template v-if="billing.refunded">
                    <dt>{{ t('billing.refunded') }}</dt>
                    <dd class="font-mono">{{ money.format(billing.refunded) }}</dd>
                </template>
                <template v-if="billing.balance && billing.balance.amount > 0">
                    <dt>{{ t('billing.balance') }}</dt>
                    <dd class="font-mono font-semibold">{{ money.format(billing.balance) }}</dd>
                </template>
            </dl>

            <p v-if="overpaid" class="text-xs text-ink-3">
                {{ t('billing.overpaid', { amount: money.format(negate(billing.balance)) }) }}
            </p>
            <p v-if="notDue" class="text-xs text-ink-3">
                {{ t('billing.notDue', { status: t(`consultationStatuses.${consultation.status}`).toLowerCase() }) }}
            </p>

            <div>
                <div class="label">{{ t('billing.payments') }}</div>
                <ul v-if="payments.length" class="divide-y divide-line-soft text-sm">
                    <li
                        v-for="payment in payments"
                        :key="payment.id"
                        class="flex flex-wrap items-center gap-x-2 py-1.5"
                    >
                        <span class="font-mono text-xs text-ink-3">{{
                            formatDate(payment.paid_on, locale, 'medium')
                        }}</span>
                        <span class="font-mono" :class="{ 'text-danger': payment.kind === 'refund' }">{{
                            money.format({ amount: signedAmount(payment), currency: payment.currency })
                        }}</span>
                        <span class="text-xs text-ink-3">
                            {{ payment.method ? t(`payments.methods.${payment.method}`) : '' }}
                            <template v-if="payment.appointment_id"> · {{ t('billing.deposit') }}</template>
                        </span>
                        <span class="ml-auto flex">
                            <button type="button" class="btn btn-ghost btn-sm" @click="open(payment)">
                                {{ t('payments.edit') }}
                            </button>
                            <button
                                type="button"
                                class="btn btn-ghost btn-sm text-danger"
                                :disabled="busyId === payment.id"
                                @click="remove(payment)"
                            >
                                {{ t('payments.delete') }}
                            </button>
                        </span>
                    </li>
                </ul>
                <p v-else class="text-sm text-ink-3">{{ t('billing.noPayments') }}</p>
            </div>

            <button v-if="!form" type="button" class="btn btn-sm" @click="open()">+ {{ t('payments.record') }}</button>

            <div v-if="form" class="border-t border-line-soft pt-3">
                <h3 class="mb-2 text-sm font-semibold">
                    {{ form.payment ? t('payments.form.editTitle') : t('payments.form.newTitle') }}
                </h3>
                <PaymentForm
                    :key="formKey"
                    :payment="form.payment"
                    :consultation="consultation"
                    :client="consultation.client"
                    compact
                    closable
                    @saved="saved"
                    @cancel="form = null"
                />
            </div>
            <p class="text-xs text-ink-3">{{ t('billing.feeHint') }}</p>
        </div>
    </section>
</template>
