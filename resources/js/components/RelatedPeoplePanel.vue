<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRouter } from 'vue-router';

import ClientPicker from '@/components/ClientPicker.vue';
import FormField from '@/components/FormField.vue';
import { useForm } from '@/composables/useForm';
import { useLabels } from '@/composables/useLabels';
import { formatDate } from '@/lib/format';
import http from '@/lib/http';
import { useReferenceStore } from '@/stores/reference';
import { useToastStore } from '@/stores/toast';

/**
 * The "Related people" tab of a client (docs/spec/02, "Povezane osobe"): the
 * partner, children, parents … with their own birth data, and other clients
 * linked to this one. A link another client made shows here the other way
 * round (their "child" is this client's "parent") and is edited from here too.
 */
const props = defineProps({
    clientId: { type: Number, required: true },
});
const emit = defineEmits(['changed']);

const { t, locale } = useI18n();
const labels = useLabels();
const router = useRouter();
const reference = useReferenceStore();
const toast = useToastStore();

const links = ref(null);

async function load() {
    const { data } = await http.get(`/clients/${props.clientId}/relationships`);
    links.value = data.data;
}

onMounted(() => Promise.all([reference.load(), load()]));

const partyRoute = (link) =>
    link.kind === 'person'
        ? { name: 'related-people.show', params: { id: link.party.id } }
        : { name: 'clients.show', params: { id: link.party.id } };

function open(link) {
    if (editing.value !== link.id) router.push(partyRoute(link));
}

// Linking another client
const linking = ref(false);
const chosen = ref(null);
const linkForm = useForm({ relationship_type: 'partner', notes: '' });

function startLinking() {
    linking.value = !linking.value;
    chosen.value = null;
    linkForm.reset();
}

async function link() {
    try {
        await linkForm.submit((data) =>
            http.post(`/clients/${props.clientId}/relationships`, {
                related_client_id: chosen.value?.id ?? null,
                relationship_type: data.relationship_type,
                notes: data.notes || null,
            }),
        );
        linking.value = false;
        toast.success(t('related.linked'));
        await load();
        emit('changed');
    } catch {
        // Field errors are shown next to the fields; other failures as a toast.
    }
}

// Editing a link's type and notes in place
const editing = ref(null);
const editForm = useForm({ relationship_type: '', notes: '' });

function startEditing(link) {
    editing.value = link.id;
    editForm.reset({ relationship_type: link.relationship_type, notes: link.notes ?? '' });
}

async function saveEditing(link) {
    try {
        await editForm.submit((data) =>
            http.patch(`/client-relationships/${link.id}`, {
                relationship_type: data.relationship_type,
                notes: data.notes || null,
                as_seen_by: props.clientId,
            }),
        );
        editing.value = null;
        toast.success(t('related.updated'));
        await load();
    } catch {
        // Shown next to the fields or as a toast.
    }
}

async function remove(link) {
    if (!window.confirm(t('related.confirmRemove', { name: link.party.full_name }))) return;

    await http.delete(`/client-relationships/${link.id}`);
    toast.success(t('related.removed'));
    await load();
    emit('changed');
}

const birthLine = (birth) =>
    [formatDate(birth.birth_date, locale.value, 'medium'), birth.birth_time, birth.birth_place]
        .filter(Boolean)
        .join(' · ');
</script>

