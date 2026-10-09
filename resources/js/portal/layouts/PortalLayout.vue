<script setup>
import { useI18n } from 'vue-i18n';
import { RouterLink, useRouter } from 'vue-router';

import BrandMark from '@/components/BrandMark.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import PracticeMark from '@/portal/components/PracticeMark.vue';
import { useSessionStore } from '@/portal/stores/session';

/**
 * The signed-in portal: the practice's logo and name on top (docs/spec/12,
 * "Brend prakse u portalu"), five sections, "Powered by AstroLabe" below.
 */
const { t } = useI18n();
const router = useRouter();
const session = useSessionStore();

const sections = ['home', 'appointments', 'shared', 'profile', 'security'];

async function signOut() {
    await session.signOut();
    router.push({ name: 'sign-in' });
}
</script>

<template>
    <div class="portal">
        <header class="portal-top">
            <div class="portal-top-inner">
                <RouterLink v-if="session.practice" :to="{ name: 'home' }" class="portal-practice">
                    <PracticeMark :practice="session.practice" />
                    <span class="name">{{ session.practice.name }}</span>
                </RouterLink>
                <span v-else class="portal-practice">
                    <BrandMark class="size-8" />
                    <span class="name">{{ t('portal.title') }}</span>
                </span>
                <div class="ml-auto flex shrink-0 items-center gap-1.5">
                    <RouterLink
                        v-if="session.practices.length > 1"
                        :to="{ name: 'choose' }"
                        class="btn btn-ghost btn-sm"
                        >{{ t('portal.nav.switch') }}</RouterLink
                    >
                    <ThemeToggle />
                    <button type="button" class="btn btn-ghost btn-sm" @click="signOut">
                        {{ t('portal.nav.signOut') }}
                    </button>
                </div>
            </div>
            <nav class="portal-nav" :aria-label="t('portal.nav.label')">
                <div class="portal-nav-inner">
                    <RouterLink v-for="section in sections" :key="section" :to="{ name: section }">{{
                        t(`portal.nav.${section}`)
                    }}</RouterLink>
                </div>
            </nav>
        </header>

        <main class="portal-main">
            <slot />
        </main>

        <footer class="portal-foot">
            <BrandMark class="size-4" />
            {{ t('portal.poweredBy') }}
        </footer>
    </div>
</template>
