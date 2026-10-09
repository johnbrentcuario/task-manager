<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { deadlineInfo, toneClasses } from '@/lib/deadline';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

interface EventRow {
    id: number;
    type: string;
    note: string | null;
    user_name: string | null;
    created_at: string;
}

interface RequestRow {
    id: number;
    reason: string;
    status: string;
    admin_response: string | null;
    requested_by: string | null;
    resolved_by: string | null;
    created_at: string;
    resolved_at: string | null;
}

const props = defineProps<{
    task: {
        id: number;
        title: string;
        description: string | null;
        priority: 'low' | 'medium' | 'high';
        status: string;
        due_date: string;
        days_until_due: number;
        is_overdue: boolean;
        assignee: string | null;
        assigned_by: string | null;
        accepted_at: string | null;
        submitted_at: string | null;
        completed_at: string | null;
    };
    is_archived: boolean;
    latest_submission: { note: string | null; submitted_at: string; user_name: string | null } | null;
    events: EventRow[];
    modification_requests: RequestRow[];
    can: {
        review: boolean;
        edit: boolean;
        resolve_requests: boolean;
        comment: boolean;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tasks', href: '/admin/tasks' },
    { title: props.task.title, href: `/admin/tasks/${props.task.id}` },
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

const eventLabels: Record<string, string> = {
    created: 'Task created',
    edited: 'Task edited',
    reassigned: 'Reassigned',
    accepted: 'Accepted',
    submitted: 'Submitted for review',
    approved: 'Approved',
    changes_requested: 'Changes requested',
    modification_requested: 'Modification requested',
    modification_approved: 'Modification approved',
    modification_declined: 'Modification declined',
    commented: 'Comment',
    archived: 'Archived',
    restored: 'Restored',
};

const requestStatusLabels: Record<string, string> = {
    open: 'Waiting for your decision',
    approved: 'Approved',
    declined: 'Declined',
};

const inputClass = 'w-full rounded-lg border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900';
const primaryButton =
    'rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200';
const secondaryButton = 'rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium hover:bg-neutral-100 disabled:opacity-50 dark:border-neutral-700 dark:hover:bg-neutral-800';

const actionError = ref<string | null>(null);

function rememberTaskError(errors: Record<string, string>) {
    actionError.value = errors.task ?? null;
}

function rememberAnyError(errors: Record<string, string>) {
    actionError.value = errors.task ?? errors.response ?? 'That action could not be completed.';
}

const approveForm = useForm({ note: '' });
const changesForm = useForm({ feedback: '' });
const commentForm = useForm({ note: '' });
const responses = reactive<Record<number, string>>({});

function approve() {
    actionError.value = null;
    approveForm.post(`/admin/tasks/${props.task.id}/approve`, {
        preserveScroll: true,
        onSuccess: () => approveForm.reset(),
        onError: rememberTaskError,
    });
}

function requestChanges() {
    actionError.value = null;
    changesForm.post(`/admin/tasks/${props.task.id}/request-changes`, {
        preserveScroll: true,
        onSuccess: () => changesForm.reset(),
        onError: rememberTaskError,
    });
}

function addComment() {
    actionError.value = null;
    commentForm.post(`/admin/tasks/${props.task.id}/comments`, {
        preserveScroll: true,
        onSuccess: () => commentForm.reset(),
        onError: rememberTaskError,
    });
}

function resolveRequest(request: RequestRow, approveIt: boolean) {
    actionError.value = null;
    router.post(
        `/admin/modification-requests/${request.id}/${approveIt ? 'approve' : 'decline'}`,
        { response: responses[request.id] ?? '' },
        {
            preserveScroll: true,
            onSuccess: () => {
                delete responses[request.id];
            },
            onError: rememberAnyError,
        },
    );
}

function formatTime(value: string): string {
    return new Date(value).toLocaleString();
}
</script>

<template>
    <Head :title="task.title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <Link href="/admin/tasks" class="text-sm underline">&larr; Back to tasks</Link>
                <Link v-if="can.edit" :href="`/admin/tasks/${task.id}/edit`" :class="secondaryButton">Edit task</Link>
            </div>

            <p v-if="is_archived" class="rounded-lg bg-neutral-100 p-3 text-sm dark:bg-neutral-800">
                This task is archived, so it is read-only. Restore it from the task list to work on it again.
            </p>

            <p v-if="actionError" class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-200">
                {{ actionError }}
            </p>

            <!-- Details -->
            <section class="rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <h1 class="text-xl font-semibold">{{ task.title }}</h1>
                    <span class="rounded-full border border-neutral-300 px-3 py-1 text-sm dark:border-neutral-700">
                        {{ statusLabels[task.status] ?? task.status }}
                    </span>
                </div>

                <dl class="mt-4 grid gap-4 text-sm md:grid-cols-4">
                    <div>
                        <dt class="text-neutral-500">Assigned to</dt>
                        <dd class="mt-1">{{ task.assignee ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-neutral-500">Assigned by</dt>
                        <dd class="mt-1">{{ task.assigned_by ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-neutral-500">Priority</dt>
                        <dd class="mt-1">{{ priorityLabels[task.priority] }}</dd>
                    </div>
                    <div>
                        <dt class="text-neutral-500">Deadline</dt>
                        <dd class="mt-1">
                            {{ task.due_date }}
                            <span :class="toneClasses[deadlineInfo(task.days_until_due, task.status, task.due_date).tone]">
                                {{ deadlineInfo(task.days_until_due, task.status, task.due_date).label }}
                            </span>
                        </dd>
                    </div>
                </dl>

                <dl class="mt-4 grid gap-4 text-sm md:grid-cols-3">
                    <div>
                        <dt class="text-neutral-500">Accepted</dt>
                        <dd class="mt-1">{{ task.accepted_at ? formatTime(task.accepted_at) : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-neutral-500">Submitted</dt>
                        <dd class="mt-1">{{ task.submitted_at ? formatTime(task.submitted_at) : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-neutral-500">Completed</dt>
                        <dd class="mt-1">{{ task.completed_at ? formatTime(task.completed_at) : '—' }}</dd>
                    </div>
                </dl>

                <h2 class="mt-6 text-sm font-medium text-neutral-500">Instructions</h2>
                <p class="mt-1 whitespace-pre-line">{{ task.description || 'No instructions were added.' }}</p>
            </section>

            <!-- Latest submission -->
            <section v-if="latest_submission" class="rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                <h2 class="font-medium">Latest submission</h2>
                <p class="mt-1 text-xs text-neutral-500">
                    {{ latest_submission.user_name ?? 'The assignee' }} · {{ formatTime(latest_submission.submitted_at) }}
                </p>
                <p class="mt-3 whitespace-pre-line">{{ latest_submission.note || 'No notes were added with this submission.' }}</p>
            </section>

            <!-- Review -->
            <section v-if="can.review" class="grid gap-4 md:grid-cols-2">
                <form @submit.prevent="approve" class="rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                    <h2 class="font-medium">Approve</h2>
                    <p class="mt-1 text-sm text-neutral-500">Marks the task as completed. You can add a note.</p>
                    <textarea v-model="approveForm.note" rows="3" placeholder="Note (optional)" :class="[inputClass, 'mt-3']" />
                    <p v-if="approveForm.errors.note" class="mt-1 text-sm text-red-500">{{ approveForm.errors.note }}</p>
                    <button type="submit" :disabled="approveForm.processing" :class="[primaryButton, 'mt-3']">Approve task</button>
                </form>

                <form @submit.prevent="requestChanges" class="rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                    <h2 class="font-medium">Request changes</h2>
                    <p class="mt-1 text-sm text-neutral-500">Sends the task back to the user. Feedback is required.</p>
                    <textarea v-model="changesForm.feedback" rows="3" placeholder="What needs to change?" :class="[inputClass, 'mt-3']" />
                    <p v-if="changesForm.errors.feedback" class="mt-1 text-sm text-red-500">{{ changesForm.errors.feedback }}</p>
                    <button type="submit" :disabled="changesForm.processing" :class="[secondaryButton, 'mt-3']">Send back for changes</button>
                </form>
            </section>

            <!-- Modification requests -->
            <section v-if="modification_requests.length > 0" class="rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                <h2 class="font-medium">Modification requests</h2>

                <ul class="mt-3 divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                    <li v-for="request in modification_requests" :key="request.id" class="py-4 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-medium">
                                {{ requestStatusLabels[request.status] ?? request.status }}
                                <span class="font-normal text-neutral-500">· {{ request.requested_by ?? 'The assignee' }}</span>
                            </span>
                            <span class="text-xs text-neutral-500">{{ formatTime(request.created_at) }}</span>
                        </div>

                        <p class="mt-1 whitespace-pre-line">{{ request.reason }}</p>

                        <p v-if="request.admin_response" class="mt-2 rounded-lg bg-neutral-100 p-3 dark:bg-neutral-800">
                            <span class="font-medium">Reply{{ request.resolved_by ? ` from ${request.resolved_by}` : '' }}:</span>
                            {{ request.admin_response }}
                        </p>

                        <div v-if="request.status === 'open' && can.resolve_requests" class="mt-3">
                            <textarea
                                v-model="responses[request.id]"
                                rows="2"
                                placeholder="Your reply (required to decline)"
                                :class="inputClass"
                            />
                            <div class="mt-2 flex flex-wrap gap-2">
                                <button type="button" @click="resolveRequest(request, true)" :class="primaryButton">Approve</button>
                                <button type="button" @click="resolveRequest(request, false)" :class="secondaryButton">Decline</button>
                            </div>
                            <p class="mt-2 text-xs text-neutral-500">
                                Approving does not change the task by itself. Use "Edit task" to make the change.
                            </p>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Comments and history -->
            <section class="rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                <h2 class="font-medium">History and comments</h2>

                <template v-if="can.comment">
                    <form @submit.prevent="addComment" class="mt-3 flex flex-col gap-2 md:flex-row">
                        <input v-model="commentForm.note" type="text" placeholder="Add a comment" :class="inputClass" />
                        <button type="submit" :disabled="commentForm.processing" :class="primaryButton">Comment</button>
                    </form>
                    <p v-if="commentForm.errors.note" class="mt-1 text-sm text-red-500">{{ commentForm.errors.note }}</p>
                </template>

                <ul class="mt-4 divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                    <li v-for="event in events" :key="event.id" class="py-3 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-medium">
                                {{ eventLabels[event.type] ?? event.type }}
                                <span v-if="event.user_name" class="font-normal text-neutral-500">· {{ event.user_name }}</span>
                            </span>
                            <span class="text-xs text-neutral-500">{{ formatTime(event.created_at) }}</span>
                        </div>
                        <p v-if="event.note" class="mt-1 whitespace-pre-line text-neutral-700 dark:text-neutral-300">{{ event.note }}</p>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>