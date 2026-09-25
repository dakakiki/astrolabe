<script setup>
import { onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

import { useLabels } from '@/composables/useLabels';
import { formatDateTime, isFuture } from '@/lib/datetime';
import { formatDate, formatTime } from '@/lib/format';
import http from '@/lib/http';
import { describeEvent, TIMELINE_FILTERS } from '@/lib/timeline';
import { useAuthStore } from '@/stores/auth';

/**
 * The client's history, newest first (docs/spec/02). Entries lead to where the
 * record lives: a consultation's page, or a tab of the client profile.
 */
const props = defineProps({ clientId: { type: [Number, String], required: true } });
const emit = defineEmits(['open-tab']);

const { t, te, locale } = useI18n();
const labels = useLabels();
const auth = useAuthStore();

const filter = ref('all');
const entries = ref([]);
const meta = ref(null);
const loading = ref(false);
const failed = ref(false);

async function load(page = 1) {
    loading.value = true;
    failed.value = false;

    try {
        const { data } = await http.get(`/clients/${props.clientId}/timeline`, {
            params: { type: filter.value, page, per_page: 20 },
        });
        const described = data.data.map((event) => ({ event, ...describeEvent(event) }));
        entries.value = page === 1 ? described : [...entries.value, ...described];
        meta.value = data.meta;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

watch(filter, () => load());
onMounted(() => load());

defineExpose({ reload: () => load() });

const when = (event) => formatDateTime(event.occurred_at, locale.value, auth.user?.timezone);
const moment = (isoTimestamp) => formatDateTime(isoTimestamp, locale.value, auth.user?.timezone);

function fieldList(fields) {
    return fields.map((field) => (te(`timeline.fields.${field}`) ? t(`timeline.fields.${field}`) : field)).join(', ');
}

function chartLine(chart) {
    const zodiac =
        chart.zodiac_mode === 'sidereal'
            ? t('chart.sidereal', { ayanamsa: labels.ayanamsa(chart.ayanamsa) })
            : t('chart.tropical');

    const houses = chart.house_system ? labels.houseSystem(chart.house_system) : null;

    return [zodiac, houses, chart.engine].filter(Boolean).join(' · ');
}

// The deadline as it was entered, the priority when it is not normal, and whether it is done.
function taskLine(task) {
    const due = task.due_date
        ? t(task.due_time ? 'tasks.due.onAt' : 'tasks.due.on', {
              date: formatDate(task.due_date, locale.value, 'medium'),
              time: formatTime(task.due_time, locale.value),
          })
        : null;
    const priority =
        task.priority && task.priority !== 'normal'
            ? t('tasks.priority', { priority: t(`tasks.priorities.${task.priority}`) })
            : null;

    return [due, priority, task.status === 'done' ? t('timeline.taskDone') : null].filter(Boolean).join(' · ');
}
</script>

<template>
    <section class="card">
        <div class="card-head flex-wrap">
            <h2>{{ t('timeline.title') }}</h2>
            <div class="seg right" role="group" :aria-label="t('timeline.title')">
                <button
                    v-for="option in TIMELINE_FILTERS"
                    :key="option"
                    type="button"
                    :aria-pressed="filter === option"
                    @click="filter = option"
                >
                    {{ t(`timeline.filters.${option}`) }}
                </button>
            </div>
        </div>
        <div class="card-body">
            <ol v-if="entries.length" class="timeline" :aria-busy="loading">
                <li v-for="entry in entries" :key="entry.event.id" class="tl-item" :class="`t-${entry.tone}`">
                    <div class="tl-when">
                        {{ when(entry.event) }}
                        <span
                            v-if="isFuture(entry.event.occurred_at) && !entry.cancelled"
                            class="badge b-info b-plain ml-1 normal-case"
                        >
                            {{ t('timeline.upcoming') }}
                        </span>
                        <span v-if="entry.private" class="badge b-plain ml-1 normal-case">
                            {{ t('timeline.private') }}
                        </span>
                    </div>
                    <div class="tl-title">
                        <RouterLink v-if="entry.to?.name" :to="entry.to" class="hover:underline">
                            {{ t(...entry.title) }}<template v-if="entry.titled"> · {{ entry.event.summary }}</template>
                        </RouterLink>
                        <a
                            v-else-if="entry.to?.tab"
                            href="#"
                            class="hover:underline"
                            @click.prevent="emit('open-tab', entry.to.tab)"
                            >{{ t(...entry.title) }}</a
                        >
                        <span v-else>{{ t(...entry.title) }}</span>
                    </div>
                    <div v-if="entry.body" class="tl-body line-clamp-3 whitespace-pre-line">{{ entry.body }}</div>
                    <div v-if="entry.fields?.length" class="tl-body">
                        {{ t('timeline.changed', { fields: fieldList(entry.fields) }) }}
                    </div>
                    <div v-if="entry.chart" class="tl-body">{{ chartLine(entry.chart) }}</div>
                    <div v-if="entry.task && taskLine(entry.task)" class="tl-body">{{ taskLine(entry.task) }}</div>
                    <div v-if="entry.moved" class="tl-body">
                        {{ t('timeline.movedFromTo', { from: moment(entry.moved.from), to: moment(entry.moved.to) }) }}
                    </div>
                    <div v-if="entry.wasAt" class="tl-body">
                        {{ t('timeline.wasAt', { time: moment(entry.wasAt) }) }}
                    </div>
                    <div v-if="entry.event.created_by && entry.event.created_by.id !== auth.user?.id" class="tl-body">
                        {{ t('timeline.by', { name: entry.event.created_by.name }) }}
                    </div>
                </li>
            </ol>

            <p v-else-if="failed" class="notice n-warn" role="alert">{{ t('errors.generic') }}</p>
            <p v-else-if="!loading" class="empty">
                {{ filter === 'all' ? t('timeline.empty') : t('timeline.emptyFilter') }}
            </p>
            <p v-else class="text-ink-3" role="status">{{ t('common.loading') }}</p>

            <button
                v-if="meta && meta.current_page < meta.last_page"
                type="button"
                class="btn btn-sm"
                :disabled="loading"
                @click="load(meta.current_page + 1)"
            >
                {{ t('timeline.loadMore') }}
            </button>
        </div>
    </section>
</template>
