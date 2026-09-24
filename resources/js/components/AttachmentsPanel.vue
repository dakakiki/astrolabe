<script setup>
import { computed, onMounted, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

import VisibilityBadge from '@/components/VisibilityBadge.vue';
import { formatDateTime } from '@/lib/datetime';
import { acceptAttribute, checkFile, fileKind, formatBytes } from '@/lib/files';
import http, { validationErrors } from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useReferenceStore } from '@/stores/reference';
import { useToastStore } from '@/stores/toast';

/**
 * Files and links of a client (including those on their consultations) or of
 * one consultation. Uploads go to private storage; downloads go through the
 * authorized API route, never a public URL (docs/spec/03).
 */
const props = defineProps({
    clientId: { type: [Number, String], default: null },
    consultationId: { type: [Number, String], default: null },
    compact: { type: Boolean, default: false },
});
const emit = defineEmits(['changed']);

const { t, locale } = useI18n();
const auth = useAuthStore();
const reference = useReferenceStore();
const toast = useToastStore();
const inputId = useId();

const items = ref([]);
const loading = ref(true);
const over = ref(false);
const uploading = ref(null);
const visibility = ref('private');
const showLink = ref(false);
const link = ref({ url: '', title: '' });
const linkErrors = ref({});

const limits = computed(() => reference.data?.attachments ?? { max_size: 0, extensions: [] });
const owner = computed(() =>
    props.consultationId ? { consultation_id: props.consultationId } : { client_id: props.clientId },
);

async function load() {
    loading.value = true;
    try {
        const { data } = await http.get('/attachments', { params: { ...owner.value, per_page: 100 } });
        items.value = data.data;
    } finally {
        loading.value = false;
    }
}

onMounted(() => Promise.all([reference.load(), load()]));

async function upload(files) {
    for (const file of files) {
        const refused = checkFile(file, { maxSize: limits.value.max_size, extensions: limits.value.extensions });
        if (refused) {
            toast.error(
                refused === 'type'
                    ? t('files.refusedType', { name: file.name })
                    : t('files.refusedSize', {
                          name: file.name,
                          size: formatBytes(limits.value.max_size, locale.value),
                      }),
            );
            continue;
        }

        const body = new FormData();
        Object.entries(owner.value).forEach(([key, value]) => body.append(key, value));
        body.append('visibility', visibility.value);
        body.append('file', file);

        uploading.value = { name: file.name, progress: 0 };
        try {
            await http.post('/attachments', body, {
                onUploadProgress: (event) => {
                    if (event.total) uploading.value.progress = Math.round((event.loaded / event.total) * 100);
                },
            });
            toast.success(t('files.uploaded', { name: file.name }));
        } catch (error) {
            toast.error(validationErrors(error)?.file ?? t('errors.generic'));
        }
    }

    uploading.value = null;
    await load();
    emit('changed');
}

function onDrop(event) {
    over.value = false;
    upload([...(event.dataTransfer?.files ?? [])]);
}

function onPick(event) {
    upload([...event.target.files]);
    event.target.value = '';
}

async function addLink() {
    linkErrors.value = {};
    try {
        await http.post('/attachments', {
            ...owner.value,
            kind: 'link',
            url: link.value.url,
            title: link.value.title || null,
            visibility: visibility.value,
        });
        toast.success(t('files.linkAdded'));
        link.value = { url: '', title: '' };
        showLink.value = false;
        await load();
        emit('changed');
    } catch (error) {
        linkErrors.value = validationErrors(error) ?? {};
        if (!validationErrors(error)) toast.error(t('errors.generic'));
    }
}

async function changeVisibility(item, value) {
    const { data } = await http.patch(`/attachments/${item.id}`, { visibility: value });
    Object.assign(item, data.data);
    toast.success(t('files.visibilityChanged'));
}

async function remove(item) {
    if (!window.confirm(t('files.confirmRemove', { name: item.name }))) return;

    await http.delete(`/attachments/${item.id}`);
    toast.success(t('files.removed'));
    await load();
    emit('changed');
}

