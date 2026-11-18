<script setup>
import { onBeforeUnmount, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';

import AppIcon from '@/components/AppIcon.vue';
import { usePwaStore } from '@/portal/stores/pwa';

/**
 * Installing the portal on a phone (docs/spec/12, "Instalacija"; user, 18 Nov 2026): one bar
 * floating at the bottom of every page of the signed-in portal, on a phone only and only until
 * the portal runs as the installed app. "Install app" does what this phone can: the browser's
 * own install dialog where there is one, otherwise this phone's steps. The Android button
 * installs at once where the browser allows it, else shows the Android steps; the iOS button
 * shows how to add the portal to the Home Screen. The steps open above the bar: Escape, a click
 * outside or ✕ close them.
 */
const { t } = useI18n();
const pwa = usePwaStore();
const uid = useId();

// The steps of each guide; the one with {icon} shows the button the person looks for.
const STEPS = {
    android: { steps: ['s1', 's2', 's3', 's4'], icon: { s2: 'browserMenu' } },
    ios: { steps: ['s1', 's2', 's3'], icon: { s1: 'share' } },
};

const guide = ref(null);
const busy = ref(false);
const root = ref(null);

/** Android: the browser's install dialog when it offers one, otherwise the steps. */
async function installAndroid() {
    if (pwa.installState !== 'prompt') {
        open('android');
        return;
    }

    busy.value = true;
    close();
    try {
        await pwa.promptInstall();
    } finally {
        busy.value = false;
    }
}

function install() {
    if (pwa.platform === 'ios') {
        open('ios');
    } else {
        installAndroid();
    }
}

function open(name) {
    guide.value = guide.value === name ? null : name;
}

function close() {
    guide.value = null;
}

function onKey(event) {
    if (event.key === 'Escape') close();
}

function onOutside(event) {
    if (root.value && !root.value.contains(event.target)) close();
}

watch(guide, (value) => {
    document.removeEventListener('keydown', onKey);
    document.removeEventListener('pointerdown', onOutside);
    if (value) {
        document.addEventListener('keydown', onKey);
        document.addEventListener('pointerdown', onOutside);
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKey);
    document.removeEventListener('pointerdown', onOutside);
});
</script>

<template>
    <div ref="root" class="install-float">
        <div
            v-if="guide"
            :id="`${uid}-guide`"
            class="install-pop"
            role="dialog"
            :aria-label="t(`portal.pwa.install.guide.title.${guide}`)"
        >
            <div class="install-pop-head">
                <h3>{{ t(`portal.pwa.install.guide.title.${guide}`) }}</h3>
                <button
                    type="button"
                    class="icon-btn"
                    :aria-label="t('common.close')"
                    @click="close"
                    v-tip="t('tips.common.close')"
                >
                    <AppIcon name="close" />
                </button>
            </div>
            <ol>
                <li v-for="step in STEPS[guide].steps" :key="step">
                    <i18n-t :keypath="`portal.pwa.install.guide.${guide}.${step}`" tag="span">
                        <template #icon>
                            <AppIcon :name="STEPS[guide].icon[step] ?? 'browserMenu'" class="step-icon" />
                        </template>
                    </i18n-t>
                </li>
            </ol>
        </div>

        <div class="install-bar">
            <button
                type="button"
                class="install-action"
                :disabled="busy"
                @click="install"
                v-tip="t('tips.portal.pwa.install.action')"
            >
                <AppIcon name="install" />
                {{ t('portal.pwa.install.action') }}
            </button>
            <span class="sep" aria-hidden="true" />
            <button
                type="button"
                class="icon-btn"
                :class="{ on: guide === 'android' }"
                :disabled="busy"
                :aria-label="t('portal.pwa.install.guide.labels.android')"
                :aria-expanded="String(guide === 'android')"
                :aria-controls="`${uid}-guide`"
                @click="installAndroid"
                v-tip="t('tips.portal.pwa.install.android')"
            >
                <AppIcon name="android" />
            </button>
            <button
                type="button"
                class="icon-btn"
                :class="{ on: guide === 'ios' }"
                :aria-label="t('portal.pwa.install.guide.labels.ios')"
                :aria-expanded="String(guide === 'ios')"
                :aria-controls="`${uid}-guide`"
                @click="open('ios')"
                v-tip="t('tips.portal.pwa.install.ios')"
            >
                <AppIcon name="ios" />
            </button>
        </div>
    </div>
</template>
