<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';

interface Assignee {
    id: number;
    name: string;
    is_active: boolean;
}

interface TaskData {
    id: number;
    title: string;
    description: string | null;
    priority: 'low' | 'medium' | 'high';
    status: string;
    due_date: string;
    assigned_to: number;
}

const props = defineProps<{
    mode: 'create' | 'edit';
    assignees: Assignee[];
    task?: TaskData;
}>();

const inputClass = 'w-full rounded-lg border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900';

const form = useForm({
    title: props.task?.title ?? '',
    description: props.task?.description ?? '',
    priority: props.task?.priority ?? 'medium',
    due_date: props.task?.due_date ?? '',
    assigned_to: (props.task?.assigned_to ?? '') as number | '',
});

const isCompleted = props.task?.status === 'completed';

function submit() {
    if (props.mode === 'create') {
        form.post('/admin/tasks');
        return;
    }

    form.put(`/admin/tasks/${props.task!.id}`);
}
</script>

<template>
    <form @submit.prevent="submit" class="grid max-w-2xl gap-4 rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
        <p v-if="isCompleted" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200">
            This task is completed and can no longer be edited.
        </p>
        <p v-if="form.errors.task" class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-200">
            {{ form.errors.task }}
        </p>

        <div>
            <label class="mb-1 block text-sm font-medium" for="title">Title</label>
            <input id="title" v-model="form.title" type="text" required :class="inputClass" />
            <p v-if="form.errors.title" class="mt-1 text-sm text-red-500">{{ form.errors.title }}</p>
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium" for="description">Description and instructions</label>
            <textarea id="description" v-model="form.description" rows="5" :class="inputClass" />
            <p v-if="form.errors.description" class="mt-1 text-sm text-red-500">{{ form.errors.description }}</p>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <div>
                <label class="mb-1 block text-sm font-medium" for="priority">Priority</label>
                <select id="priority" v-model="form.priority" :class="inputClass">
                    <option value="low">Low</option>
                    <option value="medium">Medium</option>
                    <option value="high">High</option>
                </select>
                <p v-if="form.errors.priority" class="mt-1 text-sm text-red-500">{{ form.errors.priority }}</p>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium" for="due_date">Deadline</label>
                <input id="due_date" v-model="form.due_date" type="date" required :class="inputClass" />
                <p v-if="form.errors.due_date" class="mt-1 text-sm text-red-500">{{ form.errors.due_date }}</p>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium" for="assigned_to">Assign to</label>
                <select id="assigned_to" v-model="form.assigned_to" required :class="inputClass">
                    <option value="" disabled>Choose a user</option>
                    <option v-for="assignee in assignees" :key="assignee.id" :value="assignee.id">
                        {{ assignee.name }}{{ assignee.is_active ? '' : ' (deactivated)' }}
                    </option>
                </select>
                <p v-if="form.errors.assigned_to" class="mt-1 text-sm text-red-500">{{ form.errors.assigned_to }}</p>
            </div>
        </div>

        <p v-if="mode === 'edit'" class="text-sm text-neutral-500">Changing the assignee sends the task back to Pending for the new user.</p>

        <div class="flex items-center gap-3">
            <button
                type="submit"
                :disabled="form.processing || isCompleted"
                class="rounded-lg bg-neutral-900 px-4 py-2 font-medium text-white hover:bg-neutral-700 disabled:opacity-50 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
            >
                {{ mode === 'create' ? 'Create task' : 'Save changes' }}
            </button>
            <Link href="/admin/tasks" class="text-sm underline">Cancel</Link>
        </div>
    </form>
</template>