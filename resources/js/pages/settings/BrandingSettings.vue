<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import BrandMark from '@/components/BrandMark.vue';
import FormField from '@/components/FormField.vue';
import { useForm } from '@/composables/useForm';
import { brandPalette, normaliseHex, previewTokens } from '@/lib/brand';
import { initials } from '@/lib/format';
import http from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

/**
 * Settings → Branding (docs/spec/12, Phase 9a): what clients see in the portal —
 * the practice's name, its logo and one colour. A colour white button text
 * cannot be read on is shown with the shade the portal will use instead.
 */
const { t } = useI18n();
const auth = useAuthStore();
const toast = useToastStore();

const LOGO_TYPES = '.png,.webp,.svg,image/png,image/webp,image/svg+xml';

const form = useForm({
    display_name: auth.workspace.display_name ?? '',
    brand_color: auth.workspace.brand_color ?? '',
});

const palette = computed(() => brandPalette(form.data.brand_color));
const shownName = computed(() => form.data.display_name.trim() || auth.workspace.name);
const picker = computed({
    get: () => normaliseHex(form.data.brand_color) ?? '#5a61e0',
    set: (value) => {
        form.data.brand_color = value;
    },
});

const uploading = ref(false);
const logoError = ref(null);

async function save() {
    await form
        .submit(async (data) => {
            const response = await http.patch('/workspace', {
                display_name: data.display_name.trim() || null,
                brand_color: normaliseHex(data.brand_color),
            });
            auth.workspace = response.data.data;
            toast.success(t('settings.branding.saved'));
        })
        .catch(() => {});
}

async function upload(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    uploading.value = true;
    logoError.value = null;

    try {
        const body = new FormData();
        body.append('logo', file);
        const response = await http.post('/workspace/logo', body);
        auth.workspace = response.data.data;
        toast.success(t('settings.branding.logoSaved'));
    } catch (error) {
        logoError.value = error.response?.data?.errors?.logo?.[0] ?? t('errors.generic');
    } finally {
        uploading.value = false;
    }
}

async function removeLogo() {
    const response = await http.delete('/workspace/logo');
    auth.workspace = response.data.data;
}
</script>

