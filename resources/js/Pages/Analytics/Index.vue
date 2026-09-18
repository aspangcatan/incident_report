<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineProps({
    kpis: { type: Object, required: true },
    rootCauseDistribution: { type: Array, required: true },
    departmentSafety: { type: Array, required: true },
    hourlyVolume: { type: Array, required: true },
    recurringPatterns: { type: Array, required: true },
});
</script>

<template>
    <Head title="Analytics" />

    <AuthenticatedLayout>
        <!-- Placeholder: UI built out in later tasks of this phase. -->
    </AuthenticatedLayout>
</template>
