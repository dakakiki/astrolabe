<script setup>
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

import { useMoney } from '@/composables/useMoney';
import { formatDateTime } from '@/lib/datetime';
import { formatDate } from '@/lib/format';
import { signedAmount } from '@/lib/payments';
import { useAuthStore } from '@/stores/auth';

/**
 * Payments as a table, newest first as the API sends them: the day, the
 * client, what it was for, the amount (a refund negative), how it came and
 * its reference. Editing and removing are left to the parent.
 */
defineProps({
    payments: { type: Array, required: true },
    showClient: { type: Boolean, default: true },
    editable: { type: Boolean, default: true },
    busyId: { type: Number, default: null },
    /** What the rows add up to, per currency, for the footer. */
    totals: { type: Array, default: null },
});
const emit = defineEmits(['edit', 'remove']);

const { t, locale } = useI18n();
const money = useMoney();
const auth = useAuthStore();

const day = (payment) => formatDate(payment.paid_on, locale.value, 'medium');
const when = (iso) => formatDateTime(iso, locale.value, auth.user?.timezone, { dateStyle: 'medium' });
const amount = (payment) => money.format({ amount: signedAmount(payment), currency: payment.currency });
</script>

<template>
    <table class="data">
        <caption class="sr-only">
            {{
                t('payments.title')
            }}
        </caption>
        <thead>
            <tr>
                <th scope="col">{{ t('payments.columns.date') }}</th>
                <th v-if="showClient" scope="col">{{ t('payments.columns.client') }}</th>
                <th scope="col">{{ t('payments.columns.for') }}</th>
                <th scope="col" class="text-right">{{ t('payments.columns.amount') }}</th>
                <th scope="col" class="hidden md:table-cell">{{ t('payments.columns.method') }}</th>
                <th scope="col" class="hidden lg:table-cell">{{ t('payments.columns.reference') }}</th>
                <th v-if="editable" scope="col">
                    <span class="sr-only">{{ t('common.edit') }}</span>
                </th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="payment in payments" :key="payment.id" class="cursor-default!">
                <td class="font-mono text-xs whitespace-nowrap">{{ day(payment) }}</td>
                <td v-if="showClient">
                    <RouterLink
                        v-if="payment.client"
                        :to="{ name: 'clients.show', params: { id: payment.client.id } }"
                        class="hover:underline"
                        >{{ payment.client.full_name }}</RouterLink
                    >
                </td>
                <td class="text-sm">
                    <RouterLink
                        v-if="payment.consultation"
                        :to="{ name: 'consultations.show', params: { id: payment.consultation.id } }"
                        class="hover:underline"
                        >{{ payment.consultation.title ?? t('consultations.untitled') }}</RouterLink
                    >
                    <span v-if="payment.consultation?.starts_at" class="block text-xs text-ink-3">{{
                        when(payment.consultation.starts_at)
                    }}</span>
                    <RouterLink
                        v-else-if="payment.appointment"
                        :to="{ name: 'calendar', query: { appointment: payment.appointment.id } }"
                        class="hover:underline"
                        >{{ t('payments.depositFor', { date: when(payment.appointment.starts_at) }) }}</RouterLink
                    >
                    <span v-else-if="!payment.consultation" class="text-ink-4">—</span>
                    <span
                        v-if="payment.notes"
                        class="block max-w-64 truncate text-xs text-ink-3"
                        :title="payment.notes"
                        >{{ payment.notes }}</span
                    >
                </td>
                <td
                    class="text-right font-mono whitespace-nowrap"
                    :class="{ 'text-danger': payment.kind === 'refund' }"
                >
                    {{ amount(payment) }}
                    <span v-if="payment.kind === 'refund'" class="block font-sans text-xs">{{
                        t('payments.kinds.refund')
                    }}</span>
                </td>
                <td class="hidden text-xs text-ink-3 md:table-cell">
                    {{ payment.method ? t(`payments.methods.${payment.method}`) : '—' }}
                </td>
                <td class="hidden text-xs text-ink-3 lg:table-cell">{{ payment.reference ?? '—' }}</td>
                <td v-if="editable" class="text-right whitespace-nowrap">
                    <button type="button" class="btn btn-ghost btn-sm" @click="emit('edit', payment)">
                        {{ t('payments.edit') }}
                    </button>
                    <button
                        type="button"
                        class="btn btn-ghost btn-sm text-danger"
                        :disabled="busyId === payment.id"
                        @click="emit('remove', payment)"
                    >
                        {{ t('payments.delete') }}
                    </button>
                </td>
            </tr>
        </tbody>
        <tfoot v-if="totals?.length">
            <tr>
                <th scope="row" :colspan="showClient ? 3 : 2" class="text-right">{{ t('payments.total') }}</th>
                <td class="text-right font-mono font-semibold whitespace-nowrap">
                    <span v-for="total in totals" :key="total.currency" class="block">{{ money.format(total) }}</span>
                </td>
                <td :colspan="editable ? 3 : 2" class="hidden md:table-cell"></td>
            </tr>
        </tfoot>
    </table>
</template>
