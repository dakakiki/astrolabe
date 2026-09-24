<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import http from '@/lib/http';
import { useThemeStore } from '@/stores/theme';

const { t } = useI18n();
const themeStore = useThemeStore();

// Phase 0 placeholder: proves the SPA → API → database path end to end.
// Replaced by the real application layout in Phase 1.
const status = ref(null);
const failed = ref(false);

onMounted(async () => {
    try {
        const { data } = await http.get('/status');
        status.value = data.data;
    } catch {
        failed.value = true;
    }
});

function label(ok) {
    if (failed.value) return t('status.failed');
    if (status.value === null) return t('status.checking');
    return ok ? t('status.ok') : t('status.failed');
}
</script>

<template>
    <main class="flex min-h-full items-center justify-center px-4 py-12">
        <section class="w-full max-w-md rounded-lg border border-line bg-surface p-8 shadow-card">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="font-serif text-3xl text-ink">{{ t('app.name') }}</h1>
                    <p class="mt-1 text-ink-3">{{ t('app.tagline') }}</p>
                </div>
                <button
                    type="button"
                    class="rounded-sm border border-line px-3 py-1.5 text-ink-2 hover:bg-surface-2 focus-visible:outline-2 focus-visible:outline-brand"
                    :aria-label="t('theme.toggle')"
                    @click="themeStore.toggle()"
                >
                    {{ themeStore.theme === 'day' ? t('theme.night') : t('theme.day') }}
                </button>
            </div>

            <h2 class="mt-8 text-xs font-medium tracking-wide text-ink-3 uppercase">{{ t('status.title') }}</h2>
            <dl class="mt-3 divide-y divide-line-soft font-mono text-sm">
                <div class="flex justify-between py-2">
                    <dt class="text-ink-2">{{ t('status.api') }}</dt>
                    <dd :class="failed ? 'text-danger' : 'text-ok'">{{ label(true) }}</dd>
                </div>
                <div class="flex justify-between py-2">
                    <dt class="text-ink-2">{{ t('status.database') }}</dt>
                    <dd :class="status?.database ? 'text-ok' : 'text-danger'">{{ label(status?.database) }}</dd>
                </div>
                <div v-if="status" class="flex justify-between py-2">
                    <dt class="text-ink-2">Laravel</dt>
                    <dd class="text-ink">{{ status.laravel }}</dd>
                </div>
            </dl>
        </section>
    </main>
</template>
