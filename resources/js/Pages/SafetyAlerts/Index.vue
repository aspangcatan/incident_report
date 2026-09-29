<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { formatDate } from '@/Utils/formatDate';

defineProps({
    alerts: { type: Object, required: true },
    canCreate: { type: Boolean, default: false },
});

const urgencyClasses = {
    information: 'bg-blue-100 text-blue-900',
    warning: 'bg-amber-100 text-amber-900',
    critical: 'bg-error-container text-on-error-container',
};
</script>

<template>
    <Head title="Safety Alerts" />

    <AuthenticatedLayout>
        <div class="flex flex-wrap items-center justify-between gap-space-sm">
            <div class="flex flex-col gap-1">
                <h1 class="font-headline-sm text-headline-sm text-on-surface">Safety Alerts</h1>
                <p class="font-body-sm text-body-sm text-outline">Alerts from the Patient Safety/CQI Office. Open each one and acknowledge that you have read it.</p>
            </div>
            <Link
                v-if="canCreate"
                href="/safety-alerts/create"
                class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold"
            >
                Issue Safety Alert
            </Link>
        </div>

        <div v-if="!alerts.data.length" class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm font-body-md text-body-md text-outline text-center">
            No safety alerts yet.
        </div>

        <Link
            v-for="alert in alerts.data"
            :key="alert.id"
            :href="`/safety-alerts/${alert.id}`"
            class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-1 hover:bg-surface-container-low"
        >
            <div class="flex flex-wrap items-center gap-2">
                <span :class="urgencyClasses[alert.urgency.value]" class="px-2.5 py-0.5 rounded-full font-label-sm text-body-sm font-semibold">{{ alert.urgency.label }}</span>
                <span class="font-title-sm text-title-sm text-on-surface font-semibold">{{ alert.title }}</span>
                <span v-if="alert.addressed_to_me && !alert.acknowledged" class="px-2.5 py-0.5 rounded-full bg-error text-on-error font-label-sm text-body-sm font-semibold">Not yet read</span>
                <span v-else-if="alert.acknowledged" class="font-body-sm text-body-sm text-outline">Read</span>
            </div>
            <span class="font-body-sm text-body-sm text-outline">
                {{ formatDate(alert.created_at) }} · To: {{ alert.audience }}
                <template v-if="alert.acknowledgements_count !== null"> · {{ alert.acknowledgements_count }} acknowledged</template>
            </span>
        </Link>

        <Pagination :paginator="alerts" />
    </AuthenticatedLayout>
</template>
