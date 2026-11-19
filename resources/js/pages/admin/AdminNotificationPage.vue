<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import AppIcon from '@/components/AppIcon.vue';
import http from '@/lib/http';
import { insertPlaceholder, placeholderProblems, shownText, textsPayload, unsavedTexts } from '@/lib/notificationTexts';
import { useToastStore } from '@/stores/toast';

/**
 * One notification's texts in the operator's words (Admin → Notifications; docs/spec/09):
 * each text with where it shows, the placeholders it may use — a click puts one at the
 * cursor — and how many other notifications share it; beside them the notification as it
 * will be sent, redrawn as the texts change (nothing is stored until Save). "Default"
 * brings a text back to the code's words. Links, codes and buttons' addresses are not here.
 */
const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const toast = useToastStore();

const message = ref(null);
const values = ref({});
const preview = ref(null);
const errors = ref({});
const failed = ref(false);
const saving = ref(false);
const sample = ref('client');
const fields = {};
let lastFocused = null;
let timer = null;

const id = computed(() => String(route.params.id));
const isPush = computed(() => message.value?.channel === 'push');
const isLayout = computed(() => message.value?.id === 'layout');
const changed = computed(
    () => message.value && Object.keys(unsavedTexts(message.value.texts, values.value)).length > 0,
);
const shownPreview = computed(() => (isLayout.value ? preview.value?.[sample.value] : preview.value));
const backTab = computed(() => (isPush.value ? 'push' : isLayout.value ? 'layout' : undefined));

async function load() {
    failed.value = false;
    try {
        const { data } = await http.get(`/admin/notifications/${id.value}`);
        apply(data.data);
    } catch (error) {
        failed.value = true;
        if (error.response?.status === 404) router.replace({ name: 'admin.notifications' });
    }
}

function apply(data) {
    message.value = data;
    values.value = Object.fromEntries(data.texts.map((text) => [text.key, shownText(text)]));
    preview.value = data.preview;
    errors.value = {};
}

watch(id, load, { immediate: true });

// The preview follows the typing, a moment after it stops.
watch(
    values,
    () => {
        if (!message.value) return;
        window.clearTimeout(timer);
        timer = window.setTimeout(refreshPreview, 450);
    },
    { deep: true },
);

onBeforeUnmount(() => window.clearTimeout(timer));

async function refreshPreview() {
    const texts = unsavedTexts(message.value.texts, values.value);

    try {
        const { data } = await http.post(`/admin/notifications/${id.value}/preview`, { texts });
        preview.value = data.data;
        errors.value = {};
    } catch (error) {
        if (error.response?.status === 422) errors.value = error.response.data.errors ?? {};
    }
}

async function save() {
    saving.value = true;
    try {
        const { data } = await http.put(`/admin/notifications/${id.value}`, {
            texts: textsPayload(message.value.texts, values.value),
        });
        apply(data.data);
        toast.success(t('admin.notifications.saved'));
    } catch (error) {
        if (error.response?.status === 422) {
            errors.value = error.response.data.errors ?? {};
        } else {
            toast.error(t('errors.generic'));
        }
    } finally {
        saving.value = false;
    }
}

function reset(text) {
    values.value[text.key] = text.default;
}

async function insert(text, name) {
    const field = fields[text.key];
    const start = lastFocused === text.key && field ? field.selectionStart : values.value[text.key].length;
    const end = lastFocused === text.key && field ? field.selectionEnd : start;
    const result = insertPlaceholder(values.value[text.key], name, start, end);

    values.value[text.key] = result.value;
    await nextTick();
    field?.focus();
    field?.setSelectionRange(result.cursor, result.cursor);
}

// Where a text shows, in the words of the screen.
function roleOf(text) {
    if (text.role === 'layout') return t(`admin.notifications.layoutKeys.${text.key.replace('mail_layout.', '')}`);

    return t(`admin.notifications.roles.${text.role}`);
}

function problemsOf(text) {
    return placeholderProblems(values.value[text.key] ?? '', text);
}

function errorOf(text) {
    return errors.value[`texts.${text.key}`]?.[0] ?? null;
}

// The email drawn inside its own frame; its height follows its content.
function fit(event) {
    try {
        const frame = event.target;
        frame.style.height = `${Math.max(320, frame.contentDocument.documentElement.scrollHeight + 8)}px`;
    } catch {
        // The frame keeps its height.
    }
}
</script>

