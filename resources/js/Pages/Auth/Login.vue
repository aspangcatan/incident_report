<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: { type: Boolean, default: false },
});

const form = useForm({
    username: '',
    password: '',
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
            Sign in with your hospital (tdh) username and password.
        </p>

        <form class="flex flex-col gap-space-md" @submit.prevent="submit">
            <div class="flex flex-col gap-1.5">
                <label for="username" class="font-label-md text-label-md text-on-surface font-semibold">
                    Username
                </label>
                <input
                    id="username"
                    v-model="form.username"
                    type="text"
                    autofocus
                    autocomplete="username"
                    class="w-full p-3 rounded-lg bg-surface-container-low text-on-surface font-body-md text-body-md focus:outline-none focus:bg-surface-container-lowest focus:ring-2 focus:ring-primary shadow-sm"
                />
                <span v-if="form.errors.username" class="font-body-sm text-body-sm text-error">{{ form.errors.username }}</span>
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
