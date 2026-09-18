<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import KpiStatTile from '@/Components/Analytics/KpiStatTile.vue';
import StackedBarChart from '@/Components/Analytics/StackedBarChart.vue';

const props = defineProps({
    kpis: { type: Object, required: true },
    rootCauseDistribution: { type: Array, required: true },
    departmentSafety: { type: Array, required: true },
    hourlyVolume: { type: Array, required: true },
    recurringPatterns: { type: Array, required: true },
});

function formatHours(hours) {
    return hours === null ? 'No data yet' : `${hours} hrs`;
}

function formatDays(days) {
    return days === null ? 'No data yet' : `${days} days`;
}

function formatPercent(rate) {
    return rate === null ? 'No data yet' : `${rate}%`;
}
</script>

<template>
    <AuthenticatedLayout>
        <div class="flex flex-col gap-space-lg">
            <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm">
                <h1 class="font-headline-md text-headline-md text-primary tracking-tight">Executive Incident Intelligence &amp; Organizational Learning</h1>
                <p class="font-body-sm text-body-sm text-outline mt-1">Trailing 90-day window, scoped to the departments you have access to.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                <KpiStatTile label="Mean time to review" :value="formatHours(kpis.meanHoursToReview)" />
                <KpiStatTile label="Mean time to investigate" :value="formatDays(kpis.meanDaysToInvestigate)" />
                <KpiStatTile
                    label="CAPA adoption rate"
                    :value="formatPercent(kpis.capaAdoption.rate)"
                    :delta="kpis.capaAdoption.total ? `${kpis.capaAdoption.verified}/${kpis.capaAdoption.total} verified` : null"
                />
                <KpiStatTile
                    label="Sentinel recurrence"
                    :value="formatPercent(kpis.sentinelRecurrence.rate)"
                    :delta="kpis.sentinelRecurrence.total ? `${kpis.sentinelRecurrence.recurrences}/${kpis.sentinelRecurrence.total} repeat` : 'No sentinel events'"
                    :delta-is-good="kpis.sentinelRecurrence.rate === 0"
                />
                <KpiStatTile
                    label="Near-miss reporting velocity"
                    :value="`${kpis.nearMissVelocityPercent > 0 ? '+' : ''}${kpis.nearMissVelocityPercent}%`"
                    delta="vs. prior 30 days"
                    :delta-is-good="true"
                />
            </div>

            <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
                <div>
                    <h2 class="font-title-lg text-title-lg text-primary font-bold">Root Cause Distribution</h2>
                    <p class="font-body-sm text-body-sm text-outline">Contributing factors recorded on incidents in the last 90 days, by category.</p>
                </div>
                <StackedBarChart :segments="rootCauseDistribution" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