<template>
    <section class="card">
        <div class="card-head">
            <h2>{{ t('related.title') }}</h2>
            <span class="right flex flex-wrap gap-2">
                <button type="button" class="btn btn-sm" :aria-expanded="linking" @click="startLinking">
                    {{ t('related.linkClient') }}
                </button>
                <RouterLink :to="{ name: 'related-people.create', params: { clientId } }" class="btn btn-sm btn-primary"
                    >+ {{ t('related.add') }}</RouterLink
                >
            </span>
        </div>

        <form v-if="linking" class="card-body border-b border-line-soft" novalidate @submit.prevent="link">
            <div class="row">
                <FormField
                    v-slot="{ id, aria }"
                    :label="t('related.linkForm.client')"
                    :error="linkForm.errors.value.related_client_id"
                >
                    <ClientPicker :input-id="id" :aria="aria" :selected="chosen?.full_name" @select="chosen = $event" />
                </FormField>
                <FormField
                    v-slot="{ id, aria }"
                    :label="t('related.linkForm.relationship')"
                    :error="linkForm.errors.value.relationship_type"
                    :hint="t('related.form.relationshipHint')"
                >
                    <select :id="id" v-model="linkForm.data.relationship_type" v-bind="aria" class="input">
                        <option v-for="type in reference.data?.relationship_types ?? []" :key="type" :value="type">
                            {{ labels.relationship(type) }}
                        </option>
                    </select>
                </FormField>
            </div>
            <FormField v-slot="{ id, aria }" :label="t('related.linkForm.notes')" :error="linkForm.errors.value.notes">
                <input :id="id" v-model="linkForm.data.notes" v-bind="aria" class="input" autocomplete="off" />
            </FormField>
            <div class="flex gap-2">
                <button class="btn btn-primary btn-sm" type="submit" :disabled="!chosen || linkForm.processing.value">
                    {{ t('related.linkForm.save') }}
                </button>
                <button type="button" class="btn btn-ghost btn-sm" @click="linking = false">
                    {{ t('related.cancel') }}
                </button>
            </div>
        </form>

        <div class="overflow-x-auto">
            <table v-if="links?.length" class="data">
                <thead>
                    <tr>
                        <th>{{ t('related.columns.name') }}</th>
                        <th>{{ t('related.columns.relationship') }}</th>
                        <th>{{ t('related.columns.birth') }}</th>
                        <th>{{ t('related.columns.chart') }}</th>
                        <th>
                            <span class="sr-only">{{ t('related.edit') }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="link in links" :key="link.id" @click="open(link)">
                        <td>
                            <RouterLink
                                :to="partyRoute(link)"
                                class="font-medium text-ink hover:underline"
                                @click.stop
                                >{{ link.party.full_name }}</RouterLink
                            >
                            <span v-if="link.kind === 'client'" class="tag ml-2">{{ t('related.kinds.client') }}</span>
                            <div v-if="link.notes && editing !== link.id" class="text-xs text-ink-3">
                                {{ link.notes }}
                            </div>
                        </td>
                        <td v-if="editing === link.id" colspan="3" @click.stop>
                            <div class="flex flex-wrap items-start gap-2">
                                <select
                                    v-model="editForm.data.relationship_type"
                                    class="input w-auto"
                                    :aria-label="t('related.columns.relationship')"
                                >
                                    <option
                                        v-for="type in reference.data?.relationship_types ?? []"
                                        :key="type"
                                        :value="type"
                                    >
                                        {{ labels.relationship(type) }}
                                    </option>
                                </select>
                                <input
                                    v-model="editForm.data.notes"
                                    class="input min-w-48 flex-1"
                                    :aria-label="t('related.linkForm.notes')"
                                    :placeholder="t('related.linkForm.notes')"
                                />
                            </div>
                            <div v-if="editForm.errors.value.notes" class="error">
                                {{ editForm.errors.value.notes }}
                            </div>
                        </td>
                        <template v-else>
                            <td>{{ labels.relationship(link.relationship_type) }}</td>
                            <td class="text-xs text-ink-2">
                                <template v-if="link.party.birth">{{ birthLine(link.party.birth) }}</template>
                                <span v-else class="text-ink-4">{{ t('related.noBirth') }}</span>
                            </td>
                            <td>
                                <span v-if="link.party.birth?.chart_ready" class="badge b-ok">{{
                                    t('related.chartReady')
                                }}</span>
                                <span v-else class="badge b-warn">{{ t('related.chartIncomplete') }}</span>
                            </td>
                        </template>
                        <td class="text-right whitespace-nowrap" @click.stop>
                            <template v-if="editing === link.id">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-primary"
                                    :disabled="editForm.processing.value"
                                    @click="saveEditing(link)"
                                >
                                    {{ t('related.save') }}
                                </button>
                                <button type="button" class="btn btn-sm btn-ghost" @click="editing = null">
                                    {{ t('related.cancel') }}
                                </button>
                            </template>
                            <template v-else>
                                <button type="button" class="btn btn-sm" @click="startEditing(link)">
                                    {{ t('related.edit') }}
                                </button>
                                <button type="button" class="btn btn-sm btn-ghost" @click="remove(link)">
                                    {{ t('related.remove') }}
                                </button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div v-else-if="links" class="empty">
                <div class="e-glyph" aria-hidden="true">◌</div>
                <h3>{{ t('related.emptyTitle') }}</h3>
                <p class="text-ink-3">{{ t('related.emptyText') }}</p>
            </div>
            <p v-else class="p-6 text-ink-3">{{ t('common.loading') }}</p>
        </div>
    </section>
</template>
