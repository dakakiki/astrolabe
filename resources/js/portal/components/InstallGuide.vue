<script setup>
import { onBeforeUnmount, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';

import AppIcon from '@/components/AppIcon.vue';
import { usePwaStore } from '@/portal/stores/pwa';

/**
 * Installing the portal, the same everywhere it is offered (Home, Profile → App, the iPhone
 * notice under Notifications): "Install app" opens the browser's own dialog where there is one,
 * and otherwise the steps for this device; the Android and iOS buttons always show their steps.
 * The steps open in a small panel under the bar: Escape, a click outside or ✕ closes it.
 */
const { t } = useI18n();
const pwa = usePwaStore();
const uid = useId();

// The steps of each guide; the one with {icon} shows the button the person looks for.
const STEPS = {
    android: { steps: ['s1', 's2', 's3', 's4'], icon: { s2: 'browserMenu' } },
    ios: { steps: ['s1', 's2', 's3', 's4'], icon: { s2: 'share' } },
    desktop: { steps: ['s1', 's2', 's3'], icon: { s2: 'browserMenu' } },
};

const guide = ref(null);
const busy = ref(false);
const root = ref(null);

async function install() {
    if (pwa.installState === 'prompt') {
        busy.value = true;
        try {
            await pwa.promptInstall();
        } finally {
            busy.value = false;
        }
        return;
    }
    open(pwa.platform === 'ios' || pwa.platform === 'android' ? pwa.platform : 'desktop');
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

onBeforeUnmount(close);
</script>

<template>
    <div ref="root" class="install-guide">
        <div class="install-bar">
            <button
                type="button"
                class="btn btn-primary btn-sm"
                :disabled="busy"
                :aria-expanded="pwa.installState === 'prompt' ? undefined : String(guide !== null)"
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
                :aria-label="t('portal.pwa.install.guide.labels.android')"
                :aria-expanded="String(guide === 'android')"
                :aria-controls="`${uid}-guide`"
                @click="open('android')"
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
            <p v-if="guide === 'ios'" class="install-note">{{ t('portal.pwa.install.iosNote') }}</p>
        </div>
    </div>
</template>
