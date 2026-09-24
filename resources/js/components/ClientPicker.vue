<script setup>
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';

import { initials } from '@/lib/format';
import http from '@/lib/http';

/**
 * Finds a client by name as you type (the same search as the client list).
 */
const props = defineProps({
    inputId: { type: String, default: undefined },
    aria: { type: Object, default: () => ({}) },
    /** The name of the client already chosen, e.g. when the page was opened from a profile. */
    selected: { type: String, default: '' },
});
const emit = defineEmits(['select']);

const { t } = useI18n();
const listId = useId();

const query = ref('');
watch(
    () => props.selected,
    (name) => {
        if (name) query.value = name;
    },
    { immediate: true },
);
const results = ref([]);
const open = ref(false);
const active = ref(-1);

let timer;
let controller;

function onInput() {
    clearTimeout(timer);
    timer = setTimeout(search, 250);
}

async function search() {
    controller?.abort();
    controller = new AbortController();

    try {
        const { data } = await http.get('/clients', {
            params: { search: query.value.trim() || undefined, sort: '-last_activity_at', per_page: 8 },
            signal: controller.signal,
        });
        results.value = data.data;
        active.value = data.data.length ? 0 : -1;
        open.value = true;
    } catch (error) {
        if (error.name !== 'CanceledError') results.value = [];
    }
}

function choose(client) {
    emit('select', client);
    query.value = client.full_name;
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
            :placeholder="t('consultations.form.chooseClient')"
            @input="onInput"
            @focus="search"
            @keydown="onKeydown"
            @blur="onBlur"
        />
        <div v-if="open && results.length" class="menu left-0 w-full" style="right: auto">
            <ul :id="listId" role="listbox" class="max-h-72 overflow-y-auto">
                <li
                    v-for="(client, index) in results"
                    :id="`${listId}-${index}`"
                    :key="client.id"
                    role="option"
                    :aria-selected="index === active"
                    class="person cursor-pointer rounded-md px-2.5 py-2"
                    :class="index === active ? 'bg-brand-050' : ''"
                    @mousedown.prevent="choose(client)"
                    @mouseenter="active = index"
                >
                    <span class="ini" aria-hidden="true">{{ initials(client.full_name) }}</span>
                    <span class="min-w-0 truncate">
                        <span class="nm">{{ client.full_name }}</span>
                        <span v-if="client.email" class="meta ml-2">{{ client.email }}</span>
                    </span>
                </li>
            </ul>
        </div>
    </div>
</template>
