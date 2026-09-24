<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

import { useLabels } from '@/composables/useLabels';
import http from '@/lib/http';
import { useAuthStore } from '@/stores/auth';

const { t, locale } = useI18n();
const auth = useAuthStore();
const labels = useLabels();

const methods = ref(null);

onMounted(async () => {
    const { data } = await http.get('/astrology-methods');
    methods.value = data.data.filter((method) => method.selected);
});

const firstName = computed(() => auth.user?.name.split(/\s+/)[0] ?? '');

const today = computed(() => {
    try {
        return new Intl.DateTimeFormat(locale.value, {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            timeZone: auth.user?.timezone,
        }).format(new Date());
    } catch {
        return '';
    }
});

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
    { label: t('dashboard.setup.clients'), soon: true },
]);
</script>

<template>
    <div class="page-head">
        <div class="eyebrow">{{ t('dashboard.eyebrow') }}</div>
        <h1>{{ t('dashboard.greeting', { name: firstName }) }}</h1>
        <div class="sub">{{ t('dashboard.sub', { workspace: auth.workspace?.name, date: today }) }}</div>
    </div>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_368px]">
        <section class="card">
            <div class="card-head">
                <h2>{{ t('dashboard.setup.title') }}</h2>
            </div>
            <ul class="card-body space-y-1">
                <li v-for="step in steps" :key="step.label" class="flex items-center gap-3 rounded-md px-2 py-2">
                    <span
                        class="grid size-5 place-items-center rounded-full border text-[11px]"
                        :class="step.done ? 'border-ok bg-ok-bg text-ok' : 'border-line text-ink-4'"
                        aria-hidden="true"
                        >{{ step.done ? '✓' : '' }}</span
                    >
                    <RouterLink v-if="step.to" :to="step.to" class="flex-1 hover:underline">{{
                        step.label
                    }}</RouterLink>
                    <span v-else class="flex-1 text-ink-3">{{ step.label }}</span>
                    <span v-if="step.soon" class="tag">{{ t('dashboard.setup.soon') }}</span>
                </li>
            </ul>
        </section>

        <section class="card">
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
                        <span v-else-if="methods" class="text-ink-3">{{ t('dashboard.practice.noMethods') }}</span>
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
                <div>
                    <dt class="eyebrow">{{ t('dashboard.practice.currency') }}</dt>
                    <dd>{{ auth.workspace && labels.currency(auth.workspace.default_currency) }}</dd>
                </div>
            </dl>
        </section>
    </div>
</template>
