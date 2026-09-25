<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import BillingBadge from '@/components/BillingBadge.vue';
import ConsultationStatusBadge from '@/components/ConsultationStatusBadge.vue';
import PaymentForm from '@/components/PaymentForm.vue';
import PaymentsTable from '@/components/PaymentsTable.vue';
import { useMoney } from '@/composables/useMoney';
import { todayIn } from '@/lib/calendar';
import { formatDateTime } from '@/lib/datetime';
import http from '@/lib/http';
import { PAYMENT_METHODS, PERIODS, paymentFilters } from '@/lib/payments';
import { useAuthStore } from '@/stores/auth';
import { useReferenceStore } from '@/stores/reference';
import { useToastStore } from '@/stores/toast';

/**
 * What clients paid the practice (docs/spec/02, "Plaćanja"): this month, last
 * month, this year and what is still owed; the payments with filters, totals
 * and a CSV export; and the consultations waiting on payment. The tab lives
 * in the URL (`?view=waiting`), so the dashboard can link to it.
 */
const { t, locale } = useI18n();
const route = useRoute();
const router = useRouter();
const money = useMoney();
const auth = useAuthStore();
const reference = useReferenceStore();
const toast = useToastStore();

const today = todayIn(auth.user?.timezone ?? 'UTC');
const view = computed(() => (route.query.view === 'waiting' ? 'waiting' : 'received'));

const summary = ref(null);
const payments = ref([]);
const meta = ref(null);
const totals = ref([]);
const waiting = ref(null);
const loading = ref(true);
const busyId = ref(null);
const exporting = ref(false);

// One client's payments (from their profile): all of them, not just this month's.
const clientId = computed(() => Number(route.query.client) || null);
const clientName = ref('');
const filters = ref({ period: clientId.value ? 'all' : 'this_month', method: '', kind: '', search: '' });
const params = computed(() => paymentFilters({ ...filters.value, today, clientId: clientId.value }));

// The form beside the list: a new payment, one for a waiting consultation, or one being edited.
const form = ref(null);
const formKey = ref(0);
const formCard = ref(null);

async function loadSummary() {
    const { data } = await http.get('/payments/summary');
    summary.value = data.data;
}

async function loadPayments(page = 1) {
    loading.value = true;
    try {
        const { data } = await http.get('/payments', { params: { ...params.value, page } });
        payments.value = page === 1 ? data.data : [...payments.value, ...data.data];
        meta.value = data.meta;
        totals.value = data.totals;
    } finally {
        loading.value = false;
    }
}

async function loadWaiting() {
    const { data } = await http.get('/consultations', {
        params: { billing: 'owed', sort: 'starts_at', per_page: 100 },
    });
    waiting.value = data.data;
}

function load() {
    return Promise.all([loadSummary(), view.value === 'waiting' ? loadWaiting() : loadPayments()]);
}

async function loadClientName() {
    clientName.value = '';
    if (!clientId.value) return;
    const { data } = await http.get(`/clients/${clientId.value}`);
    clientName.value = data.data.full_name;
}

onMounted(() => {
    reference.load();
    load();
    loadClientName();
});
watch(clientId, () => {
    loadClientName();
    loadPayments();
});
watch(view, () => (view.value === 'waiting' ? loadWaiting() : loadPayments()));

let searchTimer = null;
watch(
    () => [filters.value.period, filters.value.method, filters.value.kind],
    () => loadPayments(),
);
watch(
    () => filters.value.search,
    () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => loadPayments(), 300);
    },
);

function clearClient() {
    router.replace({ query: { ...route.query, client: undefined } });
}

function setView(next) {
    router.replace({ query: { ...route.query, view: next === 'received' ? undefined : next } });
}

