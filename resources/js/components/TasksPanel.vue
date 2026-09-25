<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

import TaskForm from '@/components/TaskForm.vue';
import TaskList from '@/components/TaskList.vue';
import { todayIn } from '@/lib/calendar';
import http from '@/lib/http';
import { followUpDate, TASK_VIEWS, viewParams } from '@/lib/tasks';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

/**
 * A task list with its form (docs/spec/02): all of the practice's tasks, one
 * client's, or — compact — one consultation's follow-ups. Open, overdue,
 * today's and done tasks are tabs; ticking a task marks it done.
 */
const props = defineProps({
    /** Only this client's tasks; new ones are theirs. { id, full_name } */
    client: { type: Object, default: null },
    /** Only this consultation's follow-ups; new ones follow it up. { id, title } */
    consultation: { type: Object, default: null },
    /** The open tab (v-model:view). */
    view: { type: String, default: 'open' },
});
const emit = defineEmits(['changed', 'update:view']);

const { t } = useI18n();
const auth = useAuthStore();
const toast = useToastStore();

const compact = computed(() => props.consultation !== null);
const current = ref(TASK_VIEWS.includes(props.view) ? props.view : 'open');
watch(
    () => props.view,
    (view) => {
        if (TASK_VIEWS.includes(view)) current.value = view;
    },
);

const tasks = ref([]);
const meta = ref(null);
const counts = ref(null);
const loading = ref(true);
const busyId = ref(null);

// The form: a new task (keyed to start fresh after each save) or the one being edited.
const editing = ref(null);
const formKey = ref(0);
const formOpen = ref(!compact.value);
const formCard = ref(null);

const params = computed(() => ({
    ...(compact.value ? { status: 'all' } : viewParams(current.value)),
    ...(props.client && !compact.value ? { client_id: props.client.id } : {}),
    ...(compact.value ? { consultation_id: props.consultation.id, per_page: 50 } : {}),
}));

async function load(page = 1) {
    loading.value = true;
    try {
        const { data } = await http.get('/tasks', { params: { ...params.value, page } });
        tasks.value = page === 1 ? data.data : [...tasks.value, ...data.data];
        meta.value = data.meta;
        counts.value = data.counts;
    } finally {
        loading.value = false;
    }
}

onMounted(() => load());
watch(current, () => load());

function setView(view) {
    current.value = view;
    emit('update:view', view);
}

function resetForm() {
    editing.value = null;
    formKey.value++;
    if (compact.value) formOpen.value = false;
}

/** Edit a task, or (without one) start a new follow-up. Below the list on a narrow screen, so bring it into view. */
async function openForm(task = null) {
    editing.value = task;
    formKey.value++;
    formOpen.value = true;

    await nextTick();
    if (window.matchMedia?.('(max-width: 1023px)').matches) {
        formCard.value?.$el?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

async function saved() {
    resetForm();
    await load();
    emit('changed');
}

async function toggle(task) {
    busyId.value = task.id;
    const done = task.status !== 'done';

    try {
        await http.patch(`/tasks/${task.id}`, { status: done ? 'done' : 'open' });
        toast.success(done ? t('tasks.done') : t('tasks.reopened'));
        await load();
        emit('changed');
    } catch {
        toast.error(t('errors.generic'));
        await load();
    } finally {
        busyId.value = null;
    }
}

async function remove(task) {
    if (!window.confirm(t('tasks.confirmDelete'))) return;

    await http.delete(`/tasks/${task.id}`);
    toast.success(t('tasks.deleted'));
    if (editing.value?.id === task.id) resetForm();
    await load();
    emit('changed');
}

// A follow-up starts with a title and a deadline a week out.
const followUpPreset = computed(() => ({
    title: props.client ? t('tasks.followUpTitle', { name: props.client.full_name }) : '',
    due_date: followUpDate(todayIn(auth.user?.timezone ?? 'UTC')),
}));

const emptyText = computed(() => {
    if (props.client && current.value === 'open') return t('tasks.emptyClient');

    return t(`tasks.empty.${current.value}`);
});
</script>

<template>
    <!-- One consultation's follow-ups -->
    <div v-if="compact" class="space-y-3">
        <section class="card">
            <div class="card-head">
                <h2>{{ t('tasks.followUps') }}</h2>
                <button v-if="!formOpen" type="button" class="btn btn-sm right" @click="openForm()">
                    + {{ t('tasks.addFollowUp') }}
                </button>
            </div>
            <div class="card-body">
                <TaskList
                    v-if="tasks.length"
                    :tasks="tasks"
                    :show-client="false"
                    :show-consultation="false"
                    :busy-id="busyId"
                    @toggle="toggle"
                    @edit="openForm"
                    @remove="remove"
                />
                <p v-else-if="!loading" class="text-sm text-ink-3">{{ t('tasks.followUpsHint') }}</p>
            </div>
        </section>

        <TaskForm
            v-if="formOpen"
            ref="formCard"
            :key="formKey"
            :task="editing"
            :client="client"
            :consultation="editing ? null : consultation"
            :preset="followUpPreset"
            class="scroll-mt-20"
            closable
            @saved="saved"
            @cancel="resetForm"
        />
    </div>

    <!-- All tasks, or one client's -->
    <div v-else class="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_380px]">
        <section class="card">
            <div class="card-head flex-wrap">
                <div class="seg" role="group" :aria-label="t('tasks.title')">
                    <button
                        v-for="view in TASK_VIEWS"
                        :key="view"
                        type="button"
                        :aria-pressed="current === view"
                        @click="setView(view)"
                    >
                        {{ t(`tasks.views.${view}`) }}
                        <span
                            v-if="counts && view !== 'done' && counts[view]"
                            class="ml-1 font-mono text-[11px]"
                            :class="current === view ? 'opacity-80' : view === 'overdue' ? 'text-danger' : 'text-ink-3'"
                            >{{ counts[view] }}</span
                        >
                    </button>
                </div>
            </div>
            <div class="card-body" :aria-busy="loading">
                <TaskList
                    v-if="tasks.length"
                    :tasks="tasks"
                    :show-client="!client"
                    :busy-id="busyId"
                    @toggle="toggle"
                    @edit="openForm"
                    @remove="remove"
                />
                <p v-else-if="!loading" class="empty">{{ emptyText }}</p>
                <p v-else class="text-ink-3" role="status">{{ t('common.loading') }}</p>

                <button
                    v-if="meta && meta.current_page < meta.last_page"
                    type="button"
                    class="btn btn-sm mt-3"
                    :disabled="loading"
                    @click="load(meta.current_page + 1)"
                >
                    {{ t('common.more') }}
                </button>
            </div>
        </section>

        <TaskForm
            ref="formCard"
            :key="formKey"
            :task="editing"
            :client="client"
            class="scroll-mt-20 lg:sticky lg:top-4"
            @saved="saved"
            @cancel="resetForm"
        />
    </div>
</template>
