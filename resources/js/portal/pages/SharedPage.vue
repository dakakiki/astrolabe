<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import RichText from '@/components/RichText.vue';
import { formatBytes } from '@/lib/files';
import { displayZone } from '@/portal/lib/portal';
import { loadScreen } from '@/portal/load';
import http from '@/portal/http';
import { useSessionStore } from '@/portal/stores/session';

/**
 * Portal → Shared (docs/spec/12): notes, files and links the astrologer
 * shared with the client, newest first, each with the consultation it came
 * from (its day and service only). Files download through the portal's own
 * authorised route.
 */
const { t, locale } = useI18n();
const session = useSessionStore();

const items = ref(null);
const zone = computed(() => displayZone(session.user, session.practice));

onMounted(() =>
    loadScreen(async () => {
        const response = await http.get('/shared');
        items.value = response.data.data;
    }),
);

function day(iso) {
    if (!iso) return '';

    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeZone: zone.value }).format(new Date(iso));
}

function host(url) {
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
}
</script>

<template>
    <div class="page-head">
        <div class="eyebrow">{{ session.practice?.name }}</div>
        <h1>{{ t('portal.shared.title') }}</h1>
        <div class="sub">{{ t('portal.shared.sub', { practice: session.practice?.name }) }}</div>
    </div>

    <p v-if="items === null" class="text-ink-3">{{ t('common.loading') }}</p>
    <div v-else-if="items.length === 0" class="card card-body text-sm text-ink-3">{{ t('portal.shared.empty') }}</div>

    <div v-else class="space-y-3">
        <article v-for="item in items" :key="`${item.type}-${item.id}`" class="card">
            <div class="card-body">
                <div class="flex flex-wrap items-baseline gap-x-2 text-xs text-ink-3">
                    <span>{{ t(`portal.shared.kinds.${item.type}`) }} · {{ day(item.date) }}</span>
                    <span v-if="item.new" class="new-dot">{{ t('portal.shared.new') }}</span>
                </div>
                <p v-if="item.consultation" class="mt-0.5 text-xs text-ink-3">
                    {{
                        item.consultation.service
                            ? t('portal.shared.fromConsultationService', {
                                  date: day(item.consultation.date),
                                  service: item.consultation.service,
                              })
                            : t('portal.shared.fromConsultation', { date: day(item.consultation.date) })
                    }}
                </p>

                <template v-if="item.type === 'note'">
                    <h2 v-if="item.title" class="mt-2 text-base font-semibold">{{ item.title }}</h2>
                    <RichText :html="item.content" class="mt-2" />
                </template>

                <div v-else class="mt-2 flex flex-wrap items-center gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="font-medium break-words">{{ item.name }}</div>
                        <div class="text-xs text-ink-3">
                            {{ item.type === 'link' ? host(item.url) : formatBytes(item.size, locale) }}
                        </div>
                    </div>
                    <a
                        v-if="item.type === 'link'"
                        :href="item.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="btn btn-sm"
                        >{{ t('portal.shared.open') }}</a
                    >
                    <a v-else :href="`/api/portal/v1/files/${item.id}/download`" class="btn btn-sm">{{
                        t('portal.shared.download')
                    }}</a>
                </div>
            </div>
        </article>
    </div>
</template>
