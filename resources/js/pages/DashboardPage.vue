<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

import AppointmentStatusBadge from '@/components/calendar/AppointmentStatusBadge.vue';
import TaskList from '@/components/TaskList.vue';
import { useLabels } from '@/composables/useLabels';
import { wallClock } from '@/lib/calendar';
import { formatDateTime, isFuture } from '@/lib/datetime';
import { fileKind } from '@/lib/files';
import { formatRelative, initials } from '@/lib/format';
import http from '@/lib/http';
import { serviceColorClass } from '@/lib/services';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

/**
 * The start screen (docs/spec/02, "Dashboard"): the astrologer's day and the
 * week ahead — appointments, tasks that are due, the clients and files worked
 * on lately, and clients whose chart cannot be drawn yet. A new practice sees
 * its setup steps first. Payments and transits join in Phase 7.
 */
const { t, locale } = useI18n();
const labels = useLabels();
const auth = useAuthStore();
const toast = useToastStore();

const board = ref(null);
const failed = ref(false);
const methods = ref(null);
const busyId = ref(null);

const zone = computed(() => auth.user?.timezone ?? 'UTC');

async function load() {
    try {
        const { data } = await http.get('/dashboard');
        board.value = data.data;
        failed.value = false;
    } catch {
        failed.value = true;
    }
}

onMounted(async () => {
    await load();
    // Setup steps are only for a practice without clients yet.
    if (board.value?.counts.clients_total === 0) {
        const { data } = await http.get('/astrology-methods');
        methods.value = data.data.filter((method) => method.selected);
    }
});

const counts = computed(() => board.value?.counts ?? {});
const firstName = computed(() => auth.user?.name.split(/\s+/)[0] ?? '');

const greeting = computed(() => {
    const hour = Math.floor(wallClock(new Date().toISOString(), zone.value).minutes / 60);
    const part = hour >= 5 && hour < 12 ? 'morning' : hour >= 12 && hour < 18 ? 'afternoon' : 'evening';

    return t(`dashboard.greeting.${part}`, { name: firstName.value });
});

const today = computed(() => {
    try {
        return new Intl.DateTimeFormat(locale.value, {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric',
            timeZone: zone.value,
        }).format(new Date());
    } catch {
        return '';
    }
});

const summary = computed(() => {
    if (!board.value) return '';
    const { appointments_today: booked, tasks_overdue: overdue, tasks_today: due } = counts.value;

    return [
        t('dashboard.sub.appointments', { count: booked }, booked),
        overdue ? t('dashboard.sub.overdue', { count: overdue }, overdue) : null,
        due ? t('dashboard.sub.dueToday', { count: due }, due) : null,
    ]
        .filter(Boolean)
        .join(' · ');
});

const stats = computed(() => [
    {
        key: t('dashboard.stats.today'),
        value: counts.value.appointments_today,
        detail: t('dashboard.stats.todayDetail', counts.value.appointments_today ?? 0),
        to: { name: 'calendar', query: { view: 'day' } },
    },
    {
        key: t('dashboard.stats.week'),
        value: counts.value.appointments_upcoming,
        detail: t('dashboard.stats.weekDetail', counts.value.appointments_upcoming ?? 0),
        to: { name: 'calendar', query: { view: 'agenda' } },
    },
    {
        key: t('dashboard.stats.tasks'),
        value: counts.value.tasks_open,
        detail: t('dashboard.stats.tasksDetail', {
            overdue: counts.value.tasks_overdue,
            today: counts.value.tasks_today,
        }),
        to: { name: 'tasks.index' },
    },
    {
        key: t('dashboard.stats.clients'),
        value: counts.value.clients_active,
        detail: t(
            'dashboard.stats.clientsDetail',
            { count: counts.value.clients_new_this_month },
            counts.value.clients_new_this_month ?? 0,
        ),
        to: { name: 'clients.index' },
    },
]);

// Appointments: today's, then the week ahead, each under its own heading.
const nextUp = computed(() =>
    board.value
        ? [
              { label: t('dashboard.nextUp.today'), items: board.value.appointments.today, today: true },
              { label: t('dashboard.nextUp.upcoming'), items: board.value.appointments.upcoming, today: false },
          ]
        : [],
);
const nothingBooked = computed(
    () => board.value && !board.value.appointments.today.length && !board.value.appointments.upcoming.length,
);

