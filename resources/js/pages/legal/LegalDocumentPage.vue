<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import BrandMark from '@/components/BrandMark.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import http from '@/lib/http';
import { formatLegalDate, internalPath, LEGAL_DOCUMENTS } from '@/lib/legal';
import { useAuthStore } from '@/stores/auth';

/**
 * A legal document (Phase 8c), public: the Terms of Service, the Data
 * Processing Agreement or the Privacy Policy, in force or an earlier version
 * (`?version=`). The text is ours, rendered on the server from the
 * repository's Markdown with raw HTML stripped.
 */
const { t, locale } = useI18n();
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const document = ref(null);
const failed = ref(false);

const others = computed(() => LEGAL_DOCUMENTS.filter((slug) => slug !== route.params.document));
const back = computed(() => (auth.isAuthenticated ? { name: 'dashboard' } : { name: 'login' }));

async function load() {
    failed.value = false;

    try {
        const { data } = await http.get(`/legal/${route.params.document}`, {
            params: route.query.version ? { version: route.query.version } : {},
        });
        document.value = data.data;
        await nextTick();
        scrollToHash();
    } catch {
        document.value = null;
        failed.value = true;
    }
}

function scrollToHash() {
    const id = route.hash ? decodeURIComponent(route.hash.slice(1)) : null;
    window.document.getElementById(id ?? '')?.scrollIntoView();
}

watch(() => [route.params.document, route.query.version], load, { immediate: true });
watch(() => route.hash, scrollToHash);

/** Links between the documents open inside the app instead of reloading it. */
function followLink(event) {
    const link = event.target.closest('a');
    const path = internalPath(link?.getAttribute('href'));

    if (path && !event.ctrlKey && !event.metaKey && !event.shiftKey) {
        event.preventDefault();
        router.push(path);
    }
}
</script>

<template>
    <div class="legal-page">
        <header class="legal-head">
            <RouterLink :to="back" class="flex items-center gap-2 font-serif text-lg text-ink no-underline">
                <BrandMark class="size-8" />
                {{ t('app.name') }}
            </RouterLink>
            <div class="flex items-center gap-3">
                <RouterLink :to="back" class="text-sm">{{
                    auth.isAuthenticated ? t('legal.backToApp') : t('legal.backToSignIn')
                }}</RouterLink>
                <ThemeToggle />
            </div>
        </header>

        <p v-if="failed" class="notice n-warn">{{ t('legal.notFound') }}</p>
        <div v-else-if="!document" class="text-ink-3">{{ t('common.loading') }}</div>

        <article v-else>
            <div class="eyebrow">{{ t('legal.eyebrow') }}</div>
            <h1 class="mb-2 font-serif text-3xl font-normal text-ink">{{ document.title }}</h1>
            <p class="mb-5 text-sm text-ink-3">
                {{
                    t('legal.versionLine', {
                        version: document.version,
                        date: formatLegalDate(document.effective_on, locale),
                    })
                }}
            </p>

            <div v-if="document.draft" class="notice n-warn mb-4" role="note">
                <div>
                    <strong>{{ t('legal.draftTitle') }}</strong>
                    {{ t('legal.draftBody') }}
                </div>
            </div>
            <div v-if="!document.current" class="notice n-info mb-4" role="note">
                <div>
                    {{ t('legal.earlierVersion') }}
                    <RouterLink :to="{ name: 'legal.show', params: { document: document.slug } }">{{
                        t('legal.readCurrent')
                    }}</RouterLink>
                </div>
            </div>

            <nav v-if="document.sections.length > 2" class="card legal-aside mb-6" :aria-label="t('legal.contents')">
                <div class="card-body">
                    <div class="eyebrow mb-2">{{ t('legal.contents') }}</div>
                    <ol class="legal-toc">
                        <li v-for="section in document.sections" :key="section.id">
                            <RouterLink :to="{ hash: `#${section.id}`, query: route.query }">{{
                                section.title
                            }}</RouterLink>
                        </li>
                    </ol>
                </div>
            </nav>

            <!-- eslint-disable-next-line vue/no-v-html -- our own Markdown from the repository, raw HTML stripped on the server -->
            <div class="legal-doc" @click="followLink" v-html="document.html" />

            <footer class="legal-aside mt-10 border-t border-line pt-5 text-sm">
                <div v-if="document.versions.length > 1" class="mb-4">
                    <div class="eyebrow mb-2">{{ t('legal.versions') }}</div>
                    <ul class="space-y-1">
                        <li v-for="version in document.versions" :key="version.version">
                            <RouterLink
                                :to="{
                                    name: 'legal.show',
                                    params: { document: document.slug },
                                    query: { version: version.version },
                                }"
                                >{{ formatLegalDate(version.effective_on, locale) || version.version }}</RouterLink
                            >
                            <span v-if="version.summary" class="text-ink-3"> — {{ version.summary }}</span>
                        </li>
                    </ul>
                </div>
                <div class="flex flex-wrap gap-4">
                    <RouterLink
                        v-for="slug in others"
                        :key="slug"
                        :to="{ name: 'legal.show', params: { document: slug } }"
                        >{{ t(`legal.documents.${slug}`) }}</RouterLink
                    >
                </div>
            </footer>
        </article>
    </div>
</template>