<template>
    <nav v-if="message" class="crumbs" :aria-label="t('admin.notifications.where')">
        <RouterLink :to="{ name: 'admin.notifications', query: backTab ? { tab: backTab } : {} }">{{
            t('admin.notifications.title')
        }}</RouterLink>
        <span aria-hidden="true">›</span>
        <span>{{ message.label }}</span>
    </nav>

    <div class="page-head">
        <div class="eyebrow">
            {{
                message
                    ? t(
                          `admin.notifications.channels.${message.channel === 'push' ? 'push' : isLayout ? 'layout' : 'mail'}`,
                      )
                    : ''
            }}
        </div>
        <h1>{{ message?.label ?? t('admin.notifications.title') }}</h1>
        <div v-if="message?.when" class="sub">{{ message.when }}</div>
    </div>

    <p v-if="failed" class="notice n-warn" role="alert">{{ t('errors.generic') }}</p>
    <p v-else-if="!message" class="text-ink-3" role="status">{{ t('common.loading') }}</p>

    <div v-else class="notif-editor">
        <form class="card" novalidate @submit.prevent="save">
            <div class="card-head">
                <h2>{{ t('admin.notifications.texts') }}</h2>
            </div>
            <div class="card-body space-y-5">
                <p class="text-xs text-ink-3">{{ t('admin.notifications.textsHint') }}</p>

                <div v-for="text in message.texts" :key="text.key" class="field mb-0">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <label class="label mb-0" :for="`text-${text.key}`">{{ roleOf(text) }}</label>
                        <button
                            v-if="values[text.key] !== text.default"
                            type="button"
                            class="btn btn-ghost btn-sm"
                            @click="reset(text)"
                            v-tip="t('tips.admin.notifications.reset')"
                        >
                            <AppIcon name="undo" />
                            {{ t('admin.notifications.reset') }}
                        </button>
                    </div>
                    <textarea
                        :id="`text-${text.key}`"
                        :ref="(el) => (fields[text.key] = el)"
                        v-model="values[text.key]"
                        class="input font-mono text-[13px]"
                        :rows="text.role === 'subject' || text.role === 'title' || text.role === 'action' ? 1 : 3"
                        :aria-invalid="errorOf(text) ? 'true' : undefined"
                        :aria-describedby="`text-${text.key}-hint`"
                        @focus="lastFocused = text.key"
                    />
                    <div :id="`text-${text.key}-hint`" class="mt-1.5 space-y-1">
                        <div v-if="errorOf(text)" class="error" role="alert">{{ errorOf(text) }}</div>
                        <div v-else-if="problemsOf(text).missing.length" class="error" role="alert">
                            {{
                                t('admin.notifications.keep', {
                                    names: problemsOf(text)
                                        .missing.map((n) => `:${n}`)
                                        .join(', '),
                                })
                            }}
                        </div>
                        <div v-else-if="problemsOf(text).unknown.length" class="error" role="alert">
                            {{
                                t('admin.notifications.unknown', {
                                    names: problemsOf(text)
                                        .unknown.map((n) => `:${n}`)
                                        .join(', '),
                                })
                            }}
                        </div>
                        <div v-if="text.placeholders.length" class="flex flex-wrap items-center gap-1.5">
                            <span class="text-xs text-ink-3">{{ t('admin.notifications.placeholders') }}</span>
                            <button
                                v-for="name in text.placeholders"
                                :key="name"
                                type="button"
                                class="chip-btn"
                                :class="{ required: text.required.includes(name) }"
                                @click="insert(text, name)"
                                v-tip="t('tips.admin.notifications.placeholder', { name: `:${name}` })"
                            >
                                <AppIcon name="add" />:{{ name }}
                            </button>
                        </div>
                        <div v-if="text.shared" class="text-xs text-ink-3">
                            <AppIcon name="warning" class="inline-icon" />
                            {{ t('admin.notifications.shared', { count: text.shared }, text.shared) }}
                        </div>
                        <div class="font-mono text-[11px] text-ink-4">{{ text.key }}</div>
                    </div>
                </div>
            </div>
            <div class="card-body border-t border-line-soft">
                <div class="form-actions">
                    <RouterLink
                        :to="{ name: 'admin.notifications', query: backTab ? { tab: backTab } : {} }"
                        class="btn btn-ghost form-cancel"
                        v-tip="t('tips.admin.notifications.back')"
                        ><AppIcon name="back" />{{ t('admin.notifications.back') }}</RouterLink
                    >
                    <button
                        type="submit"
                        class="btn btn-primary"
                        :disabled="saving || !changed"
                        v-tip="t('tips.admin.notifications.save')"
                    >
                        <AppIcon name="save" />
                        {{ t('admin.notifications.save') }}
                    </button>
                </div>
            </div>
        </form>

        <section class="card notif-preview">
            <div class="card-head">
                <h2>{{ t('admin.notifications.preview') }}</h2>
                <div v-if="isLayout" class="seg right" role="group" :aria-label="t('admin.notifications.previewAs')">
                    <button
                        v-for="option in ['client', 'astrologer']"
                        :key="option"
                        type="button"
                        :aria-pressed="sample === option"
                        @click="sample = option"
                    >
                        {{ t(`admin.notifications.samples.${option}`) }}
                    </button>
                </div>
            </div>
            <div class="card-body">
                <p class="mb-3 text-xs text-ink-3">{{ t('admin.notifications.previewHint') }}</p>
                <template v-if="isPush">
                    <div class="push-phone">
                        <div class="push-card">
                            <span class="push-icon" aria-hidden="true">A</span>
                            <span class="min-w-0">
                                <span class="push-app">AstroLabe · {{ t('admin.notifications.now') }}</span>
                                <strong class="block">{{ shownPreview?.title }}</strong>
                                <span class="block text-ink-2">{{ shownPreview?.body }}</span>
                            </span>
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-ink-3">
                        {{
                            t('admin.notifications.pushLength', {
                                title: shownPreview?.title?.length ?? 0,
                                body: shownPreview?.body?.length ?? 0,
                            })
                        }}
                    </p>
                </template>
                <template v-else-if="shownPreview">
                    <div class="mail-frame">
                        <div class="mail-subject">
                            <span class="text-ink-3">{{ t('admin.notifications.subject') }}</span>
                            <strong>{{ shownPreview.subject }}</strong>
                        </div>
                        <iframe
                            :title="t('admin.notifications.preview')"
                            :srcdoc="shownPreview.html"
                            sandbox="allow-same-origin"
                            @load="fit"
                        />
                    </div>
                </template>
            </div>
        </section>
    </div>
</template>
