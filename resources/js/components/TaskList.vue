<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

import { todayIn } from '@/lib/calendar';
import { formatDate, formatTime } from '@/lib/format';
import { describeDue } from '@/lib/tasks';
import { useAuthStore } from '@/stores/auth';

/**
 * Tasks as rows with a tick box (docs/spec/02). The deadline reads relative to
 * the viewer's today; what a tick, an edit or a delete does is the parent's.
 */
const props = defineProps({
    tasks: { type: Array, required: true },
    showClient: { type: Boolean, default: true },
    showConsultation: { type: Boolean, default: true },
    editable: { type: Boolean, default: true },
    /** The task whose change is on its way, so it cannot be ticked twice. */
    busyId: { type: Number, default: null },
});
const emit = defineEmits(['toggle', 'edit', 'remove']);

const { t, locale } = useI18n();
const auth = useAuthStore();

const zone = computed(() => auth.user?.timezone ?? 'UTC');
const today = computed(() => todayIn(zone.value));

const TONES = { danger: 'text-danger font-medium', warn: 'text-warn font-medium' };

function due(task) {
    const described = describeDue(task, today.value, zone.value);
    if (!described) return null;

    const params = {
        ...described.params,
        date: formatDate(described.params.date, locale.value, 'medium'),
        time: formatTime(described.params.time, locale.value),
    };
    const text =
        described.params.count === undefined
            ? t(described.key, params)
            : t(described.key, params, described.params.count);

    return { text, tone: TONES[described.tone] ?? '' };
}

const rows = computed(() => props.tasks.map((task) => ({ task, deadline: due(task) })));

function followUpLabel(consultation) {
    return consultation.title ? t('tasks.followUpTo', { title: consultation.title }) : t('tasks.followUpToUntitled');
}
</script>

<template>
    <ul>
        <li
            v-for="{ task, deadline } in rows"
            :key="task.id"
            class="task-row"
            :class="{ 'is-done': task.status === 'done' }"
        >
            <input
                type="checkbox"
                :checked="task.status === 'done'"
                :disabled="busyId === task.id"
                :aria-label="
                    task.status === 'done'
                        ? t('tasks.reopen', { title: task.title })
                        : t('tasks.markDone', { title: task.title })
                "
                @change="emit('toggle', task)"
            />
            <div class="min-w-0 flex-1">
                <div class="t-label break-words">{{ task.title }}</div>
                <div class="t-desc flex flex-wrap gap-x-2 gap-y-0.5">
                    <span v-if="deadline" :class="deadline.tone">{{ deadline.text }}</span>
                    <RouterLink
                        v-if="showClient && task.client"
                        :to="{ name: 'clients.show', params: { id: task.client.id } }"
                        class="hover:underline"
                        >{{ task.client.full_name }}</RouterLink
                    >
                    <RouterLink
                        v-if="showConsultation && task.consultation"
                        :to="{ name: 'consultations.show', params: { id: task.consultation.id } }"
                        class="hover:underline"
                        >↩ {{ followUpLabel(task.consultation) }}</RouterLink
                    >
                    <span v-if="task.assigned_user && task.assigned_user.id !== auth.user?.id">{{
                        t('tasks.assignedTo', { name: task.assigned_user.name })
                    }}</span>
                </div>
                <p v-if="task.description" class="mt-1 line-clamp-2 text-xs whitespace-pre-line text-ink-2">
                    {{ task.description }}
                </p>
            </div>
            <span
                v-if="task.priority !== 'normal' && task.status !== 'done'"
                class="badge shrink-0"
                :class="task.priority === 'high' ? 'b-warn' : 'b-plain'"
                >{{ t(`tasks.priorities.${task.priority}`) }}</span
            >
            <span v-if="editable" class="flex shrink-0 gap-1">
                <button type="button" class="btn btn-ghost btn-sm" @click="emit('edit', task)">
                    {{ t('common.edit') }}
                </button>
                <button type="button" class="btn btn-ghost btn-sm" @click="emit('remove', task)">
                    {{ t('common.delete') }}
                </button>
            </span>
        </li>
    </ul>
</template>
