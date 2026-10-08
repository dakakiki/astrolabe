<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import PaginationBar from '@/components/PaginationBar.vue';
import http from '@/lib/http';
import { formatDateTime } from '@/lib/datetime';
import { describeDevice, isWarning } from '@/lib/security';
import { useAuthStore } from '@/stores/auth';

/**
 * The person's own sign-ins and account changes from the audit log (Phase 8a),
 * newest first, on their own clock. Failed attempts stand out.
 */
const { t, locale } = useI18n();
const auth = useAuthStore();

const entries = ref([]);
const meta = ref(null);
const failed = ref(false);

async function load(page = 1) {
    try {
        const { data } = await http.get('/security-activity', { params: { page } });
        entries.value = data.data;
        meta.value = data.meta;
        failed.value = false;
    } catch {
        failed.value = true;
    }
}
onMounted(() => load());

// The settings page reloads the list after a change it just made.
defineExpose({ load });

function when(entry) {
    return formatDateTime(entry.at, locale.value, auth.user?.timezone || 'UTC');
}

function device(entry) {
    const { browser, system } = describeDevice(entry.user_agent);

    if (browser && system) {
        return t('settings.security.activity.deviceOn', { browser, system });
    }

    return browser ?? system ?? t('settings.security.activity.unknownDevice');
}
</script>

<template>
    <section class="card">
        <div class="card-head">
            <h2>{{ t('settings.security.activity.title') }}</h2>
        </div>
        <div class="card-body">
            <p class="text-ink-3">{{ t('settings.security.activity.intro') }}</p>
        </div>

        <p v-if="failed" class="card-body text-ink-3">{{ t('errors.generic') }}</p>
        <p v-else-if="meta && entries.length === 0" class="card-body text-ink-3">
            {{ t('settings.security.activity.empty') }}
        </p>
        <div v-else-if="entries.length" class="overflow-x-auto">
            <table class="data">
                <thead>
                    <tr>
                        <th>{{ t('settings.security.activity.when') }}</th>
                        <th>{{ t('settings.security.activity.what') }}</th>
                        <th>{{ t('settings.security.activity.device') }}</th>
                        <th class="hidden sm:table-cell">{{ t('settings.security.activity.ip') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in entries" :key="entry.id">
                        <td class="whitespace-nowrap">{{ when(entry) }}</td>
                        <td>
                            <span :class="isWarning(entry.event) ? 'font-medium text-danger' : ''">
                                {{ t(`settings.security.activity.events.${entry.event}`) }}
                            </span>
                            <span v-if="entry.remembered" class="text-ink-3">
                                · {{ t('settings.security.activity.remembered') }}</span
                            >
                        </td>
                        <td>{{ device(entry) }}</td>
                        <td class="hidden font-mono text-xs sm:table-cell">{{ entry.ip_address ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <PaginationBar v-if="meta && meta.last_page > 1" :meta="meta" @page="load" />
    </section>
</template>
