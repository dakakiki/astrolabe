<script setup>
import { ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';

const model = defineModel({ type: Array, default: () => [] });

defineProps({
    inputId: { type: String, default: undefined },
    suggestions: { type: Array, default: () => [] },
});

const { t } = useI18n();
const listId = useId();
const draft = ref('');

function add() {
    const name = draft.value.trim().replace(/,$/, '').trim();

    if (name && !model.value.some((tag) => tag.toLowerCase() === name.toLowerCase())) {
        model.value = [...model.value, name];
    }
    draft.value = '';
}

function remove(name) {
    model.value = model.value.filter((tag) => tag !== name);
}

function onKeydown(event) {
    if (event.key === 'Enter' || event.key === ',') {
        event.preventDefault();
        add();
    } else if (event.key === 'Backspace' && !draft.value && model.value.length) {
        remove(model.value[model.value.length - 1]);
    }
}
</script>

<template>
    <div class="input flex flex-wrap items-center gap-1.5">
        <span v-for="tag in model" :key="tag" class="tag inline-flex items-center gap-1">
            {{ tag }}
            <button
                type="button"
                class="cursor-pointer text-ink-4 hover:text-danger"
                :aria-label="`× ${tag}`"
                @click="remove(tag)"
            >
                ×
            </button>
        </span>
        <input
            :id="inputId"
            v-model="draft"
            class="min-w-32 flex-1 bg-transparent outline-none"
            :list="listId"
            :placeholder="model.length ? '' : t('clients.form.tagsPlaceholder')"
            @keydown="onKeydown"
            @blur="add"
        />
        <datalist :id="listId">
            <option v-for="name in suggestions" :key="name" :value="name" />
        </datalist>
    </div>
</template>
