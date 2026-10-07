<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';

interface UserData {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'user';
}

const props = defineProps<{
    mode: 'create' | 'edit';
    user?: UserData;
    isSelf?: boolean;
}>();

const inputClass = 'w-full rounded-lg border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900';

const form = useForm({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    password: '',
    role: props.user?.role ?? 'user',
});

function submit() {
    if (props.mode === 'create') {
        form.post('/admin/users');
        return;
    }

    form.put(`/admin/users/${props.user!.id}`, {
        onSuccess: () => form.reset('password'),
    });
}
</script>

<template>
    <form @submit.prevent="submit" class="grid max-w-xl gap-4 rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
        <div>
            <label class="mb-1 block text-sm font-medium" for="name">Name</label>
            <input id="name" v-model="form.name" type="text" required :class="inputClass" />
            <p v-if="form.errors.name" class="mt-1 text-sm text-red-500">{{ form.errors.name }}</p>
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium" for="email">Email</label>
            <input id="email" v-model="form.email" type="email" required :class="inputClass" />
            <p v-if="form.errors.email" class="mt-1 text-sm text-red-500">{{ form.errors.email }}</p>
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium" for="password">
                {{ mode === 'create' ? 'Password' : 'New password (leave blank to keep the current one)' }}
            </label>
            <input
                id="password"
                v-model="form.password"
                type="password"
                autocomplete="new-password"
                :required="mode === 'create'"
                :class="inputClass"
            />
            <p v-if="form.errors.password" class="mt-1 text-sm text-red-500">{{ form.errors.password }}</p>
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium" for="role">Role</label>
            <select id="role" v-model="form.role" :disabled="isSelf" :class="inputClass">
                <option value="user">User</option>
                <option value="admin">Administrator</option>
            </select>
            <p v-if="isSelf" class="mt-1 text-sm text-neutral-500">You can not change your own role.</p>
            <p v-if="form.errors.role" class="mt-1 text-sm text-red-500">{{ form.errors.role }}</p>
        </div>

        <div class="flex items-center gap-3">
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded-lg bg-neutral-900 px-4 py-2 font-medium text-white hover:bg-neutral-700 disabled:opacity-50 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
            >
                {{ mode === 'create' ? 'Create user' : 'Save changes' }}
            </button>
            <Link href="/admin/users" class="text-sm underline">Cancel</Link>
        </div>
    </form>
</template>