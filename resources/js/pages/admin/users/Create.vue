<script setup lang="ts">
import UserForm from '@/components/admin/UserForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Users', href: '/admin/users' },
    { title: 'New user', href: '/admin/users/create' },
];
</script>

<template>
    <Head title="New user" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <h1 class="text-xl font-semibold">New user</h1>
            <UserForm mode="create" />
        </div>
    </AppLayout>
</template>