<template>
    <div v-if="!auth.isOwner" class="notice n-neutral">{{ t('settings.ownerOnly') }}</div>

    <section class="card">
        <div class="card-head">
            <h2>{{ t('settings.branding.title') }}</h2>
        </div>
        <form class="card-body" novalidate @submit.prevent="save">
            <p class="mb-4 text-sm text-ink-3">{{ t('settings.branding.intro') }}</p>
            <fieldset :disabled="!auth.isOwner">
                <FormField
                    v-slot="{ id, aria }"
                    :label="t('settings.branding.displayName')"
                    :error="form.errors.value.display_name"
                    :hint="t('settings.branding.displayNameHint', { name: auth.workspace.name })"
                >
                    <input
                        :id="id"
                        v-model="form.data.display_name"
                        v-bind="aria"
                        class="input"
                        maxlength="120"
                        :placeholder="auth.workspace.name"
                    />
                </FormField>

                <FormField
                    v-slot="{ id, aria }"
                    :label="t('settings.branding.color')"
                    :error="form.errors.value.brand_color"
                    :hint="t('settings.branding.colorHint')"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <input
                            v-model="picker"
                            type="color"
                            class="h-9 w-12 cursor-pointer rounded border border-line bg-surface p-0.5"
                            :aria-label="t('settings.branding.colorPicker')"
                        />
                        <input
                            :id="id"
                            v-model="form.data.brand_color"
                            v-bind="aria"
                            class="input w-32 font-mono"
                            placeholder="#5a61e0"
                            maxlength="7"
                        />
                        <button
                            v-if="form.data.brand_color"
                            type="button"
                            class="btn btn-ghost btn-sm"
                            @click="form.data.brand_color = ''"
                        >
                            {{ t('settings.branding.useDefault') }}
                        </button>
                    </div>
                </FormField>
                <!-- What the contrast check changed, so it never happens silently. -->
                <div
                    v-if="palette?.adjusted || palette?.darkText"
                    class="notice n-info mb-4 flex flex-wrap items-center gap-2"
                >
                    <span
                        class="inline-grid size-6 place-items-center rounded text-xs font-semibold"
                        :style="{ background: palette.button, color: palette.text }"
                        aria-hidden="true"
                        >Aa</span
                    >
                    <span class="min-w-0 flex-1">{{
                        palette.adjusted
                            ? t('settings.branding.adjusted', { color: palette.color, button: palette.button })
                            : t('settings.branding.darkText', { color: palette.color })
                    }}</span>
                </div>

                <button class="btn btn-primary" type="submit" :disabled="form.processing.value">
                    {{ t('common.save') }}
                </button>
            </fieldset>
        </form>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>{{ t('settings.branding.logo') }}</h2>
        </div>
        <div class="card-body">
            <div class="flex flex-wrap items-center gap-4">
                <img
                    v-if="auth.workspace.logo_url"
                    :src="auth.workspace.logo_url"
                    :alt="t('settings.branding.logoAlt')"
                    class="h-16 max-w-60 rounded border border-line-soft bg-surface-2 object-contain p-1"
                />
                <p v-else class="text-sm text-ink-3">{{ t('settings.branding.noLogo') }}</p>
                <div v-if="auth.isOwner" class="flex flex-wrap gap-2">
                    <label class="btn btn-sm" :class="{ 'pointer-events-none opacity-60': uploading }">
                        {{
                            auth.workspace.logo_url
                                ? t('settings.branding.replaceLogo')
                                : t('settings.branding.uploadLogo')
                        }}
                        <input type="file" class="sr-only" :accept="LOGO_TYPES" @change="upload" />
                    </label>
                    <button
                        v-if="auth.workspace.logo_url"
                        type="button"
                        class="btn btn-sm btn-ghost text-danger"
                        @click="removeLogo"
                    >
                        {{ t('settings.branding.removeLogo') }}
                    </button>
                </div>
            </div>
            <p v-if="logoError" class="mt-2 text-sm text-danger" role="alert">{{ logoError }}</p>
            <p class="mt-2 text-xs text-ink-3">{{ t('settings.branding.logoHint') }}</p>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>{{ t('settings.branding.preview') }}</h2>
        </div>
        <div class="card-body grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div
                v-for="theme in ['night', 'day']"
                :key="theme"
                class="overflow-hidden rounded-lg border border-line"
                :style="{ ...previewTokens(theme, palette), background: 'var(--canvas)', color: 'var(--ink)' }"
            >
                <div class="px-3 pt-2 text-[11px] tracking-wide text-ink-3 uppercase">
                    {{ t(`settings.branding.themes.${theme}`) }}
                </div>
                <div class="m-3 rounded-md border border-line bg-surface">
                    <div class="flex items-center gap-2 border-b border-line-soft px-3 py-2.5">
                        <img
                            v-if="auth.workspace.logo_url"
                            :src="auth.workspace.logo_url"
                            alt=""
                            class="h-7 max-w-28 object-contain"
                        />
                        <span
                            v-else
                            class="grid size-7 place-items-center rounded-full bg-brand text-[11px] font-semibold text-on-brand"
                            aria-hidden="true"
                            >{{ initials(shownName) }}</span
                        >
                        <span class="truncate font-serif">{{ shownName }}</span>
                    </div>
                    <div class="flex gap-3 border-b border-line-soft px-3 text-xs">
                        <span class="border-b-2 border-link py-2 text-ink">{{
                            t('settings.branding.sampleHome')
                        }}</span>
                        <span class="py-2 text-ink-3">{{ t('settings.branding.sampleAppointments') }}</span>
                    </div>
                    <div class="space-y-2 p-3 text-sm">
                        <p>
                            {{ t('settings.branding.sampleText') }}
                            <a href="#" @click.prevent>{{ t('settings.branding.sampleLink') }}</a>
                        </p>
                        <button type="button" class="btn btn-primary btn-sm" tabindex="-1">
                            {{ t('settings.branding.sampleButton') }}
                        </button>
                    </div>
                </div>
                <div class="flex items-center justify-center gap-1 pb-2 text-[11px] text-ink-3">
                    <BrandMark class="size-3" />{{ t('settings.branding.poweredBy') }}
                </div>
            </div>
        </div>
    </section>
</template>
