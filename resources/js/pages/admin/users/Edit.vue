<script setup lang="ts">
import UserForm from '@/components/admin/UserForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';

const props = defineProps<{
    user: {
        id: number;
        name: string;
        email: string;
        role: 'admin' | 'user';
    };
}>();

const page = usePage<SharedData>();

const isSelf = props.user.id === page.props.auth.user.id;

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Users', href: '/admin/users' },
    { title: 'Edit', href: `/admin/users/${props.user.id}/edit` },
];
</script>

<template>
    <Head title="Edit user" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <h1 class="text-xl font-semibold">Edit {{ user.name }}</h1>
            <UserForm mode="edit" :user="user" :is-self="isSelf" />
        </div>
    </AppLayout>
</template>