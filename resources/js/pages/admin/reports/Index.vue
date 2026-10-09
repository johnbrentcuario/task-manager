<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, watch } from 'vue';

interface UserRow {
    id: number;
    name: string;
    is_active: boolean;
    total: number;
    pending: number;
    accepted: number;
    submitted: number;
    changes_requested: number;
    completed: number;
    overdue: number;
    completion_rate: number | null;
}

interface OverdueRow {
    id: number;
    title: string;
    assignee: string | null;
    status: string;
    due_date: string;
    days_overdue: number;
}

const props = defineProps<{
    filters: { from: string | null; to: string | null; assignee: number | null };
    totals: Record<string, number>;
    users: UserRow[];
    overdue: OverdueRow[];
    assignees: { id: number; name: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Reports',
        href: '/admin/reports',
    },
];

const statusLabels: Record<string, string> = {
    pending: 'Pending',
    accepted: 'Accepted',
    submitted: 'Submitted',
    changes_requested: 'Changes requested',
    completed: 'Completed',
};

const cards = [
    { key: 'all', label: 'All tasks' },
    { key: 'pending', label: 'Pending' },
    { key: 'accepted', label: 'Accepted' },
    { key: 'submitted', label: 'Submitted' },
    { key: 'changes_requested', label: 'Changes requested' },
    { key: 'completed', label: 'Completed' },
    { key: 'overdue', label: 'Overdue' },
];

const inputClass = 'w-full rounded-lg border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900';

const filters = reactive({
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    assignee: props.filters.assignee ? String(props.filters.assignee) : '',
});

const hasActiveFilters = computed(() => filters.from !== '' || filters.to !== '' || filters.assignee !== '');

function query(): Record<string, string> {
    const params: Record<string, string> = {};

    if (filters.from !== '') {
        params.from = filters.from;
    }
    if (filters.to !== '') {
        params.to = filters.to;
    }
    if (filters.assignee !== '') {
        params.assignee = filters.assignee;
    }

    return params;
}

const exportUrl = computed(() => {
    const search = new URLSearchParams(query()).toString();

    return `/admin/reports/export${search ? `?${search}` : ''}`;
});

let timer: ReturnType<typeof setTimeout> | undefined;

watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get('/admin/reports', query(), { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});

onBeforeUnmount(() => clearTimeout(timer));

function clearFilters() {
    filters.from = '';
    filters.to = '';
    filters.assignee = '';
}
</script>

<template>
    <Head title="Reports" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-semibold">Reports</h1>
                <a
                    :href="exportUrl"
                    class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
                >
                    Export CSV
                </a>
            </div>

            <!-- Filters -->
            <div class="grid items-end gap-3 md:grid-cols-4">
                <div>
                    <label class="mb-1 block text-sm text-neutral-500" for="from">Deadline from</label>
                    <input id="from" v-model="filters.from" type="date" :class="inputClass" />
                </div>
                <div>
                    <label class="mb-1 block text-sm text-neutral-500" for="to">Deadline to</label>
                    <input id="to" v-model="filters.to" type="date" :class="inputClass" />
                </div>
                <div>
                    <label class="mb-1 block text-sm text-neutral-500" for="assignee">User</label>
                    <select id="assignee" v-model="filters.assignee" :class="inputClass">
                        <option value="">All users</option>
                        <option v-for="user in assignees" :key="user.id" :value="String(user.id)">{{ user.name }}</option>
                    </select>
                </div>
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    @click="clearFilters"
                    class="rounded-lg px-3 py-2 text-sm underline hover:bg-neutral-100 dark:hover:bg-neutral-800"
                >
                    Clear filters
                </button>
            </div>

            <p class="-mt-3 text-xs text-neutral-500">
                Filters use each task's deadline. The export includes exactly the tasks counted here. Archived tasks are left out.
            </p>

            <!-- Totals -->
            <div class="grid gap-4 md:grid-cols-4 lg:grid-cols-7">
                <div v-for="card in cards" :key="card.key" class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <p class="text-sm text-neutral-500">{{ card.label }}</p>
                    <p class="mt-1 text-2xl font-semibold">{{ totals[card.key] ?? 0 }}</p>
                </div>
            </div>

            <!-- Per user -->
            <section class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <h2 class="px-4 pt-4 font-medium">Progress by user</h2>

                <p v-if="users.length === 0" class="p-6 text-center text-neutral-500">There are no users yet.</p>

                <div v-else class="overflow-x-auto">
                    <table class="mt-2 w-full text-left text-sm">
                        <thead class="border-b border-sidebar-border/70 text-neutral-500 dark:border-sidebar-border">
                            <tr>
                                <th class="px-4 py-3 font-medium">User</th>
                                <th class="px-4 py-3 font-medium">Total</th>
                                <th v-for="(label, key) in statusLabels" :key="key" class="px-4 py-3 font-medium">{{ label }}</th>
                                <th class="px-4 py-3 font-medium">Overdue</th>
                                <th class="px-4 py-3 font-medium">Completion</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            <tr v-for="user in users" :key="user.id" :class="{ 'opacity-60': !user.is_active }">
                                <td class="px-4 py-3 font-medium">
                                    {{ user.name }}
                                    <span v-if="!user.is_active" class="text-xs font-normal text-neutral-500">(deactivated)</span>
                                </td>
                                <td class="px-4 py-3">{{ user.total }}</td>
                                <td class="px-4 py-3">{{ user.pending }}</td>
                                <td class="px-4 py-3">{{ user.accepted }}</td>
                                <td class="px-4 py-3">{{ user.submitted }}</td>
                                <td class="px-4 py-3">{{ user.changes_requested }}</td>
                                <td class="px-4 py-3">{{ user.completed }}</td>
                                <td class="px-4 py-3" :class="{ 'font-medium text-red-600': user.overdue > 0 }">{{ user.overdue }}</td>
                                <td class="px-4 py-3">{{ user.completion_rate === null ? '—' : `${user.completion_rate}%` }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Overdue -->
            <section class="rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                <h2 class="font-medium">Most overdue tasks</h2>

                <p v-if="overdue.length === 0" class="mt-3 text-sm text-neutral-500">Nothing is overdue.</p>
                <ul v-else class="mt-3 divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                    <li v-for="task in overdue" :key="task.id">
                        <Link :href="`/admin/tasks/${task.id}`" class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm hover:underline">
                            <span class="font-medium">{{ task.title }}</span>
                            <span class="flex items-center gap-2 text-neutral-500">
                                {{ task.assignee ?? '—' }} · {{ statusLabels[task.status] ?? task.status }} · {{ task.due_date }}
                                <span class="rounded bg-red-100 px-1.5 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950 dark:text-red-300">
                                    {{ task.days_overdue }} {{ task.days_overdue === 1 ? 'day' : 'days' }} late
                                </span>
                            </span>
                        </Link>
                    </li>
                </ul>
                <p v-if="overdue.length === 20" class="mt-3 text-xs text-neutral-500">Showing the 20 most overdue tasks. Export the CSV for the full list.</p>
            </section>
        </div>
    </AppLayout>
</template>