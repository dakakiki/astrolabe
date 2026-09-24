<script setup>
import { computed, onBeforeUnmount, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';

import { useLabels } from '@/composables/useLabels';
import http from '@/lib/http';

defineProps({
    inputId: { type: String, default: undefined },
    aria: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['select']);

const { t } = useI18n();
const labels = useLabels();
const listId = useId();

const query = ref('');
const results = ref([]);
const open = ref(false);
const active = ref(-1);
const searched = ref(false);

let timer;
let controller;

// Typed text is looked up in the server's local copy of GeoNames; nothing
// leaves the server (docs/spec/06).
function onInput() {
    clearTimeout(timer);
    const term = query.value.trim();

    if (term.length < 2) {
        results.value = [];
        open.value = false;
        return;
    }

    timer = setTimeout(() => search(term), 250);
}

async function search(term) {
    controller?.abort();
    controller = new AbortController();

    try {
        const { data } = await http.get('/places', { params: { q: term }, signal: controller.signal });
        results.value = data.data;
        active.value = data.data.length ? 0 : -1;
        searched.value = true;
        open.value = true;
    } catch (error) {
        if (error.name !== 'CanceledError') {
            results.value = [];
        }
    }
}

function choose(place) {
    emit('select', place);
    query.value = '';
    results.value = [];
    open.value = false;
}

function onKeydown(event) {
    if (!open.value || !results.value.length) return;

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = (active.value + 1) % results.value.length;
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = (active.value - 1 + results.value.length) % results.value.length;
    } else if (event.key === 'Enter' && active.value >= 0) {
        event.preventDefault();
        choose(results.value[active.value]);
    } else if (event.key === 'Escape') {
        open.value = false;
    }
}

function onBlur() {
    // Let a click on an option land before the list closes.
    setTimeout(() => (open.value = false), 150);
}

const activeId = computed(() => (active.value >= 0 ? `${listId}-${active.value}` : undefined));

onBeforeUnmount(() => {
    clearTimeout(timer);
    controller?.abort();
});
</script>

<template>
    <div class="relative">
        <input
            :id="inputId"
            v-model="query"
            v-bind="aria"
            class="input"
            type="text"
            role="combobox"
            autocomplete="off"
            aria-autocomplete="list"
            :aria-expanded="open"
            :aria-controls="listId"
            :aria-activedescendant="activeId"
            :placeholder="t('clients.birth.placePlaceholder')"
            @input="onInput"
            @keydown="onKeydown"
            @blur="onBlur"
        />
        <div v-if="open" class="menu left-0 w-full" style="right: auto">
            <ul :id="listId" role="listbox" class="max-h-72 overflow-y-auto">
                <li
                    v-for="(place, index) in results"
                    :id="`${listId}-${index}`"
                    :key="place.id"
                    role="option"
                    :aria-selected="index === active"
                    class="flex cursor-pointer items-baseline gap-2 rounded-md px-2.5 py-2"
                    :class="index === active ? 'bg-brand-050' : ''"
                    @mousedown.prevent="choose(place)"
                    @mouseenter="active = index"
                >
                    <span class="min-w-0 flex-1 truncate">
                        <span class="text-ink">{{ place.label }}</span>
                        <span class="text-ink-3">, {{ labels.country(place.country_code) }}</span>
                    </span>
                    <span class="font-mono text-[11px] text-ink-4">{{ place.timezone }}</span>
                </li>
            </ul>
            <p v-if="searched && !results.length" class="px-2.5 py-2 text-ink-3">{{ t('clients.birth.noPlaces') }}</p>
            <p class="border-t border-line-soft px-2.5 pt-1.5 text-[11px] text-ink-4">
                <a href="https://www.geonames.org/" target="_blank" rel="noopener">{{
                    t('clients.birth.placeAttribution')
                }}</a>
            </p>
        </div>
    </div>
</template>