const kindLabel = (item) => t(`files.kinds.${item.kind === 'link' ? 'link' : fileKind(item.mime_type)}`);
const glyph = { image: '▣', pdf: '▤', document: '▤', audio: '♫', video: '▶', text: '≡', file: '▢', link: '↗' };
</script>

<template>
    <div>
        <div
            class="dropzone"
            :class="{ 'is-over': over }"
            @dragover.prevent="over = true"
            @dragleave.prevent="over = false"
            @drop.prevent="onDrop"
        >
            <p v-if="uploading" role="status">
                {{ t('files.uploading', { name: uploading.name }) }} {{ uploading.progress }}%
            </p>
            <template v-else>
                <p>
                    {{ over ? t('files.dropActive') : t('files.drop') }}
                    <label :for="inputId" class="cursor-pointer text-link underline">{{ t('files.choose') }}</label>
                    <input
                        :id="inputId"
                        type="file"
                        multiple
                        class="sr-only"
                        :accept="acceptAttribute(limits.extensions)"
                        @change="onPick"
                    />
                </p>
                <p class="mt-1 text-xs text-ink-4">
                    {{
                        t('files.allowed', {
                            types: limits.extensions.join(', '),
                            size: formatBytes(limits.max_size, locale),
                        })
                    }}
                </p>
            </template>
            <div class="mt-3 flex flex-wrap items-center justify-center gap-2 text-xs">
                <label :for="`${inputId}-visibility`">{{ t('files.uploadVisibility') }}</label>
                <select :id="`${inputId}-visibility`" v-model="visibility" class="input w-auto py-1 text-xs">
                    <option v-for="value in ['private', 'team', 'shared_with_client']" :key="value" :value="value">
                        {{ t(`visibility.${value}`) }}
                    </option>
                </select>
                <button type="button" class="btn btn-sm" @click="showLink = !showLink">{{ t('files.addLink') }}</button>
            </div>
        </div>

        <form v-if="showLink" class="mt-3 flex flex-wrap items-start gap-2" novalidate @submit.prevent="addLink">
            <div class="min-w-56 flex-1">
                <label class="sr-only" :for="`${inputId}-url`">{{ t('files.linkUrl') }}</label>
                <input
                    :id="`${inputId}-url`"
                    v-model="link.url"
                    class="input"
                    type="url"
                    placeholder="https://"
                    :aria-invalid="linkErrors.url ? 'true' : undefined"
                    required
                />
                <div v-if="linkErrors.url" class="mt-1 text-xs text-danger">{{ linkErrors.url }}</div>
            </div>
            <div class="min-w-48 flex-1">
                <label class="sr-only" :for="`${inputId}-title`">{{ t('files.linkTitle') }}</label>
                <input
                    :id="`${inputId}-title`"
                    v-model="link.title"
                    class="input"
                    :placeholder="t('files.linkTitlePlaceholder')"
                />
            </div>
            <button type="submit" class="btn btn-primary">{{ t('files.linkSave') }}</button>
        </form>

        <!-- A narrow column (the consultation page) gets a list instead of the table. -->
        <ul v-if="compact && items.length" class="mt-3 divide-y divide-line-soft">
            <li v-for="item in items" :key="item.id" class="py-2.5">
                <div class="flex items-start gap-2">
                    <span class="text-ink-3" aria-hidden="true">{{
                        glyph[item.kind === 'link' ? 'link' : fileKind(item.mime_type)]
                    }}</span>
                    <div class="min-w-0 flex-1">
                        <a
                            v-if="item.kind === 'link'"
                            :href="item.url"
                            target="_blank"
                            rel="noopener noreferrer nofollow"
                            class="block truncate"
                            >{{ item.name }}</a
                        >
                        <a v-else :href="item.download_url" class="block truncate" download>{{ item.name }}</a>
                        <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-3">
                            <span>{{ kindLabel(item) }}</span>
                            <span v-if="item.file_size !== null">{{ formatBytes(item.file_size, locale) }}</span>
                            <select
                                v-if="item.can_edit"
                                class="input w-auto px-1.5 py-0.5 text-xs"
                                :value="item.visibility"
                                :aria-label="t('visibility.label')"
                                @change="changeVisibility(item, $event.target.value)"
                            >
                                <option
                                    v-for="value in ['private', 'team', 'shared_with_client']"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ t(`visibility.${value}`) }}
                                </option>
                            </select>
                            <VisibilityBadge v-else :visibility="item.visibility" />
                        </div>
                    </div>
                    <div class="flex shrink-0">
                        <a
                            v-if="item.previewable"
                            :href="`${item.download_url}?inline=1`"
                            target="_blank"
                            rel="noopener"
                            class="btn btn-ghost btn-sm"
                            >{{ t('files.open') }}</a
                        >
                        <button v-if="item.can_edit" type="button" class="btn btn-ghost btn-sm" @click="remove(item)">
                            {{ t('files.remove') }}
                        </button>
                    </div>
                </div>
            </li>
        </ul>

        <div v-else class="mt-3 overflow-x-auto">
            <table v-if="items.length" class="data">
                <thead>
                    <tr>
                        <th>{{ t('files.columns.name') }}</th>
                        <th class="text-right">{{ t('files.columns.size') }}</th>
                        <th>{{ t('files.columns.visibility') }}</th>
                        <th>{{ t('files.columns.added') }}</th>
                        <th>
                            <span class="sr-only">{{ t('common.more') }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in items" :key="item.id" class="cursor-default!">
                        <td class="max-w-72">
                            <div class="flex items-center gap-2">
                                <span class="text-ink-3" aria-hidden="true">{{
                                    glyph[item.kind === 'link' ? 'link' : fileKind(item.mime_type)]
                                }}</span>
                                <div class="min-w-0">
                                    <a
                                        v-if="item.kind === 'link'"
                                        :href="item.url"
                                        target="_blank"
                                        rel="noopener noreferrer nofollow"
                                        class="block truncate"
                                        >{{ item.name }}</a
                                    >
                                    <a v-else :href="item.download_url" class="block truncate" download>{{
                                        item.name
                                    }}</a>
                                    <div class="text-xs text-ink-3">
                                        {{ kindLabel(item) }}
                                        <template v-if="item.consultation_id && !consultationId">
                                            ·
                                            <RouterLink
                                                :to="{
                                                    name: 'consultations.show',
                                                    params: { id: item.consultation_id },
                                                }"
                                                class="hover:underline"
                                                >{{ t('files.onConsultation') }}</RouterLink
                                            >
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="text-right whitespace-nowrap text-ink-3">
                            {{ formatBytes(item.file_size, locale) }}
                        </td>
                        <td>
                            <select
                                v-if="item.can_edit"
                                class="input w-auto py-1 text-xs"
                                :value="item.visibility"
                                :aria-label="t('visibility.label')"
                                @change="changeVisibility(item, $event.target.value)"
                            >
                                <option
                                    v-for="value in ['private', 'team', 'shared_with_client']"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ t(`visibility.${value}`) }}
                                </option>
                            </select>
                            <VisibilityBadge v-else :visibility="item.visibility" />
                        </td>
                        <td class="text-xs whitespace-nowrap text-ink-3">
                            {{ formatDateTime(item.created_at, locale, auth.user?.timezone) }}
                            <div v-if="item.uploader">{{ item.uploader.name }}</div>
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <a
                                v-if="item.previewable"
                                :href="`${item.download_url}?inline=1`"
                                target="_blank"
                                rel="noopener"
                                class="btn btn-ghost btn-sm"
                                >{{ t('files.open') }}</a
                            >
                            <button
                                v-if="item.can_edit"
                                type="button"
                                class="btn btn-ghost btn-sm"
                                @click="remove(item)"
                            >
                                {{ t('files.remove') }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else-if="!loading" class="empty">
                {{ consultationId ? t('files.emptyConsultation') : t('files.empty') }}
            </p>
        </div>

        <div class="engine-note -mx-4 mt-3 -mb-4">
            <span>{{ t('files.footer.private') }}</span>
            <span>{{ t('files.footer.verified') }}</span>
            <span>{{ t('files.footer.authorised') }}</span>
        </div>
    </div>
</template>
