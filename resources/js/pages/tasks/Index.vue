<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { deadlineInfo, toneClasses } from '@/lib/deadline';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';

interface TaskRow {
    id: number;
    title: string;
    priority: 'low' | 'medium' | 'high';
    status: string;
    due_date: string;
    days_until_due: number;
    is_overdue: boolean;
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
    filters: { status: string | null };
    counts: Record<string, number>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'My tasks',
        href: '/tasks',
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
    low: 'Low priority',
    medium: 'Medium priority',
    high: 'High priority',
};

const chips = [
    { key: '', label: 'Active', count: 'active' },
    { key: 'pending', label: 'Pending', count: 'pending' },
    { key: 'accepted', label: 'Accepted', count: 'accepted' },
    { key: 'submitted', label: 'Submitted', count: 'submitted' },
    { key: 'changes_requested', label: 'Changes requested', count: 'changes_requested' },
    { key: 'overdue', label: 'Overdue', count: 'overdue' },
    { key: 'completed', label: 'History', count: 'completed' },
];

function selectChip(key: string) {
    router.get('/tasks', key ? { status: key } : {}, { preserveScroll: true, replace: true });
}

const emptyMessage = () => {
    switch (props.filters.status) {
        case 'completed':
            return 'No completed tasks yet.';
        case null:
        case '':
            return 'You have no active tasks.';
        default:
            return 'No tasks in this view.';
    }
};
</script>

<template>
    <Head title="My tasks" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <h1 class="text-xl font-semibold">My tasks</h1>

            <div class="flex flex-wrap gap-2">
                <button
                    v-for="chip in chips"
                    :key="chip.key"
                    type="button"
                    @click="selectChip(chip.key)"
                    class="rounded-full border px-3 py-1 text-sm"
                    :class="
                        (filters.status ?? '') === chip.key
                            ? 'border-neutral-900 bg-neutral-900 text-white dark:border-white dark:bg-white dark:text-neutral-900'
                            : 'border-neutral-300 hover:bg-neutral-100 dark:border-neutral-700 dark:hover:bg-neutral-800'
                    "
                >
                    {{ chip.label }} · {{ counts[chip.count] ?? 0 }}
                </button>
            </div>

            <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <p v-if="tasks.data.length === 0" class="p-6 text-center text-neutral-500">{{ emptyMessage() }}</p>

                <ul v-else class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                    <li v-for="task in tasks.data" :key="task.id">
                        <Link
                            :href="`/tasks/${task.id}`"
                            class="flex flex-col gap-2 p-4 hover:bg-neutral-50 md:flex-row md:items-center md:justify-between dark:hover:bg-neutral-900"
                        >
                            <div class="min-w-0">
                                <p class="font-medium">{{ task.title }}</p>
                                <p class="mt-1 text-xs text-neutral-500">{{ priorityLabels[task.priority] }}</p>
                            </div>

                            <div class="flex items-center gap-3 text-sm">
                                <span :class="toneClasses[deadlineInfo(task.days_until_due, task.status, task.due_date).tone]">
                                    {{ deadlineInfo(task.days_until_due, task.status, task.due_date).label }}
                                </span>
                                <span class="rounded-full border border-neutral-300 px-2 py-0.5 text-xs dark:border-neutral-700">
                                    {{ statusLabels[task.status] ?? task.status }}
                                </span>
                            </div>
                        </Link>
                    </li>
                </ul>
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