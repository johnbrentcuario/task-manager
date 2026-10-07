<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

interface UserRow {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'user';
    is_active: boolean;
    total_tasks_count: number;
    open_tasks_count: number;
}

defineProps<{
    users: UserRow[];
}>();

const page = usePage<SharedData>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Users',
        href: '/admin/users',
    },
];

function toggleActive(user: UserRow) {
    if (user.is_active && !confirm(`Deactivate ${user.name}? They will no longer be able to log in.`)) {
        return;
    }

    router.patch(`/admin/users/${user.id}/status`, { active: !user.is_active }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Users" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Users</h1>
                <Link
                    href="/admin/users/create"
                    class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
                >
                    New user
                </Link>
            </div>

            <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-sidebar-border/70 text-neutral-500 dark:border-sidebar-border">
                        <tr>
                            <th class="px-4 py-3 font-medium">Name</th>
                            <th class="px-4 py-3 font-medium">Email</th>
                            <th class="px-4 py-3 font-medium">Role</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Open / total tasks</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                        <tr v-for="user in users" :key="user.id" :class="{ 'opacity-60': !user.is_active }">
                            <td class="px-4 py-3 font-medium">
                                {{ user.name }}
                                <span v-if="user.id === page.props.auth.user.id" class="text-xs text-neutral-500">(you)</span>
                            </td>
                            <td class="px-4 py-3">{{ user.email }}</td>
                            <td class="px-4 py-3">{{ user.role === 'admin' ? 'Administrator' : 'User' }}</td>
                            <td class="px-4 py-3">{{ user.is_active ? 'Active' : 'Deactivated' }}</td>
                            <td class="px-4 py-3">{{ user.open_tasks_count }} / {{ user.total_tasks_count }}</td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-2">
                                    <Link :href="`/admin/users/${user.id}/edit`" class="rounded-lg px-3 py-1.5 hover:bg-neutral-100 dark:hover:bg-neutral-800">
                                        Edit
                                    </Link>
                                    <button
                                        v-if="user.id !== page.props.auth.user.id"
                                        type="button"
                                        @click="toggleActive(user)"
                                        class="rounded-lg px-3 py-1.5 hover:bg-neutral-100 dark:hover:bg-neutral-800"
                                        :class="user.is_active ? 'text-red-600' : ''"
                                    >
                                        {{ user.is_active ? 'Deactivate' : 'Reactivate' }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>