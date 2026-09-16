<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

// Placeholder counts until Incident Reporting (Phase 3) and Analytics (Phase 8)
// wire this dashboard to real database-driven data.
const lifecycleStages = [
    { label: 'Reported', icon: 'clipboard-user', count: 0 },
    { label: 'For Review', icon: 'hourglass-half', count: 0 },
    { label: 'Investigation', icon: 'magnifying-glass', count: 0 },
    { label: 'CAPA Action', icon: 'list-check', count: 0 },
    { label: 'Verification', icon: 'shield-halved', count: 0 },
    { label: 'Closed', icon: 'circle-check', count: 0 },
    { label: 'Learn & CQI', icon: 'chart-line', count: 0 },
];

const kpiCards = [
    { label: 'Total Incidents (YTD)', value: 0, icon: 'chart-line' },
    { label: 'Pending Review', value: 0, icon: 'hourglass-half' },
    { label: 'Active Investigations', value: 0, icon: 'magnifying-glass' },
    { label: 'Overdue CAPA', value: 0, icon: 'triangle-exclamation' },
    { label: 'Sentinel Incidents', value: 0, icon: 'circle-exclamation' },
    { label: 'Closed & Verified', value: 0, icon: 'square-check' },
];
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <section class="relative overflow-hidden rounded-xl bg-surface-container-lowest p-space-lg shadow-sm">
            <div class="flex flex-col max-w-3xl">
                <h1 class="font-headline-lg text-headline-lg text-on-surface tracking-tight">
                    From Paper Incident Reporting to Complete Digital Incident Management
                </h1>
                <p class="mt-1 font-body-md text-body-md text-on-surface-variant leading-relaxed">
                    Report, review, investigate, act, verify, close, and learn — all incident lifecycle stages
                    tracked in one system.
                </p>
            </div>
        </section>

        <section class="rounded-xl bg-surface-container-lowest p-space-md shadow-sm">
            <div class="flex items-center gap-2 mb-3 px-space-xs">
                <FontAwesomeIcon icon="sitemap" class="text-primary text-title-md" />
                <h2 class="font-title-lg text-title-lg text-on-surface">Incident Governance Pipeline</h2>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-2">
                <div
                    v-for="(stage, index) in lifecycleStages"
                    :key="stage.label"
                    class="flex flex-col items-start p-3 rounded-lg bg-surface-container-low"
                >
                    <div class="flex items-center justify-between w-full mb-1.5">
                        <span class="flex items-center justify-center w-7 h-7 rounded-md bg-surface-container-highest text-primary">
                            <FontAwesomeIcon :icon="stage.icon" class="text-body-sm" />
                        </span>
                        <span class="font-code-tabular text-title-sm font-bold text-on-surface">{{ stage.count }}</span>
                    </div>
                    <span class="font-label-sm text-label-sm uppercase tracking-wider text-outline">
                        Step {{ String(index + 1).padStart(2, '0') }}
                    </span>
                    <span class="font-title-sm text-title-sm text-on-surface font-semibold">{{ stage.label }}</span>
                </div>
            </div>
        </section>

        <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6 gap-space-md">
            <div
                v-for="card in kpiCards"
                :key="card.label"
                class="flex flex-col justify-between p-space-md rounded-xl bg-surface-container-lowest shadow-sm"
            >
                <div class="flex items-start justify-between">
                    <span class="font-label-sm text-body-sm uppercase tracking-wider text-outline">{{ card.label }}</span>
                    <span class="p-1.5 rounded-lg bg-surface-container text-primary">
                        <FontAwesomeIcon :icon="card.icon" class="text-title-md" />
                    </span>
                </div>
                <div class="my-2">
                    <div class="font-headline-xl text-headline-xl text-on-surface font-bold">{{ card.value }}</div>
                </div>
            </div>
        </section>

        <section class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col items-center text-center gap-2">
            <FontAwesomeIcon icon="circle-info" class="text-primary text-title-lg" />
            <p class="font-body-md text-body-md text-on-surface-variant max-w-xl">
                The incident reporting form, investigation workspace, and analytics are being built in the next
                phases. This dashboard will populate with real data as those modules ship.
            </p>
        </section>
    </AuthenticatedLayout>
</template>
