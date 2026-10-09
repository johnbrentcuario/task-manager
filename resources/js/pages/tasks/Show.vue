<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { deadlineInfo, toneClasses } from '@/lib/deadline';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface TaskEventRow {
    id: number;
    type: string;
    note: string | null;
    user_name: string | null;
    created_at: string;
}

interface ModificationRequestRow {
    id: number;
    reason: string;
    status: string;
    admin_response: string | null;
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
        assigned_by: string | null;
        accepted_at: string | null;
        submitted_at: string | null;
        completed_at: string | null;
    };
    events: TaskEventRow[];
    modification_requests: ModificationRequestRow[];
    has_open_request: boolean;
    can: {
        accept: boolean;
        submit: boolean;
        request_modification: boolean;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'My tasks', href: '/tasks' },
    { title: props.task.title, href: `/tasks/${props.task.id}` },
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
    open: 'Waiting for the administrator',
    approved: 'Approved',
    declined: 'Declined',
};

const inputClass = 'w-full rounded-lg border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900';
const primaryButton =
    'rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200';

const actionError = ref<string | null>(null);

function remember(errors: Record<string, string>) {
    actionError.value = errors.task ?? null;
}

const submitForm = useForm({ note: '' });
const modificationForm = useForm({ reason: '' });
const commentForm = useForm({ note: '' });

function accept() {
    actionError.value = null;
    router.post(`/tasks/${props.task.id}/accept`, {}, { preserveScroll: true, onError: remember });
}

function submitTask() {
    actionError.value = null;
    submitForm.post(`/tasks/${props.task.id}/submit`, {
        preserveScroll: true,
        onSuccess: () => submitForm.reset(),
        onError: remember,
    });
}

function requestModification() {
    actionError.value = null;
    modificationForm.post(`/tasks/${props.task.id}/modification-requests`, {
        preserveScroll: true,
        onSuccess: () => modificationForm.reset(),
        onError: remember,
    });
}

function addComment() {
    actionError.value = null;
    commentForm.post(`/tasks/${props.task.id}/comments`, {
        preserveScroll: true,
        onSuccess: () => commentForm.reset(),
        onError: remember,
    });
}

function formatTime(value: string): string {
    return new Date(value).toLocaleString();
}
</script>

<template>
    <Head :title="task.title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
            <Link href="/tasks" class="text-sm underline">&larr; Back to my tasks</Link>

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

                <dl class="mt-4 grid gap-4 text-sm md:grid-cols-3">
                    <div>
                        <dt class="text-neutral-500">Deadline</dt>
                        <dd class="mt-1">
                            {{ task.due_date }}
                            <span :class="toneClasses[deadlineInfo(task.days_until_due, task.status, task.due_date).tone]">
                                {{ deadlineInfo(task.days_until_due, task.status, task.due_date).label }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-neutral-500">Priority</dt>
                        <dd class="mt-1">{{ priorityLabels[task.priority] }}</dd>
                    </div>
                    <div>
                        <dt class="text-neutral-500">Assigned by</dt>
                        <dd class="mt-1">{{ task.assigned_by ?? '—' }}</dd>
                    </div>
                </dl>

                <h2 class="mt-6 text-sm font-medium text-neutral-500">Instructions</h2>
                <p class="mt-1 whitespace-pre-line">{{ task.description || 'No instructions were added.' }}</p>
            </section>

            <!-- Actions -->
            <section v-if="can.accept || can.submit || can.request_modification" class="grid gap-4 md:grid-cols-2">
                <div v-if="can.accept" class="rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border md:col-span-2">
                    <h2 class="font-medium">Accept this task</h2>
                    <p class="mt-1 text-sm text-neutral-500">Accepting tells the administrator you will work on it.</p>
                    <button type="button" @click="accept" :class="[primaryButton, 'mt-4']">Accept task</button>
                </div>

                <form
                    v-if="can.submit"
                    @submit.prevent="submitTask"
                    class="rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border"
                >
                    <h2 class="font-medium">Submit your work</h2>
                    <p class="mt-1 text-sm text-neutral-500">Add a note for the administrator, then submit it for review.</p>
                    <textarea v-model="submitForm.note" rows="4" placeholder="Submission notes (optional)" :class="[inputClass, 'mt-3']" />
                    <p v-if="submitForm.errors.note" class="mt-1 text-sm text-red-500">{{ submitForm.errors.note }}</p>
                    <button type="submit" :disabled="submitForm.processing" :class="[primaryButton, 'mt-3']">Submit for review</button>
                </form>

                <form
                    v-if="can.request_modification"
                    @submit.prevent="requestModification"
                    class="rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border"
                >
                    <h2 class="font-medium">Request a modification</h2>
                    <p class="mt-1 text-sm text-neutral-500">Ask the administrator to clarify or change this task. A reason is required.</p>
                    <textarea v-model="modificationForm.reason" rows="4" placeholder="Why do you need a change?" :class="[inputClass, 'mt-3']" />
                    <p v-if="modificationForm.errors.reason" class="mt-1 text-sm text-red-500">{{ modificationForm.errors.reason }}</p>
                    <button type="submit" :disabled="modificationForm.processing" :class="[primaryButton, 'mt-3']">Send request</button>
                </form>
            </section>

            <p
                v-if="has_open_request"
                class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200"
            >
                You have a modification request waiting for the administrator.
            </p>

            <!-- Modification requests -->
            <section v-if="modification_requests.length > 0" class="rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                <h2 class="font-medium">Modification requests</h2>
                <ul class="mt-3 divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                    <li v-for="request in modification_requests" :key="request.id" class="py-3 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-medium">{{ requestStatusLabels[request.status] ?? request.status }}</span>
                            <span class="text-xs text-neutral-500">{{ formatTime(request.created_at) }}</span>
                        </div>
                        <p class="mt-1 whitespace-pre-line">{{ request.reason }}</p>
                        <p v-if="request.admin_response" class="mt-2 rounded-lg bg-neutral-100 p-3 dark:bg-neutral-800">
                            <span class="font-medium">Administrator's reply:</span> {{ request.admin_response }}
                        </p>
                    </li>
                </ul>
            </section>

            <!-- Comments and history -->
            <section class="rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                <h2 class="font-medium">History and comments</h2>

                <form @submit.prevent="addComment" class="mt-3 flex flex-col gap-2 md:flex-row">
                    <input v-model="commentForm.note" type="text" placeholder="Add a comment" :class="inputClass" />
                    <button type="submit" :disabled="commentForm.processing" :class="[primaryButton, 'md:w-auto']">Comment</button>
                </form>
                <p v-if="commentForm.errors.note" class="mt-1 text-sm text-red-500">{{ commentForm.errors.note }}</p>

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