function when(appointment, isToday) {
    return formatDateTime(
        appointment.starts_at,
        locale.value,
        zone.value,
        isToday
            ? { timeStyle: 'short' }
            : { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' },
    );
}

// Held, and not yet written up: offer to record the consultation.
const canRecord = (appointment) =>
    !appointment.consultation && appointment.status !== 'cancelled' && !isFuture(appointment.starts_at);

// Tasks: overdue, due today, then the rest of the week.
const taskGroups = computed(() =>
    board.value
        ? [
              { key: 'overdue', items: board.value.tasks.overdue, total: counts.value.tasks_overdue },
              { key: 'today', items: board.value.tasks.today, total: counts.value.tasks_today },
              { key: 'upcoming', items: board.value.tasks.upcoming, total: board.value.tasks.upcoming.length },
          ].filter((group) => group.items.length)
        : [],
);

async function completeTask(task) {
    busyId.value = task.id;
    try {
        await http.patch(`/tasks/${task.id}`, { status: 'done' });
        toast.success(t('tasks.done'));
        await load();
    } catch {
        toast.error(t('errors.generic'));
    } finally {
        busyId.value = null;
    }
}

const fileGlyph = { image: '▣', pdf: '▤', document: '▤', audio: '♫', video: '▶', text: '≡', file: '▢', link: '↗' };
const glyphOf = (file) => fileGlyph[file.kind === 'link' ? 'link' : fileKind(file.mime_type)] ?? fileGlyph.file;
const fileRoute = (file) =>
    file.consultation_id
        ? { name: 'consultations.show', params: { id: file.consultation_id } }
        : { name: 'clients.show', params: { id: file.client_id }, query: { tab: 'files' } };

const missingText = (client) =>
    (client.birth?.chart.missing ?? ['birth_date']).map((field) => t(`clients.missing.${field}`)).join(', ');

const chartDefaults = computed(() => {
    const workspace = auth.workspace;
    if (!workspace) return '';
    const parts = [
        labels.zodiacMode(workspace.default_zodiac_mode),
        labels.houseSystem(workspace.default_house_system),
    ];
    if (workspace.default_ayanamsa) parts.push(labels.ayanamsa(workspace.default_ayanamsa));
    return parts.join(' · ');
});

const steps = computed(() => [
    { label: t('dashboard.setup.methods'), to: { name: 'settings.chart' }, done: (methods.value?.length ?? 0) > 0 },
    { label: t('dashboard.setup.chart'), to: { name: 'settings.chart' } },
    { label: t('dashboard.setup.regional'), to: { name: 'settings.regional' } },
    { label: t('dashboard.setup.clients'), to: { name: 'clients.create' } },
]);
</script>

<template>
    <div class="page-head flex flex-wrap items-end gap-4">
        <div class="min-w-0">
            <div class="eyebrow">{{ today }}</div>
            <h1>{{ greeting }}</h1>
            <div class="sub">{{ summary || auth.workspace?.name }}</div>
        </div>
        <div class="ml-auto flex flex-wrap gap-2">
            <RouterLink :to="{ name: 'calendar', query: { new: 1 } }" class="btn">{{
                t('dashboard.newAppointment')
            }}</RouterLink>
            <RouterLink :to="{ name: 'clients.create' }" class="btn btn-primary">{{
                t('dashboard.addClient')
            }}</RouterLink>
        </div>
    </div>

    <p v-if="failed" class="notice n-warn mb-4" role="alert">{{ t('errors.generic') }}</p>

    <template v-if="board">
        <div class="mb-4 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <RouterLink
                v-for="stat in stats"
                :key="stat.key"
                :to="stat.to"
                class="card stat block hover:border-brand-300"
            >
                <div class="k">{{ stat.key }}</div>
                <div class="v">{{ stat.value }}</div>
                <div class="d">{{ stat.detail }}</div>
            </RouterLink>
        </div>

        <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_368px]">
            <div class="min-w-0 space-y-4">
                <!-- A new practice: what to set up first -->
                <section v-if="counts.clients_total === 0" class="card">
                    <div class="card-head">
                        <h2>{{ t('dashboard.setup.title') }}</h2>
                    </div>
                    <ul class="card-body space-y-1">
                        <li
                            v-for="step in steps"
                            :key="step.label"
                            class="flex items-center gap-3 rounded-md px-2 py-2"
                        >
                            <span
                                class="grid size-5 place-items-center rounded-full border text-[11px]"
                                :class="step.done ? 'border-ok bg-ok-bg text-ok' : 'border-line text-ink-4'"
                                aria-hidden="true"
                                >{{ step.done ? '✓' : '' }}</span
                            >
                            <RouterLink :to="step.to" class="flex-1 hover:underline">{{ step.label }}</RouterLink>
                        </li>
                    </ul>
                </section>

                <!-- Appointments -->
                <section class="card">
                    <div class="card-head">
                        <h2>{{ t('dashboard.nextUp.title') }}</h2>
                        <RouterLink :to="{ name: 'calendar' }" class="right">{{
                            t('dashboard.nextUp.calendar')
                        }}</RouterLink>
                    </div>
                    <p v-if="nothingBooked" class="empty">{{ t('dashboard.nextUp.empty') }}</p>
                    <div v-else class="overflow-x-auto">
                        <table class="data">
                            <tbody v-for="group in nextUp" :key="group.label">
                                <tr>
                                    <th colspan="4" class="bg-surface-2">{{ group.label }}</th>
                                </tr>
                                <tr v-if="!group.items.length">
                                    <td colspan="4" class="text-ink-3">{{ t('dashboard.nextUp.noneToday') }}</td>
                                </tr>
                                <tr v-for="appointment in group.items" :key="appointment.id">
                                    <td class="w-28 font-mono text-xs whitespace-nowrap">
                                        {{ when(appointment, group.today) }}
                                    </td>
                                    <td>
                                        <div class="person">
                                            <span class="ini" aria-hidden="true">{{
                                                initials(appointment.client?.full_name)
                                            }}</span>
                                            <span class="min-w-0">
                                                <RouterLink
                                                    :to="{
                                                        name: 'clients.show',
                                                        params: { id: appointment.client_id },
                                                    }"
                                                    class="nm block truncate hover:underline"
                                                    >{{ appointment.client?.full_name }}</RouterLink
                                                >
                                                <span
                                                    v-if="appointment.service"
                                                    class="flex items-center gap-1.5 text-xs text-ink-3"
                                                >
                                                    <span
                                                        class="inline-block size-2 rounded-sm"
                                                        :class="serviceColorClass(appointment.service.color)"
                                                        aria-hidden="true"
                                                    />{{ appointment.service.name }}
                                                </span>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="hidden text-xs text-ink-3 sm:table-cell">
                                        {{ labels.locationType(appointment.location_type) }}
                                    </td>
                                    <td class="text-right whitespace-nowrap">
                                        <AppointmentStatusBadge
                                            v-if="appointment.status !== 'scheduled'"
                                            :status="appointment.status"
                                            class="mr-1"
                                        />
                                        <RouterLink
                                            v-if="canRecord(appointment)"
                                            :to="{
                                                name: 'consultations.create',
                                                query: { appointment: appointment.id },
                                            }"
                                            class="btn btn-sm mr-1"
                                            >{{ t('dashboard.nextUp.record') }}</RouterLink
                                        >
                                        <RouterLink
                                            v-else-if="appointment.consultation"
                                            :to="{
                                                name: 'consultations.show',
                                                params: { id: appointment.consultation.id },
                                            }"
                                            class="btn btn-sm btn-ghost mr-1"
                                            >{{ t('dashboard.nextUp.consultation') }}</RouterLink
                                        >
                                        <RouterLink
                                            :to="{ name: 'calendar', query: { appointment: appointment.id } }"
                                            class="btn btn-sm"
                                            >{{ t('dashboard.nextUp.open') }}</RouterLink
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- New files -->
                <section class="card">
                    <div class="card-head">
                        <h2>{{ t('dashboard.files.title') }}</h2>
                    </div>
                    <p v-if="!board.recent_files.length" class="empty">{{ t('dashboard.files.empty') }}</p>
                    <ul v-else class="card-body space-y-2">
                        <li v-for="file in board.recent_files" :key="file.id" class="flex items-center gap-3 text-sm">
                            <span class="w-5 text-center text-ink-3" aria-hidden="true">{{ glyphOf(file) }}</span>
                            <RouterLink :to="fileRoute(file)" class="min-w-0 flex-1 truncate hover:underline">{{
                                file.name
                            }}</RouterLink>
                            <RouterLink
                                v-if="file.client"
                                :to="{ name: 'clients.show', params: { id: file.client.id } }"
                                class="hidden truncate text-xs text-ink-3 hover:underline sm:block"
                                >{{ file.client.full_name }}</RouterLink
                            >
                            <span class="text-xs whitespace-nowrap text-ink-4">{{
                                formatRelative(file.created_at, locale)
                            }}</span>
                        </li>
                    </ul>
                </section>
            </div>

            <div class="min-w-0 space-y-4">
                <!-- Tasks -->
                <section class="card">
                    <div class="card-head">
                        <h2>{{ t('dashboard.tasks.title') }}</h2>
                        <RouterLink :to="{ name: 'tasks.index' }" class="right">{{
                            t('dashboard.tasks.all')
                        }}</RouterLink>
                    </div>
                    <div class="card-body">
                        <p v-if="!taskGroups.length" class="text-sm text-ink-3">{{ t('dashboard.tasks.empty') }}</p>
                        <div v-for="group in taskGroups" :key="group.key" class="mb-3 last:mb-0">
                            <h3
                                class="text-[10.5px] font-semibold tracking-widest uppercase"
                                :class="group.key === 'overdue' ? 'text-danger' : 'text-ink-4'"
                            >
                                {{ t(`dashboard.tasks.${group.key}`) }}
                            </h3>
                            <TaskList :tasks="group.items" :editable="false" :busy-id="busyId" @toggle="completeTask" />
                            <RouterLink
                                v-if="group.total > group.items.length"
                                :to="{ name: 'tasks.index', query: { show: group.key } }"
                                class="text-xs"
                                >{{
                                    t('dashboard.tasks.more', { count: group.total - group.items.length })
                                }}</RouterLink
                            >
                        </div>
                    </div>
                </section>

                <!-- Recently active clients -->
                <section class="card">
                    <div class="card-head">
                        <h2>{{ t('dashboard.recent.title') }}</h2>
                        <RouterLink :to="{ name: 'clients.index' }" class="right">{{
                            t('dashboard.recent.all')
                        }}</RouterLink>
                    </div>
                    <p v-if="!board.recent_clients.length" class="empty">{{ t('dashboard.recent.empty') }}</p>
                    <ul v-else class="card-body space-y-3">
                        <li v-for="client in board.recent_clients" :key="client.id" class="person">
                            <span class="ini" aria-hidden="true">{{ initials(client.full_name) }}</span>
                            <span class="min-w-0">
                                <RouterLink
                                    :to="{ name: 'clients.show', params: { id: client.id } }"
                                    class="nm block truncate hover:underline"
                                    >{{ client.full_name }}</RouterLink
                                >
                                <span class="meta">{{ formatRelative(client.last_activity_at, locale) }}</span>
                            </span>
                        </li>
                    </ul>
                </section>

                <!-- Clients whose chart cannot be drawn yet -->
                <section v-if="board.incomplete_birth_data.length" class="card">
                    <div class="card-head">
                        <h2>{{ t('dashboard.attention.title') }}</h2>
                        <span class="right font-mono">{{ counts.incomplete_birth_data }}</span>
                    </div>
                    <div class="card-body space-y-2.5">
                        <div v-for="client in board.incomplete_birth_data" :key="client.id" class="notice n-warn">
                            <span class="n-ico" aria-hidden="true">△</span>
                            <div class="min-w-0">
                                <strong class="block">{{ client.full_name }}</strong>
                                {{ t('dashboard.attention.birth') }} — {{ missingText(client) }}
                                <div class="mt-1.5">
                                    <RouterLink
                                        :to="{ name: 'clients.edit', params: { id: client.id } }"
                                        class="btn btn-sm"
                                        >{{ t('dashboard.attention.complete') }}</RouterLink
                                    >
                                </div>
                            </div>
                        </div>
                        <p
                            v-if="counts.incomplete_birth_data > board.incomplete_birth_data.length"
                            class="text-xs text-ink-3"
                        >
                            {{
                                t('dashboard.attention.more', {
                                    count: counts.incomplete_birth_data - board.incomplete_birth_data.length,
                                })
                            }}
                        </p>
                    </div>
                </section>

                <!-- A new practice: its settings at a glance -->
                <section v-if="counts.clients_total === 0" class="card">
                    <div class="card-head">
                        <h2>{{ t('dashboard.practice.title') }}</h2>
                    </div>
                    <dl class="card-body space-y-3 text-sm">
                        <div>
                            <dt class="eyebrow">{{ t('dashboard.practice.methods') }}</dt>
                            <dd class="flex flex-wrap gap-1.5">
                                <template v-if="methods?.length">
                                    <span v-for="method in methods" :key="method.id" class="method-pill">
                                        {{ labels.method(method) }}
                                    </span>
                                </template>
                                <span v-else-if="methods" class="text-ink-3">{{
                                    t('dashboard.practice.noMethods')
                                }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="eyebrow">{{ t('dashboard.practice.chart') }}</dt>
                            <dd>{{ chartDefaults }}</dd>
                        </div>
                        <div>
                            <dt class="eyebrow">{{ t('dashboard.practice.timezone') }}</dt>
                            <dd class="font-mono text-xs">{{ auth.workspace?.timezone }}</dd>
                        </div>
                    </dl>
                </section>
            </div>
        </div>
    </template>

    <p v-else-if="!failed" class="text-ink-3" role="status">{{ t('common.loading') }}</p>
</template>
