<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, watch } from 'vue';

interface TaskRow {
    id: number;
    title: string;
    priority: 'low' | 'medium' | 'high';
    status: string;
    due_date: string;
    is_overdue: boolean;
    assignee: string | null;
}

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

const props = defineProps<{
    tasks: Paginated<TaskRow>;
    filters: {
        search: string | null;
        status: string | null;
        priority: string | null;
        assignee: number | string | null;
    };
    counts: Record<string, number>;
    users: { id: number; name: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Tasks',
        href: '/admin/tasks',
    },
];

const statusLabels: Record<string, string> = {
    pending: 'Pending',
    accepted: 'Accepted',
    submitted: 'Submitted',
    changes_requested: 'Changes requested',
    completed: 'Completed',
};

const priorityLabels: Record<string, string> = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
};

const chips = [
    { key: '', label: 'All', count: 'all' },
    { key: 'pending', label: 'Pending', count: 'pending' },
    { key: 'accepted', label: 'Accepted', count: 'accepted' },
    { key: 'submitted', label: 'Submitted', count: 'submitted' },
    { key: 'changes_requested', label: 'Changes requested', count: 'changes_requested' },
    { key: 'completed', label: 'Completed', count: 'completed' },
    { key: 'overdue', label: 'Overdue', count: 'overdue' },
];

const inputClass = 'w-full rounded-lg border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900';

const filters = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    priority: props.filters.priority ?? '',
    assignee: props.filters.assignee ? String(props.filters.assignee) : '',
});

const hasActiveFilters = computed(() => filters.search.trim() !== '' || filters.status !== '' || filters.priority !== '' || filters.assignee !== '');

let filterTimer: ReturnType<typeof setTimeout> | undefined;

function applyFilters() {
    const params: Record<string, string> = {};

    if (filters.search.trim() !== '') {
        params.search = filters.search.trim();
    }
    if (filters.status !== '') {
        params.status = filters.status;
    }
    if (filters.priority !== '') {
        params.priority = filters.priority;
    }
    if (filters.assignee !== '') {
        params.assignee = filters.assignee;
    }

    router.get('/admin/tasks', params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

watch(filters, () => {
    clearTimeout(filterTimer);
    filterTimer = setTimeout(applyFilters, 300);
});

onBeforeUnmount(() => clearTimeout(filterTimer));

function clearFilters() {
    filters.search = '';
    filters.status = '';
    filters.priority = '';
    filters.assignee = '';
}

function deleteTask(task: TaskRow) {
    if (!confirm(`Delete "${task.title}"? Its history will be deleted too.`)) {
        return;
    }

    router.delete(`/admin/tasks/${task.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Tasks" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Tasks</h1>
                <Link
                    href="/admin/tasks/create"
                    class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
                >
                    New task
                </Link>
            </div>

            <!-- Status counts (click to filter) -->
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="chip in chips"
                    :key="chip.key"
                    type="button"
                    @click="filters.status = chip.key"
                    class="rounded-full border px-3 py-1 text-sm"
                    :class="
                        filters.status === chip.key
                            ? 'border-neutral-900 bg-neutral-900 text-white dark:border-white dark:bg-white dark:text-neutral-900'
                            : 'border-neutral-300 hover:bg-neutral-100 dark:border-neutral-700 dark:hover:bg-neutral-800'
                    "
                >
                    {{ chip.label }} · {{ counts[chip.count] ?? 0 }}
                </button>
            </div>

            <!-- Filters -->
            <div class="grid items-center gap-3 md:grid-cols-5">
                <input v-model="filters.search" type="search" placeholder="Search title or description" :class="[inputClass, 'md:col-span-2']" />

                <select v-model="filters.assignee" :class="inputClass">
                    <option value="">All users</option>
                    <option v-for="user in users" :key="user.id" :value="String(user.id)">{{ user.name }}</option>
                </select>

                <select v-model="filters.priority" :class="inputClass">
                    <option value="">All priorities</option>
                    <option v-for="(label, value) in priorityLabels" :key="value" :value="value">{{ label }}</option>
                </select>

                <button
                    v-if="hasActiveFilters"
                    type="button"
                    @click="clearFilters"
                    class="rounded-lg px-3 py-2 text-sm underline hover:bg-neutral-100 dark:hover:bg-neutral-800"
                >
                    Clear filters
                </button>
            </div>

            <p class="text-sm text-neutral-500">{{ tasks.total }} {{ tasks.total === 1 ? 'task' : 'tasks' }}</p>

            <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <p v-if="tasks.data.length === 0" class="p-6 text-center text-neutral-500">
                    {{ hasActiveFilters ? 'No tasks match your filters.' : 'No tasks yet. Create the first one.' }}
                </p>

                <table v-else class="w-full text-left text-sm">
                    <thead class="border-b border-sidebar-border/70 text-neutral-500 dark:border-sidebar-border">
                        <tr>
                            <th class="px-4 py-3 font-medium">Title</th>
                            <th class="px-4 py-3 font-medium">Assigned to</th>
                            <th class="px-4 py-3 font-medium">Priority</th>
                            <th class="px-4 py-3 font-medium">Deadline</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                        <tr v-for="task in tasks.data" :key="task.id">
                            <td class="px-4 py-3 font-medium">{{ task.title }}</td>
                            <td class="px-4 py-3">{{ task.assignee ?? '—' }}</td>
                            <td class="px-4 py-3">{{ priorityLabels[task.priority] }}</td>
                            <td class="px-4 py-3">
                                {{ task.due_date }}
                                <span v-if="task.is_overdue" class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950 dark:text-red-300">
                                    Overdue
                                </span>
                            </td>
                            <td class="px-4 py-3">{{ statusLabels[task.status] ?? task.status }}</td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-2">
                                    <Link :href="`/admin/tasks/${task.id}/edit`" class="rounded-lg px-3 py-1.5 hover:bg-neutral-100 dark:hover:bg-neutral-800">
                                        Edit
                                    </Link>
                                    <button
                                        type="button"
                                        @click="deleteTask(task)"
                                        class="rounded-lg px-3 py-1.5 text-red-600 hover:bg-red-50 dark:hover:bg-red-950"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

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