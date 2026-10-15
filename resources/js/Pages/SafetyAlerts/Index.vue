<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
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

const urgencyStripe = {
    information: 'border-l-blue-400',
    warning: 'border-l-amber-500',
    critical: 'border-l-error',
};
</script>

<template>
    <Head title="Safety Alerts" />

    <AuthenticatedLayout :refresh="['alerts']">
        <PageHeader
            title="Safety Alerts"
            description="Alerts from the Patient Safety/CQI Office. Open each one and acknowledge that you have read it."
            icon="bullhorn"
            eyebrow="Learning & safety"
        >
            <template v-if="canCreate" #actions>
                <Link
                    href="/safety-alerts/create"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-primary text-on-primary hover:bg-primary-container font-label-md text-label-md font-semibold shadow-sm transition-colors"
                >
                    <FontAwesomeIcon icon="plus" /> Issue Safety Alert
                </Link>
            </template>
        </PageHeader>

        <section v-if="!alerts.data.length" class="rounded-2xl bg-surface-container-lowest shadow-sm ring-1 ring-outline-variant/40">
            <EmptyState icon="bullhorn" title="No safety alerts yet" text="Alerts issued by the CQI Office will appear here." />
        </section>

        <div v-else class="flex flex-col gap-3">
            <Link
                v-for="alert in alerts.data"
                :key="alert.id"
                :href="`/safety-alerts/${alert.id}`"
                class="group rounded-xl bg-surface-container-lowest p-4 sm:p-5 shadow-sm ring-1 ring-outline-variant/40 border-l-4 flex items-center gap-4 hover:shadow-md transition-shadow"
                :class="urgencyStripe[alert.urgency.value]"
            >
                <div class="flex-1 min-w-0 flex flex-col gap-1.5">
                    <div class="flex flex-wrap items-center gap-2">
                        <span :class="urgencyClasses[alert.urgency.value]" class="px-2.5 py-0.5 rounded-full font-label-sm text-body-sm font-semibold">{{ alert.urgency.label }}</span>
                        <span v-if="alert.addressed_to_me && !alert.acknowledged" class="px-2.5 py-0.5 rounded-full bg-error text-on-error font-label-sm text-body-sm font-semibold">Not yet read</span>
                        <span v-else-if="alert.acknowledged" class="inline-flex items-center gap-1 font-label-md text-label-md text-secondary"><FontAwesomeIcon icon="circle-check" /> Read</span>
                    </div>
                    <span class="font-title-md text-title-md text-on-surface font-semibold">{{ alert.title }}</span>
                    <span class="font-body-sm text-body-sm text-outline">
                        {{ formatDate(alert.created_at) }} · To: {{ alert.audience }}
                        <template v-if="alert.acknowledgements_count !== null"> · {{ alert.acknowledgements_count }} acknowledged</template>
                    </span>
                </div>
                <FontAwesomeIcon icon="chevron-right" class="text-outline group-hover:text-primary transition-colors" />
            </Link>
        </div>

        <Pagination :paginator="alerts" />
    </AuthenticatedLayout>
</template>