async function openForm(options = {}) {
    form.value = options;
    formKey.value++;

    await nextTick();
    if (window.matchMedia?.('(max-width: 1023px)').matches) {
        formCard.value?.$el?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function closeForm() {
    form.value = null;
}

async function saved() {
    closeForm();
    await load();
}

async function remove(payment) {
    if (!window.confirm(t('payments.confirmDelete'))) return;

    busyId.value = payment.id;
    try {
        await http.delete(`/payments/${payment.id}`);
        toast.success(t('payments.deleted'));
        await load();
    } catch {
        toast.error(t('errors.generic'));
    } finally {
        busyId.value = null;
    }
}

// The filtered list as a file; fetched with the session like any other request.
async function exportCsv() {
    exporting.value = true;
    try {
        const response = await http.get('/payments/export', { params: params.value, responseType: 'blob' });
        const name = /filename="?([^";]+)"?/.exec(response.headers['content-disposition'] ?? '')?.[1] ?? 'payments.csv';
        const url = URL.createObjectURL(response.data);
        const link = Object.assign(document.createElement('a'), { href: url, download: name });
        document.body.append(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    } catch {
        toast.error(t('errors.generic'));
    } finally {
        exporting.value = false;
    }
}

const monthName = (key) =>
    new Intl.DateTimeFormat(locale.value, { month: 'long', timeZone: 'UTC' }).format(new Date(`${key}T00:00:00Z`));

const stats = computed(() => {
    const data = summary.value;
    if (!data) return [];

    return [
        { key: 'thisMonth', label: monthName(data.this_month.from), value: data.this_month.received },
        { key: 'lastMonth', label: monthName(data.last_month.from), value: data.last_month.received },
        { key: 'thisYear', label: data.this_year.from.slice(0, 4), value: data.this_year.received },
    ];
});

const held = (consultation) =>
    consultation.starts_at
        ? formatDateTime(consultation.starts_at, locale.value, auth.user?.timezone, { dateStyle: 'medium' })
        : '—';
</script>

<template>
    <div class="page-head flex flex-wrap items-end gap-4">
        <div class="min-w-0">
            <div class="eyebrow">{{ t('payments.eyebrow') }}</div>
            <h1>{{ t('payments.title') }}</h1>
            <div class="sub">{{ t('payments.sub') }}</div>
        </div>
        <div class="ml-auto flex flex-wrap gap-2">
            <button
                v-if="view === 'received'"
                type="button"
                class="btn"
                :disabled="exporting || !payments.length"
                :title="t('payments.csvHint')"
                @click="exportCsv"
            >
                {{ t('payments.export') }}
            </button>
            <button type="button" class="btn btn-primary" @click="openForm()">+ {{ t('payments.record') }}</button>
        </div>
    </div>

    <div class="mb-4 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <div v-for="stat in stats" :key="stat.key" class="card stat">
            <div class="k">{{ stat.label }}</div>
            <div class="v text-xl!">
                <span v-for="total in stat.value" :key="total.currency" class="block">{{ money.format(total) }}</span>
                <span v-if="!stat.value.length">—</span>
            </div>
            <div class="d">{{ t('payments.stats.received') }}</div>
        </div>
        <a v-if="summary" href="#" class="card stat block hover:border-brand-300" @click.prevent="setView('waiting')">
            <div class="k">{{ t('payments.stats.outstanding') }}</div>
            <div class="v text-xl!">
                <span v-for="total in summary.outstanding.total" :key="total.currency" class="block">{{
                    money.format(total)
                }}</span>
                <span v-if="!summary.outstanding.total.length">—</span>
            </div>
            <div class="d">
                {{ t('payments.stats.owed', { count: summary.outstanding.count }, summary.outstanding.count) }}
            </div>
        </a>
    </div>

    <nav class="tabs" :aria-label="t('payments.title')">
        <a
            v-for="name in ['received', 'waiting']"
            :key="name"
            href="#"
            :aria-current="view === name ? 'page' : undefined"
            @click.prevent="setView(name)"
        >
            {{ t(`payments.tabs.${name}`) }}
            <span v-if="name === 'waiting' && summary?.outstanding.count" class="count">{{
                summary.outstanding.count
            }}</span>
        </a>
    </nav>

    <div class="grid grid-cols-1 items-start gap-4" :class="{ 'lg:grid-cols-[minmax(0,1fr)_380px]': form }">
        <!-- Received -->
        <section v-if="view === 'received'" class="card min-w-0">
            <div class="filters flex flex-wrap items-end gap-2 border-b border-line-soft p-3">
                <label class="grid gap-1 text-xs text-ink-3">
                    {{ t('payments.filters.period') }}
                    <select v-model="filters.period" class="input h-9! w-auto!">
                        <option v-for="period in PERIODS" :key="period" :value="period">
                            {{ t(`payments.periods.${period}`) }}
                        </option>
                    </select>
                </label>
                <label class="grid gap-1 text-xs text-ink-3">
                    {{ t('payments.filters.method') }}
                    <select v-model="filters.method" class="input h-9! w-auto!">
                        <option value="">{{ t('payments.filters.anyMethod') }}</option>
                        <option v-for="method in PAYMENT_METHODS" :key="method" :value="method">
                            {{ t(`payments.methods.${method}`) }}
                        </option>
                    </select>
                </label>
                <label class="grid gap-1 text-xs text-ink-3">
                    {{ t('payments.filters.kind') }}
                    <select v-model="filters.kind" class="input h-9! w-auto!">
                        <option value="">{{ t('payments.filters.anyKind') }}</option>
                        <option value="payment">{{ t('payments.kinds.payment') }}</option>
                        <option value="refund">{{ t('payments.kinds.refund') }}</option>
                    </select>
                </label>
                <span v-if="clientId && clientName" class="tag mb-1 inline-flex items-center gap-1.5">
                    {{ clientName }}
                    <button type="button" :aria-label="t('common.close')" @click="clearClient">✕</button>
                </span>
                <label class="grid min-w-48 flex-1 gap-1 text-xs text-ink-3">
                    {{ t('payments.filters.search') }}
                    <input
                        v-model="filters.search"
                        type="search"
                        class="input h-9!"
                        :placeholder="t('payments.filters.searchPlaceholder')"
                    />
                </label>
            </div>

            <div v-if="payments.length" class="overflow-x-auto">
                <PaymentsTable
                    :payments="payments"
                    :totals="totals"
                    :busy-id="busyId"
                    @edit="openForm({ payment: $event })"
                    @remove="remove"
                />
            </div>
            <p v-else-if="!loading" class="empty">
                {{
                    filters.period === 'all' && !filters.method && !filters.kind && !filters.search
                        ? t('payments.empty')
                        : t('payments.emptyFiltered')
                }}
            </p>
            <p v-else class="p-6 text-ink-3" role="status">{{ t('common.loading') }}</p>

            <div v-if="meta && meta.current_page < meta.last_page" class="border-t border-line-soft p-3">
                <button
                    type="button"
                    class="btn btn-sm"
                    :disabled="loading"
                    @click="loadPayments(meta.current_page + 1)"
                >
                    {{ t('common.more') }}
                </button>
            </div>
        </section>

        <!-- Waiting on payment -->
        <section v-else class="card min-w-0">
            <div class="card-head">
                <h2>{{ t('payments.tabs.waiting') }}</h2>
                <span class="right text-xs text-ink-3">{{ t('payments.waitingHint') }}</span>
            </div>
            <div v-if="waiting?.length" class="overflow-x-auto">
                <table class="data">
                    <caption class="sr-only">
                        {{
                            t('payments.tabs.waiting')
                        }}
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ t('payments.columns.held') }}</th>
                            <th scope="col">{{ t('payments.columns.client') }}</th>
                            <th scope="col">{{ t('payments.columns.for') }}</th>
                            <th scope="col" class="text-right">{{ t('payments.columns.fee') }}</th>
                            <th scope="col" class="hidden text-right md:table-cell">
                                {{ t('payments.columns.received') }}
                            </th>
                            <th scope="col" class="text-right">{{ t('payments.columns.owes') }}</th>
                            <th scope="col">
                                <span class="sr-only">{{ t('payments.record') }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="consultation in waiting" :key="consultation.id" class="cursor-default!">
                            <td class="font-mono text-xs whitespace-nowrap">{{ held(consultation) }}</td>
                            <td>
                                <RouterLink
                                    :to="{ name: 'clients.show', params: { id: consultation.client.id } }"
                                    class="hover:underline"
                                    >{{ consultation.client.full_name }}</RouterLink
                                >
                            </td>
                            <td>
                                <RouterLink
                                    :to="{ name: 'consultations.show', params: { id: consultation.id } }"
                                    class="hover:underline"
                                    >{{
                                        consultation.title ?? consultation.service?.name ?? t('consultations.untitled')
                                    }}</RouterLink
                                >
                                <div class="mt-0.5 flex flex-wrap gap-1">
                                    <ConsultationStatusBadge
                                        v-if="consultation.status !== 'completed'"
                                        :status="consultation.status"
                                    />
                                    <BillingBadge :status="consultation.billing.status" />
                                </div>
                            </td>
                            <td class="text-right font-mono whitespace-nowrap">{{ money.format(consultation.fee) }}</td>
                            <td class="hidden text-right font-mono whitespace-nowrap md:table-cell">
                                {{ money.format(consultation.billing.paid) || '—' }}
                            </td>
                            <td class="text-right font-mono font-semibold whitespace-nowrap">
                                {{ money.format(consultation.billing.balance) }}
                            </td>
                            <td class="text-right">
                                <button type="button" class="btn btn-sm" @click="openForm({ consultation })">
                                    {{ t('payments.record') }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else-if="waiting" class="empty">{{ t('payments.waitingEmpty') }}</p>
            <p v-else class="p-6 text-ink-3" role="status">{{ t('common.loading') }}</p>
        </section>

        <PaymentForm
            v-if="form"
            ref="formCard"
            :key="formKey"
            :payment="form.payment ?? null"
            :consultation="form.consultation ?? null"
            :client="form.consultation?.client ?? null"
            closable
            @saved="saved"
            @cancel="closeForm"
        />
    </div>
</template>
