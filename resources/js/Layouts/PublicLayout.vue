<script setup>
import { Link } from '@inertiajs/vue3';
import AppLogo from '@/Components/AppLogo.vue';
</script>

<template>
    <!-- Pages anyone can open without signing in: the landing/sign-in page and the visitor report. -->
    <div class="min-h-screen bg-background text-on-surface flex flex-col">
        <header class="sticky top-0 z-30 bg-surface-container-lowest/90 backdrop-blur border-b border-outline-variant/50">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between gap-3">
                <Link href="/login" class="flex items-center gap-2.5 min-w-0">
                    <AppLogo class="h-11 w-11 shrink-0" />
                    <span class="flex flex-col leading-tight min-w-0">
                        <span class="font-label-sm text-[0.625rem] uppercase tracking-wider text-secondary font-bold truncate">Cebu South Medical Center</span>
                        <span class="font-headline-sm text-[0.8125rem] leading-tight sm:text-title-md text-primary font-bold">CSMC Incident/Accident Reporting System</span>
                    </span>
                </Link>
                <nav class="flex items-center gap-2 shrink-0">
                    <slot name="actions" />
                </nav>
            </div>
        </header>

        <main class="flex-1">
            <slot />
        </main>

        <footer class="border-t border-outline-variant/50 bg-surface-container-lowest">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 py-6 flex flex-col sm:flex-row gap-2 justify-between font-body-sm text-body-sm text-outline">
                <span>Cebu South Medical Center · Department of Health</span>
                <span>Reports are handled confidentially by the Patient Safety/CQI Office.</span>
            </div>
        </footer>

        <slot name="overlay" />
    </div>
</template>
