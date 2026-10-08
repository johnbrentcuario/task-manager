<script setup lang="ts">
import TaskForm from '@/components/admin/TaskForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

const props = defineProps<{
    task: {
        id: number;
        title: string;
        description: string | null;
        priority: 'low' | 'medium' | 'high';
        status: string;
        due_date: string;
        assigned_to: number;
    };
    assignees: { id: number; name: string; is_active: boolean }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tasks', href: '/admin/tasks' },
    { title: 'Edit', href: `/admin/tasks/${props.task.id}/edit` },
];
</script>

<template>
    <Head title="Edit task" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <h1 class="text-xl font-semibold">Edit task</h1>
            <TaskForm mode="edit" :task="task" :assignees="assignees" />
        </div>
    </AppLayout>
</template>