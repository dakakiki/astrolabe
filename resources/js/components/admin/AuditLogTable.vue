<script setup>
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

import { propertyPairs } from '@/lib/admin';
import { formatDateTime } from '@/lib/datetime';
import { describeDevice } from '@/lib/security';
import { useAuthStore } from '@/stores/auth';

/**
 * Audit log entries for the operator (Phase 8c): when on the operator's clock,
 * who (linked to the astrologer), what, from which device and address, and the
 * entry's names and counts. Failed sign-ins and lockouts stand out.
 */
defineProps({
    entries: { type: Array, required: true },
    showWho: { type: Boolean, default: true },
});

const { t, locale } = useI18n();
const auth = useAuthStore();

const when = (entry) => formatDateTime(entry.at, locale.value, auth.user?.timezone || 'UTC');

function device(entry) {
    const { browser, system } = describeDevice(entry.user_agent);

    if (browser && system) return t('admin.audit.deviceOn', { browser, system });

    return browser ?? system ?? (entry.user_agent ? t('admin.audit.unknownDevice') : '—');
}
</script>

<template>
    <div class="overflow-x-auto">
        <table class="data">
            <thead>
                <tr>
                    <th scope="col">{{ t('admin.audit.columns.when') }}</th>
                    <th v-if="showWho" scope="col">{{ t('admin.audit.columns.who') }}</th>
                    <th scope="col">{{ t('admin.audit.columns.what') }}</th>
                    <th scope="col" class="hidden md:table-cell">{{ t('admin.audit.columns.where') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="entry in entries" :key="entry.id" class="cursor-default!">
                    <td class="font-mono text-xs whitespace-nowrap">{{ when(entry) }}</td>
                    <td v-if="showWho" class="text-sm">
                        <template v-if="entry.user">
                            <span v-if="entry.user.is_admin" class="font-medium"
                                >{{ entry.user.name }}
                                <span class="text-xs text-ink-3">· {{ t('admin.audit.admin') }}</span></span
                            >
                            <RouterLink
                                v-else
                                :to="{ name: 'admin.astrologer', params: { id: entry.user.id } }"
                                class="hover:underline"
                                >{{ entry.user.email }}</RouterLink
                            >
                        </template>
                        <span v-else-if="entry.user_id" class="text-ink-3 italic">{{
                            t('admin.audit.deletedAccount')
                        }}</span>
                        <!-- A practice's client in the portal: only the account's number, never the address. -->
                        <span v-else-if="entry.portal_user_id" class="text-ink-2">{{
                            t('admin.audit.portalAccount', { id: entry.portal_user_id })
                        }}</span>
                        <span v-else class="text-ink-3">{{ t('admin.audit.system') }}</span>
                        <span v-if="entry.workspace" class="block text-xs text-ink-3">{{
                            t('admin.audit.practice', { name: entry.workspace.name })
                        }}</span>
                    </td>
                    <td class="text-sm">
                        <span :class="entry.warning ? 'font-medium text-danger' : ''">{{
                            t(`admin.events.${entry.event}`)
                        }}</span>
                        <span v-if="entry.subject_type" class="text-xs text-ink-3">
                            · {{ entry.subject_type.replaceAll('_', ' ') }} #{{ entry.subject_id }}</span
                        >
                        <span
                            v-for="[key, value] in propertyPairs(entry.properties)"
                            :key="key"
                            class="block max-w-md truncate text-xs text-ink-3"
                            :title="`${key}: ${value}`"
                            >{{ key }}: {{ value }}</span
                        >
                    </td>
                    <td class="hidden text-xs text-ink-3 md:table-cell">
                        {{ device(entry) }}
                        <span class="block font-mono">{{ entry.ip_address ?? '—' }}</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
