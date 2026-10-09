<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { deadlineInfo, toneClasses } from '@/lib/deadline';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';

interface AdminSummary {
    counts: Record<string, number>;
    review: {
        submitted: { id: number; title: string; assignee: string | null; submitted_at: string | null }[];
        requests: { id: number; task_id: number; task_title: string; requester: string | null; reason: string; created_at: string }[];
    };
}

interface MemberSummary {
    counts: Record<string, number>;
    upcoming: {
        id: number;
        title: string;
        status: string;
        priority: string;
        due_date: string;
        days_until_due: number;
    }[];
}

defineProps<{
    admin: AdminSummary | null;
    member: MemberSummary | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

const statusLabels: Record<string, string> = {
    pending: 'Pending',
    accepted: 'Accepted',
    submitted: 'Submitted',
    changes_requested: 'Changes requested',
    completed: 'Completed',
};

const adminCards = [
    { key: 'pending', label: 'Pending' },
    { key: 'accepted', label: 'Accepted' },
    { key: 'submitted', label: 'Submitted' },
    { key: 'changes_requested', label: 'Changes requested' },
    { key: 'completed', label: 'Completed' },
    { key: 'overdue', label: 'Overdue' },
];

const memberCards = [
    { key: 'active', label: 'Active tasks', query: '' },
    { key: 'pending', label: 'To accept', query: '?status=pending' },
    { key: 'changes_requested', label: 'Changes requested', query: '?status=changes_requested' },
    { key: 'overdue', label: 'Overdue', query: '?status=overdue' },
];

function formatTime(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '';
}

const cardClass = 'rounded-xl border border-sidebar-border/70 p-4 hover:bg-neutral-50 dark:border-sidebar-border dark:hover:bg-neutral-900';
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
            <!-- Administrator -->
            <template v-if="admin">
                <h1 class="text-xl font-semibold">Overview</h1>

                <div class="grid gap-4 md:grid-cols-3 lg:grid-cols-6">
                    <Link v-for="card in adminCards" :key="card.key" :href="`/admin/tasks?status=${card.key}`" :class="cardClass">
                        <p class="text-sm text-neutral-500">{{ card.label }}</p>
                        <p class="mt-1 text-2xl font-semibold">{{ admin.counts[card.key] ?? 0 }}</p>
                    </Link>
                </div>

                <section class="rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                    <h2 class="font-medium">Waiting for your review</h2>

                    <p v-if="admin.review.submitted.length === 0" class="mt-3 text-sm text-neutral-500">No submitted tasks are waiting.</p>
                    <ul v-else class="mt-3 divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                        <li v-for="task in admin.review.submitted" :key="task.id">
                            <Link :href="`/admin/tasks/${task.id}`" class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm hover:underline">
                                <span class="font-medium">{{ task.title }}</span>
                                <span class="text-neutral-500">{{ task.assignee ?? '—' }} · {{ formatTime(task.submitted_at) }}</span>
                            </Link>
                        </li>
                    </ul>
                </section>

                <section class="rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                    <h2 class="font-medium">Modification requests</h2>

                    <p v-if="admin.review.requests.length === 0" class="mt-3 text-sm text-neutral-500">No requests are waiting.</p>
                    <ul v-else class="mt-3 divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                        <li v-for="request in admin.review.requests" :key="request.id">
                            <Link :href="`/admin/tasks/${request.task_id}`" class="block py-3 text-sm hover:underline">
                                <span class="font-medium">{{ request.task_title }}</span>
                                <span class="text-neutral-500"> · {{ request.requester ?? 'The assignee' }} · {{ formatTime(request.created_at) }}</span>
                                <span class="mt-1 block text-neutral-700 dark:text-neutral-300">{{ request.reason }}</span>
                            </Link>
                        </li>
                    </ul>
                </section>
            </template>

            <!-- Regular user -->
            <template v-else-if="member">
                <h1 class="text-xl font-semibold">My overview</h1>

                <div class="grid gap-4 md:grid-cols-4">
                    <Link v-for="card in memberCards" :key="card.key" :href="`/tasks${card.query}`" :class="cardClass">
                        <p class="text-sm text-neutral-500">{{ card.label }}</p>
                        <p class="mt-1 text-2xl font-semibold">{{ member.counts[card.key] ?? 0 }}</p>
                    </Link>
                </div>

                <section class="rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                    <h2 class="font-medium">Next deadlines</h2>

                    <p v-if="member.upcoming.length === 0" class="mt-3 text-sm text-neutral-500">You have no active tasks.</p>
                    <ul v-else class="mt-3 divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                        <li v-for="task in member.upcoming" :key="task.id">
                            <Link :href="`/tasks/${task.id}`" class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm hover:underline">
                                <span class="font-medium">{{ task.title }}</span>
                                <span class="flex items-center gap-2">
                                    <span :class="toneClasses[deadlineInfo(task.days_until_due, task.status, task.due_date).tone]">
                                        {{ deadlineInfo(task.days_until_due, task.status, task.due_date).label }}
                                    </span>
                                    <span class="rounded-full border border-neutral-300 px-2 py-0.5 text-xs dark:border-neutral-700">
                                        {{ statusLabels[task.status] ?? task.status }}
                                    </span>
                                </span>
                            </Link>
                        </li>
                    </ul>
                </section>
            </template>
        </div>
    </AppLayout>
</template>