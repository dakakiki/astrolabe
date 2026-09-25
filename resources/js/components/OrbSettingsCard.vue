<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

import FormField from '@/components/FormField.vue';
import { useForm } from '@/composables/useForm';
import { ASPECT_GLYPHS } from '@/lib/chart';
import http from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useReferenceStore } from '@/stores/reference';
import { useToastStore } from '@/stores/toast';

/**
 * One set of aspects and orbs of the workspace: between natal points
 * (`aspect_orbs`) or for transits to a natal chart (`transit_orbs`). Both have
 * the same shape; only the owner changes them.
 */
const props = defineProps({
    /** 'aspect_orbs' or 'transit_orbs' */
    field: { type: String, required: true },
    title: { type: String, required: true },
    intro: { type: String, required: true },
    saved: { type: String, required: true },
});

const { t } = useI18n();
const auth = useAuthStore();
const reference = useReferenceStore();
const toast = useToastStore();

// Store values are reactive proxies, which structuredClone refuses; plain data survives JSON.
const copy = (value) => JSON.parse(JSON.stringify(value));

const orbs = useForm(copy(auth.workspace[props.field]));

const defaults = computed(() =>
    props.field === 'transit_orbs' ? reference.data?.aspects.transit_defaults : reference.data?.aspects.defaults,
);

const aspectGroups = computed(() => {
    const types = reference.data?.aspects.types ?? [];

    return [
        ['major', types.filter((type) => type.major)],
        ['minor', types.filter((type) => !type.major)],
    ];
});

const error = (path) => orbs.errors.value[`${props.field}.${path}`];

function restore() {
    Object.assign(orbs.data, copy(defaults.value));
}

async function save() {
    await orbs
        .submit(async (data) => {
            const response = await http.patch('/workspace', { [props.field]: data });
            auth.workspace = response.data.data;
            toast.success(props.saved);
        })
        .catch(() => {});
}
</script>

<template>
    <section class="card">
        <div class="card-head">
            <h2>{{ title }}</h2>
        </div>
        <form class="card-body" novalidate @submit.prevent="save">
            <fieldset class="min-w-0" :disabled="!auth.isOwner">
                <p class="mb-4 text-ink-3">{{ intro }}</p>

                <div class="mb-4 grid grid-cols-1 gap-x-8 gap-y-4 md:grid-cols-2">
                    <div v-for="[group, types] in aspectGroups" :key="group" class="overflow-x-auto">
                        <table class="data">
                            <caption class="pb-1 text-left text-xs font-semibold text-ink-2">
                                {{
                                    t(`settings.orbs.${group}`)
                                }}
                            </caption>
                            <thead>
                                <tr>
                                    <th scope="col">{{ t('settings.orbs.aspect') }}</th>
                                    <th scope="col" class="text-center">{{ t('settings.orbs.shown') }}</th>
                                    <th scope="col">{{ t('settings.orbs.orb') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="aspect in types" :key="aspect.type" class="cursor-default!">
                                    <th scope="row" :class="`asp-${aspect.type}`">
                                        <span
                                            class="aspect-glyph mr-1.5 inline-block w-4 text-center text-base"
                                            aria-hidden="true"
                                            >{{ ASPECT_GLYPHS[aspect.type] }}</span
                                        >
                                        {{ t(`aspectTypes.${aspect.type}`) }}
                                        <span class="ml-1 font-mono text-xs text-ink-4">{{ aspect.angle }}°</span>
                                    </th>
                                    <td class="text-center">
                                        <input
                                            v-model="orbs.data.aspects[aspect.type].enabled"
                                            type="checkbox"
                                            :aria-label="`${t(`aspectTypes.${aspect.type}`)}: ${t('settings.orbs.shown')}`"
                                        />
                                    </td>
                                    <td>
                                        <input
                                            v-model.number="orbs.data.aspects[aspect.type].orb"
                                            type="number"
                                            class="input w-20! py-1!"
                                            step="0.25"
                                            min="0.25"
                                            :max="reference.data?.aspects.max_orb"
                                            :aria-label="`${t(`aspectTypes.${aspect.type}`)}: ${t('settings.orbs.orb')}`"
                                            :aria-invalid="error(`aspects.${aspect.type}.orb`) ? 'true' : undefined"
                                        />
                                        <div
                                            v-if="error(`aspects.${aspect.type}.orb`)"
                                            class="mt-1 text-xs text-danger"
                                        >
                                            {{ error(`aspects.${aspect.type}.orb`) }}
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="max-w-sm">
                    <FormField
                        v-slot="{ id, aria }"
                        :label="t('settings.orbs.luminaryBonus')"
                        :hint="t('settings.orbs.luminaryBonusHint')"
                        :error="error('luminary_bonus')"
                    >
                        <input
                            :id="id"
                            v-model.number="orbs.data.luminary_bonus"
                            v-bind="aria"
                            type="number"
                            class="input w-24!"
                            step="0.25"
                            min="0"
                            :max="reference.data?.aspects.max_luminary_bonus"
                        />
                    </FormField>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit" :disabled="orbs.processing.value">
                        {{ t('common.save') }}
                    </button>
                    <button class="btn btn-ghost" type="button" :disabled="!defaults" @click="restore">
                        {{ t('settings.orbs.reset') }}
                    </button>
                </div>
            </fieldset>
        </form>
    </section>
</template>
