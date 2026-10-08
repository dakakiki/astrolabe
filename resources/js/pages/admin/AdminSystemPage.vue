<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import { formatDateTime } from '@/lib/datetime';
import { formatBytes } from '@/lib/files';
import { formatRelative } from '@/lib/format';
import http from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

/**
 * The admin's System screen (Phase 8c): health checks, versions, sizes,
 * backups and the queue; failed jobs can be run again or dropped.
 */
const { t, locale } = useI18n();
const auth = useAuthStore();
const toast = useToastStore();

const system = ref(null);
const busy = ref(null);

const when = (iso) => (iso ? formatDateTime(iso, locale.value, auth.user?.timezone || 'UTC') : '—');
const bytes = (value) => (value === null || value === undefined ? '—' : formatBytes(value, locale.value));
const allOk = computed(() => system.value && Object.values(system.value.checks).every(Boolean));

async function load() {
    try {
        const { data } = await http.get('/admin/system');
        system.value = data.data;
    } catch {
        toast.error(t('errors.generic'));
    }
}

async function act(job, kind) {
    busy.value = job.uuid;

    try {
        if (kind === 'retry') await http.post(`/admin/failed-jobs/${job.uuid}/retry`);
        else await http.delete(`/admin/failed-jobs/${job.uuid}`);
        toast.success(t(kind === 'retry' ? 'admin.system.retried' : 'admin.system.forgotten'));
        await load();
    } catch {
        toast.error(t('errors.generic'));
    } finally {
        busy.value = null;
    }
}

onMounted(load);
</script>

