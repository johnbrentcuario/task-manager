<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

type Status = 'todo' | 'in_progress' | 'done';
type Priority = 'low' | 'medium' | 'high';

interface Task {
    id: number;
    title: string;
    description: string | null;
    status: Status;
    priority: Priority;
    due_date: string | null;
}

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

defineProps<{
    tasks: Paginated<Task>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Tasks',
        href: '/tasks',
    },
];

const statusLabels: Record<Status, string> = {
    todo: 'To do',
    in_progress: 'In progress',
    done: 'Done',
};

const priorityLabels: Record<Priority, string> = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
};

const inputClass = 'w-full rounded-lg border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900';

// ---- Create ----
const form = useForm({
    title: '',
    description: '',
    status: 'todo' as Status,
    priority: 'medium' as Priority,
    due_date: '',
});

function createTask() {
    form.post('/tasks', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

// ---- Edit ----
const editingId = ref<number | null>(null);

const editForm = useForm({
    title: '',
    description: '',
    status: 'todo' as Status,
    priority: 'medium' as Priority,
    due_date: '',
});

function startEdit(task: Task) {
    editForm.clearErrors();
    editForm.title = task.title;
    editForm.description = task.description ?? '';
    editForm.status = task.status;
    editForm.priority = task.priority;
    editForm.due_date = task.due_date ? task.due_date.slice(0, 10) : '';
    editingId.value = task.id;
}

function cancelEdit() {
    editingId.value = null;
    editForm.clearErrors();
}

function saveEdit(task: Task) {
    editForm.put(`/tasks/${task.id}`, {
        preserveScroll: true,
        onSuccess: () => cancelEdit(),
    });
}

// ---- Quick status change ----
function onStatusChange(task: Task, event: Event) {
    const status = (event.target as HTMLSelectElement).value as Status;

    router.put(
        `/tasks/${task.id}`,
        {
            title: task.title,
            description: task.description,
            status,
            priority: task.priority,
            due_date: task.due_date ? task.due_date.slice(0, 10) : null,
        },
        { preserveScroll: true },
    );
}

// ---- Delete ----
function deleteTask(task: Task) {
    if (!confirm(`Delete "${task.title}"?`)) {
        return;
    }

    router.delete(`/tasks/${task.id}`, { preserveScroll: true });
}

function formatDate(value: string | null): string {
    return value ? value.slice(0, 10) : 'No due date';
}
</script>

<template>
    <Head title="Tasks" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
            <!-- Create form -->
            <form
                @submit.prevent="createTask"
                class="grid gap-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border md:grid-cols-4"
            >
                <div class="md:col-span-4">
                    <input v-model="form.title" type="text" placeholder="Task title" :class="inputClass" />
                    <p v-if="form.errors.title" class="mt-1 text-sm text-red-500">{{ form.errors.title }}</p>
                </div>

                <div class="md:col-span-4">
                    <textarea v-model="form.description" rows="2" placeholder="Description (optional)" :class="inputClass" />
                    <p v-if="form.errors.description" class="mt-1 text-sm text-red-500">{{ form.errors.description }}</p>
                </div>

                <select v-model="form.status" :class="inputClass">
                    <option v-for="(label, value) in statusLabels" :key="value" :value="value">{{ label }}</option>
                </select>

                <select v-model="form.priority" :class="inputClass">
                    <option v-for="(label, value) in priorityLabels" :key="value" :value="value">{{ label }}</option>
                </select>

                <input v-model="form.due_date" type="date" :class="inputClass" />

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-lg bg-neutral-900 px-4 py-2 font-medium text-white hover:bg-neutral-700 disabled:opacity-50 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
                >
                    Add task
                </button>
            </form>

            <!-- Task list -->
            <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <p v-if="tasks.data.length === 0" class="p-6 text-center text-neutral-500">No tasks yet. Add your first one above.</p>

                <ul v-else class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                    <li v-for="task in tasks.data" :key="task.id" class="p-4">
                        <!-- Edit mode -->
                        <form v-if="editingId === task.id" @submit.prevent="saveEdit(task)" class="grid gap-3 md:grid-cols-4">
                            <div class="md:col-span-4">
                                <input v-model="editForm.title" type="text" placeholder="Task title" :class="inputClass" />
                                <p v-if="editForm.errors.title" class="mt-1 text-sm text-red-500">{{ editForm.errors.title }}</p>
                            </div>

                            <div class="md:col-span-4">
                                <textarea v-model="editForm.description" rows="2" placeholder="Description (optional)" :class="inputClass" />
                                <p v-if="editForm.errors.description" class="mt-1 text-sm text-red-500">{{ editForm.errors.description }}</p>
                            </div>

                            <div>
                                <select v-model="editForm.status" :class="inputClass">
                                    <option v-for="(label, value) in statusLabels" :key="value" :value="value">{{ label }}</option>
                                </select>
                                <p v-if="editForm.errors.status" class="mt-1 text-sm text-red-500">{{ editForm.errors.status }}</p>
                            </div>

                            <div>
                                <select v-model="editForm.priority" :class="inputClass">
                                    <option v-for="(label, value) in priorityLabels" :key="value" :value="value">{{ label }}</option>
                                </select>
                                <p v-if="editForm.errors.priority" class="mt-1 text-sm text-red-500">{{ editForm.errors.priority }}</p>
                            </div>

                            <div>
                                <input v-model="editForm.due_date" type="date" :class="inputClass" />
                                <p v-if="editForm.errors.due_date" class="mt-1 text-sm text-red-500">{{ editForm.errors.due_date }}</p>
                            </div>

                            <div class="flex items-center gap-2">
                                <button
                                    type="submit"
                                    :disabled="editForm.processing"
                                    class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
                                >
                                    Save
                                </button>
                                <button
                                    type="button"
                                    @click="cancelEdit"
                                    class="rounded-lg px-3 py-2 text-sm hover:bg-neutral-100 dark:hover:bg-neutral-800"
                                >
                                    Cancel
                                </button>
                            </div>
                        </form>

                        <!-- View mode -->
                        <div v-else class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div class="min-w-0">
                                <p class="font-medium" :class="{ 'line-through opacity-60': task.status === 'done' }">
                                    {{ task.title }}
                                </p>
                                <p v-if="task.description" class="text-sm text-neutral-500">{{ task.description }}</p>
                                <p class="mt-1 text-xs text-neutral-500">
                                    {{ priorityLabels[task.priority] }} priority · {{ formatDate(task.due_date) }}
                                </p>
                            </div>

                            <div class="flex items-center gap-2">
                                <select
                                    :value="task.status"
                                    @change="onStatusChange(task, $event)"
                                    class="rounded-lg border-neutral-300 text-sm dark:border-neutral-700 dark:bg-neutral-900"
                                >
                                    <option v-for="(label, value) in statusLabels" :key="value" :value="value">{{ label }}</option>
                                </select>

                                <button
                                    type="button"
                                    @click="startEdit(task)"
                                    class="rounded-lg px-3 py-2 text-sm hover:bg-neutral-100 dark:hover:bg-neutral-800"
                                >
                                    Edit
                                </button>

                                <button
                                    type="button"
                                    @click="deleteTask(task)"
                                    class="rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-950"
                                >
                                    Delete
                                </button>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- Pagination -->
            <div v-if="tasks.last_page > 1" class="flex items-center justify-between text-sm">
                <Link v-if="tasks.prev_page_url" :href="tasks.prev_page_url" class="underline">Previous</Link>
                <span v-else />
                <span class="text-neutral-500">Page {{ tasks.current_page }} of {{ tasks.last_page }}</span>
                <Link v-if="tasks.next_page_url" :href="tasks.next_page_url" class="underline">Next</Link>
                <span v-else />
            </div>
        </div>
    </AppLayout>
</template>