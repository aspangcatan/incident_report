<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import KpiStatTile from '@/Components/Analytics/KpiStatTile.vue';
import StackedBarChart from '@/Components/Analytics/StackedBarChart.vue';
import HourlyVolumeChart from '@/Components/Analytics/HourlyVolumeChart.vue';

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
    <Head title="Analytics" />

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

            <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
                <div>
                    <h2 class="font-title-lg text-title-lg text-primary font-bold">Incident Volume by Hour of Day</h2>
                    <p class="font-body-sm text-body-sm text-outline">Last 90 days, by the hour the incident occurred.</p>
                </div>
                <HourlyVolumeChart :hours="hourlyVolume" />
            </div>

            <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
                <div>
                    <h2 class="font-title-lg text-title-lg text-primary font-bold">Departmental Safety Index &amp; CAPA Compliance</h2>
                    <p class="font-body-sm text-body-sm text-outline">A simple, transparent internal heuristic - not a validated clinical index.</p>
                </div>
                <div v-if="!departmentSafety.length" class="text-center font-body-sm text-body-sm text-outline p-space-md">
                    No department data available yet.
                </div>
                <table v-else class="w-full text-left">
                    <thead>
                        <tr class="font-label-sm text-body-sm uppercase text-outline">
                            <th class="pb-2">Department</th>
                            <th class="pb-2">CAPA Resolution</th>
                            <th class="pb-2">Safety Index</th>
                            <th class="pb-2">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in departmentSafety" :key="row.departmentId" class="border-t border-outline-variant">
                            <td class="py-2 font-body-md text-body-md text-on-surface">{{ row.departmentName }}</td>
                            <td class="py-2 font-code-tabular text-body-sm text-on-surface-variant">{{ row.capasVerified }} / {{ row.capasTotal }}</td>
                            <td class="py-2">
                                <div class="flex items-center gap-2">
                                    <div class="w-24 h-2 rounded-full bg-surface-container overflow-hidden">
                                        <div class="h-full rounded-full bg-primary" :style="{ width: row.safetyIndex + '%' }" />
                                    </div>
                                    <span class="font-code-tabular text-body-sm text-on-surface-variant">{{ row.safetyIndex }}/100</span>
                                </div>
                            </td>
                            <td class="py-2">
                                <span
                                    class="px-2.5 py-0.5 rounded-full font-label-sm text-body-sm font-semibold"
                                    :class="{
                                        'bg-secondary-container text-on-secondary-container': row.statusLabel === 'Exemplary' || row.statusLabel === 'Optimal',
                                        'bg-surface-container text-on-surface': row.statusLabel === 'Compliant',
                                        'bg-error-container text-on-error-container': row.statusLabel === 'Needs Attention',
                                    }"
                                >
                                    {{ row.statusLabel }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
                <div>
                    <h2 class="font-title-lg text-title-lg text-primary font-bold">Recurring Pattern Alerts</h2>
                    <p class="font-body-sm text-body-sm text-outline">Same department + incident type, {{ 3 }}+ times in the last 90 days. A grouped count, not an AI-generated inference.</p>
                </div>
                <div v-if="!recurringPatterns.length" class="text-center font-body-sm text-body-sm text-outline p-space-md">
                    No recurring patterns detected in this window.
                </div>
                <div v-for="pattern in recurringPatterns" :key="`${pattern.departmentName}-${pattern.incidentTypeName}`" class="p-3 rounded-lg bg-surface-container-low flex items-center justify-between">
                    <div class="flex flex-col">
                        <span class="font-title-sm text-title-sm text-on-surface font-semibold">{{ pattern.incidentTypeName }}</span>
                        <span class="font-body-sm text-body-sm text-on-surface-variant">{{ pattern.departmentName }}</span>
                    </div>
                    <span class="px-3 py-1 rounded-full bg-error-container text-on-error-container font-label-sm text-body-sm font-semibold">
                        {{ pattern.incidentCount }} incidents
                    </span>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
