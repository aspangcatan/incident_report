<script setup>
import AppLogo from '@/Components/AppLogo.vue';
</script>

<template>
    <div class="min-h-screen bg-background flex flex-col items-center justify-center px-space-md py-space-xl">
        <div class="flex items-center gap-space-sm mb-space-lg">
            <AppLogo class="h-10 w-10" />
            <div class="flex flex-col">
                <span class="font-label-sm text-label-sm uppercase tracking-wider text-primary font-bold">
                    Philippine National Health System
                </span>
                <span class="font-title-md text-title-md text-on-surface font-semibold tracking-tight">
                    HOPSS e-Incident
                </span>
            </div>
        </div>

        <div class="w-full max-w-md bg-surface-container-lowest rounded-xl shadow-sm p-space-lg">
            <slot />
        </div>

        <p class="mt-space-lg font-body-sm text-body-sm text-outline text-center max-w-md">
            Electronic Incident Report Management System. Access is restricted to authorized hospital personnel.
        </p>
    </div>
</template>