<template>
    <div class="page-head">
        <div class="eyebrow">{{ t('admin.badge') }}</div>
        <h1>{{ t('admin.system.title') }}</h1>
        <div class="sub">{{ t('admin.system.sub') }}</div>
    </div>

    <template v-if="system">
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <section class="card">
                <div class="card-head">
                    <h2>{{ t('admin.system.checks') }}</h2>
                    <span class="right">
                        <span class="badge" :class="allOk ? 'b-ok' : 'b-danger'">{{
                            allOk ? t('admin.system.ok') : t('admin.system.failing')
                        }}</span>
                    </span>
                </div>
                <ul class="card-body space-y-1.5 text-sm">
                    <li v-for="(ok, name) in system.checks" :key="name" class="flex justify-between gap-3">
                        <span>{{ t(`admin.system.checkNames.${name}`) }}</span>
                        <span class="badge" :class="ok ? 'b-ok' : 'b-danger'">{{
                            ok ? t('admin.system.ok') : t('admin.system.failing')
                        }}</span>
                    </li>
                </ul>
            </section>

            <section class="card">
                <div class="card-head">
                    <h2>{{ t('admin.system.versions') }}</h2>
                </div>
                <dl class="card-body grid grid-cols-[minmax(0,9rem)_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-sm">
                    <dt class="text-ink-3">{{ t('admin.system.app') }}</dt>
                    <dd class="font-mono">{{ system.versions.app ?? '—' }}</dd>
                    <dt class="text-ink-3">{{ t('admin.system.engine') }}</dt>
                    <dd>{{ system.versions.engine.name }} {{ system.versions.engine.version ?? '' }}</dd>
                    <dt class="text-ink-3">{{ t('admin.system.database') }}</dt>
                    <dd class="font-mono">{{ system.versions.database }}</dd>
                    <dt class="text-ink-3">PHP · Laravel</dt>
                    <dd class="font-mono">{{ system.versions.php }} · {{ system.versions.laravel }}</dd>
                </dl>
            </section>

            <section class="card">
                <div class="card-head">
                    <h2>{{ t('admin.system.database') }} · {{ t('admin.system.storage') }}</h2>
                </div>
                <dl class="card-body grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-1.5 text-sm">
                    <dt class="text-ink-3">{{ t('admin.system.databaseTotal') }}</dt>
                    <dd class="text-right font-mono">{{ bytes(system.database.total_bytes) }}</dd>
                    <dt class="text-ink-3">{{ t('admin.system.gazetteer') }}</dt>
                    <dd class="text-right font-mono">{{ bytes(system.database.gazetteer_bytes) }}</dd>
                    <dt class="text-ink-3">{{ t('admin.system.rest') }}</dt>
                    <dd class="text-right font-mono">
                        {{ bytes(system.database.total_bytes - system.database.gazetteer_bytes) }}
                    </dd>
                    <dt class="text-ink-3">{{ t('admin.system.files', { count: system.storage.files }) }}</dt>
                    <dd class="text-right font-mono">{{ bytes(system.storage.file_bytes) }}</dd>
                    <dt class="text-ink-3">{{ t('admin.system.exports') }}</dt>
                    <dd class="text-right font-mono">{{ bytes(system.storage.export_bytes) }}</dd>
                    <dt class="text-ink-3">{{ t('admin.system.diskFree') }}</dt>
                    <dd class="text-right font-mono">
                        {{ bytes(system.storage.disk_free_bytes) }} / {{ bytes(system.storage.disk_total_bytes) }}
                    </dd>
                </dl>
            </section>

            <section class="card">
                <div class="card-head">
                    <h2>{{ t('admin.system.backups') }} · {{ t('admin.system.queue') }}</h2>
                </div>
                <div class="card-body space-y-2 text-sm">
                    <p v-if="!system.backups.configured" class="text-ink-3">{{ t('admin.system.backupsOff') }}</p>
                    <p v-else-if="system.backups.latest">
                        {{
                            t('admin.system.backupLatest', {
                                date: when(system.backups.latest.created_at),
                                size: bytes(system.backups.latest.bytes),
                            })
                        }}
                        <span class="text-ink-3">
                            · {{ t('admin.system.backupCount', { count: system.backups.count }) }}</span
                        >
                    </p>
                    <p v-else class="text-danger">{{ t('admin.system.noBackups') }}</p>
                    <dl class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-1.5">
                        <dt class="text-ink-3">{{ t('admin.system.pending') }}</dt>
                        <dd class="text-right font-mono">{{ system.queue.pending ?? '—' }}</dd>
                        <dt class="text-ink-3">{{ t('admin.system.failedCount') }}</dt>
                        <dd class="text-right font-mono" :class="{ 'text-danger': system.queue.failed }">
                            {{ system.queue.failed }}
                        </dd>
                        <dt class="text-ink-3">{{ t('admin.system.schedulerBeat') }}</dt>
                        <dd class="text-right">
                            {{
                                system.queue.scheduler_beat_at
                                    ? formatRelative(system.queue.scheduler_beat_at, locale)
                                    : '—'
                            }}
                        </dd>
                    </dl>
                </div>
            </section>
        </div>

        <section class="card mt-4">
            <div class="card-head">
                <h2>{{ t('admin.system.failedJobs') }}</h2>
            </div>
            <p class="card-body pb-0 text-ink-3">{{ t('admin.system.failedJobsHint') }}</p>
            <p v-if="system.failed_jobs.length === 0" class="card-body text-ink-3">
                {{ t('admin.system.noFailedJobs') }}
            </p>
            <div v-else class="overflow-x-auto">
                <table class="data">
                    <thead>
                        <tr>
                            <th scope="col">{{ t('admin.system.columns.job') }}</th>
                            <th scope="col" class="hidden md:table-cell">{{ t('admin.system.columns.exception') }}</th>
                            <th scope="col" class="hidden sm:table-cell">{{ t('admin.system.columns.failed') }}</th>
                            <th scope="col">
                                <span class="sr-only">{{ t('admin.system.retry') }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="job in system.failed_jobs" :key="job.uuid" class="cursor-default!">
                            <td>
                                {{ job.job }}
                                <span class="block font-mono text-xs text-ink-3">{{ job.queue }}</span>
                            </td>
                            <td class="hidden font-mono text-xs md:table-cell">{{ job.exception ?? '—' }}</td>
                            <td class="hidden text-xs text-ink-3 sm:table-cell">{{ when(job.failed_at) }}</td>
                            <td class="text-right whitespace-nowrap">
                                <button
                                    type="button"
                                    class="btn btn-sm"
                                    :disabled="busy === job.uuid"
                                    @click="act(job, 'retry')"
                                >
                                    {{ t('admin.system.retry') }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-ghost btn-sm text-danger"
                                    :disabled="busy === job.uuid"
                                    @click="act(job, 'forget')"
                                >
                                    {{ t('admin.system.forget') }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </template>
    <p v-else class="text-ink-3">{{ t('admin.common.loading') }}</p>
</template>
