<script setup lang="ts">
import TaskForm from '@/components/admin/TaskForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

defineProps<{
    assignees: { id: number; name: string; is_active: boolean }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tasks', href: '/admin/tasks' },
    { title: 'New task', href: '/admin/tasks/create' },
];
</script>

<template>
    <Head title="New task" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <h1 class="text-xl font-semibold">New task</h1>
            <TaskForm mode="create" :assignees="assignees" />
        </div>
    </AppLayout>
</template>