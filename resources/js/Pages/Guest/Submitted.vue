<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    reference: { type: String, required: true },
});
</script>

<template>
    <Head title="Report Submitted" />

    <GuestLayout>
        <h1 class="font-headline-sm text-headline-sm text-on-surface mb-1">Thank you</h1>
        <p class="font-body-sm text-body-sm text-outline mb-space-lg">
            Your report has been received by the hospital's Quality & Patient Safety team.
        </p>

        <div class="flex flex-col items-center gap-1 p-space-lg rounded-xl bg-surface-container-low text-center">
            <span class="font-label-sm text-label-sm uppercase tracking-wider text-outline">Your reference number</span>
            <span class="font-code-tabular text-headline-sm text-primary font-bold tracking-wide">{{ reference }}</span>
        </div>

        <p class="mt-space-md font-body-sm text-body-sm text-outline">
            Please keep this number if you need to contact the hospital about your report.
        </p>

        <p class="mt-space-lg text-center">
            <Link href="/report" class="font-label-md text-label-md font-semibold text-primary">Submit another report</Link>
        </p>
    </GuestLayout>
</template>
