<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: { type: Boolean, default: false },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Log in" />

    <GuestLayout>
        <h1 class="font-headline-sm text-headline-sm text-on-surface mb-1">Sign in</h1>
        <p class="font-body-sm text-body-sm text-outline mb-space-lg">
            Use your hospital-issued account to access the incident management system.
        </p>

        <form class="flex flex-col gap-space-md" @submit.prevent="submit">
            <div class="flex flex-col gap-1.5">
                <label for="email" class="font-label-md text-label-md text-on-surface font-semibold">
                    Email address
                </label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autofocus
                    autocomplete="username"
                    class="w-full p-3 rounded-lg bg-surface-container-low text-on-surface font-body-md text-body-md focus:outline-none focus:bg-surface-container-lowest focus:ring-2 focus:ring-primary shadow-sm"
                />
                <span v-if="form.errors.email" class="font-body-sm text-body-sm text-error">{{ form.errors.email }}</span>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="password" class="font-label-md text-label-md text-on-surface font-semibold">
                    Password
                </label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    class="w-full p-3 rounded-lg bg-surface-container-low text-on-surface font-body-md text-body-md focus:outline-none focus:bg-surface-container-lowest focus:ring-2 focus:ring-primary shadow-sm"
                />
                <span v-if="form.errors.password" class="font-body-sm text-body-sm text-error">{{ form.errors.password }}</span>
            </div>

            <label class="flex items-center gap-2 cursor-pointer">
                <input v-model="form.remember" type="checkbox" class="w-4 h-4 rounded accent-primary" />
                <span class="font-body-sm text-body-sm text-on-surface-variant">Remember me</span>
            </label>

            <button
                type="submit"
                :disabled="form.processing"
                class="mt-1 w-full py-2.5 rounded-lg bg-primary text-on-primary hover:bg-primary-container font-label-md text-label-md font-semibold transition-colors shadow-sm disabled:opacity-60"
            >
                Sign in
            </button>
        </form>
    </GuestLayout>
</template>
