<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRouter } from 'vue-router';

import PaginationBar from '@/components/PaginationBar.vue';
import { astrologerState, cleanQuery, stateTone } from '@/lib/admin';
import { formatDate, formatRelative } from '@/lib/format';
import { formatBytes } from '@/lib/files';
import http from '@/lib/http';
import { useToastStore } from '@/stores/toast';

/**
 * The admin's Astrologers screen (Phase 8c): every account with its practice's
 * figures — never anything from inside a practice.
 */
const { t, locale } = useI18n();
const router = useRouter();
const toast = useToastStore();

const STATUSES = ['active', 'unverified', 'suspended', 'closing', 'no_two_factor'];

const filters = reactive({ search: '', status: '' });
const astrologers = ref(null);
const meta = ref(null);

async function load(page = 1) {
    try {
        const { data } = await http.get('/admin/astrologers', { params: { ...cleanQuery(filters), page } });
        astrologers.value = data.data;
        meta.value = data.meta;
    } catch {
        toast.error(t('errors.generic'));
    }
}

let timer = null;
function searchSoon() {
    clearTimeout(timer);
    timer = setTimeout(() => load(), 300);
}

onMounted(() => load());
</script>

<template>
    <div class="page-head">
        <div class="eyebrow">{{ t('admin.badge') }}</div>
        <h1>{{ t('admin.astrologers.title') }}</h1>
        <div class="sub">{{ t('admin.astrologers.sub') }}</div>
    </div>

    <section class="card">
        <div class="card-body flex flex-wrap gap-2">
            <input
                v-model="filters.search"
                class="input min-w-56 flex-1"
                type="search"
                :placeholder="t('admin.astrologers.searchPlaceholder')"
                :aria-label="t('admin.common.search')"
                @input="searchSoon"
            />
            <select
                v-model="filters.status"
                class="input w-auto"
                :aria-label="t('admin.astrologers.status')"
                @change="load()"
            >
                <option value="">{{ t('admin.common.all') }}</option>
                <option v-for="status in STATUSES" :key="status" :value="status">
                    {{ t(`admin.astrologers.statuses.${status}`) }}
                </option>
            </select>
        </div>

        <p v-if="astrologers && astrologers.length === 0" class="card-body text-ink-3">
            {{ t('admin.astrologers.empty') }}
        </p>
        <div v-else-if="astrologers" class="overflow-x-auto">
            <table class="data">
                <thead>
                    <tr>
                        <th scope="col">{{ t('admin.astrologers.columns.astrologer') }}</th>
                        <th scope="col" class="hidden md:table-cell">{{ t('admin.astrologers.columns.practice') }}</th>
                        <th scope="col">{{ t('admin.astrologers.columns.state') }}</th>
                        <th scope="col" class="hidden text-right lg:table-cell">
                            {{ t('admin.astrologers.columns.clients') }}
                        </th>
                        <th scope="col" class="hidden text-right lg:table-cell">
                            {{ t('admin.astrologers.columns.consultations') }}
                        </th>
                        <th scope="col" class="hidden text-right xl:table-cell">
                            {{ t('admin.astrologers.columns.storage') }}
                        </th>
                        <th scope="col" class="hidden sm:table-cell">{{ t('admin.astrologers.columns.lastLogin') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="astrologer in astrologers"
                        :key="astrologer.id"
                        @click="router.push({ name: 'admin.astrologer', params: { id: astrologer.id } })"
                    >
                        <td>
                            <RouterLink
                                :to="{ name: 'admin.astrologer', params: { id: astrologer.id } }"
                                class="font-medium hover:underline"
                                @click.stop
                                >{{ astrologer.name }}</RouterLink
                            >
                            <span class="block text-xs text-ink-3">{{ astrologer.email }}</span>
                        </td>
                        <td class="hidden text-sm md:table-cell">
                            {{ astrologer.practice?.name ?? '—' }}
                            <span class="block text-xs text-ink-3">{{
                                formatDate(astrologer.registered_at?.slice(0, 10), locale, 'medium')
                            }}</span>
                        </td>
                        <td>
                            <span class="badge" :class="stateTone(astrologerState(astrologer))">{{
                                t(`admin.astrologers.statuses.${astrologerState(astrologer)}`)
                            }}</span>
                            <span v-if="astrologer.two_factor_enabled" class="badge b-ok ml-1">{{
                                t('admin.astrologers.twoFactor')
                            }}</span>
                        </td>
                        <td class="hidden text-right font-mono lg:table-cell">{{ astrologer.counts.clients }}</td>
                        <td class="hidden text-right font-mono lg:table-cell">{{ astrologer.counts.consultations }}</td>
                        <td class="hidden text-right text-xs text-ink-3 xl:table-cell">
                            {{ formatBytes(astrologer.storage_bytes, locale) }}
                        </td>
                        <td class="hidden text-xs text-ink-3 sm:table-cell">
                            {{
                                astrologer.last_login_at
                                    ? formatRelative(astrologer.last_login_at, locale)
                                    : t('admin.common.never')
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="card-body text-ink-3">{{ t('admin.common.loading') }}</p>
        <PaginationBar v-if="meta && meta.last_page > 1" :meta="meta" @page="load" />
    </section>
</template>
