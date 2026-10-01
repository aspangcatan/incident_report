<script setup>
import { computed, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import KpiStatTile from '@/Components/Analytics/KpiStatTile.vue';
import MonthlySeverityChart from '@/Components/Analytics/MonthlySeverityChart.vue';
import ChangeBarList from '@/Components/Analytics/ChangeBarList.vue';
import { sequentialBlue } from '@/Utils/chartPalette';
import { severityLabel } from '@/Composables/useIncidentStatus';

const props = defineProps({
    filters: { type: Object, required: true },
    periodLabel: { type: String, required: true },
    total: { type: Number, required: true },
    previousTotal: { type: Number, required: true },
    monthly: { type: Array, required: true },
    byType: { type: Array, required: true },
    byDepartment: { type: Array, required: true },
    heatmap: { type: Object, required: true },
    resolution: { type: Object, required: true },
    options: { type: Object, required: true },
});

const form = reactive({ ...props.filters });

function apply() {
    const query = Object.fromEntries(Object.entries(form).filter(([, value]) => value !== null && value !== ''));
    router.get('/analytics/trends', query, { preserveScroll: true });
}

function reset() {
    router.get('/analytics/trends');
}

const totalChange = computed(() => {
    const diff = props.total - props.previousTotal;
    if (diff === 0) return 'Same as the previous period';
    return `${diff > 0 ? '▲' : '▼'} ${Math.abs(diff)} vs the previous period (${props.previousTotal})`;
});

const heatMax = computed(() => Math.max(1, ...props.heatmap.rows.flatMap((row) => Object.values(row.cells))));

// Zero stays the surface; otherwise a step of the sequential ramp by magnitude.
function cellStyle(count) {
    if (!count) return {};
    const step = Math.min(sequentialBlue.length - 1, Math.round((count / heatMax.value) * (sequentialBlue.length - 1)));
    return { backgroundColor: sequentialBlue[step], color: step >= 7 ? '#ffffff' : '#131b2e' };
}

const days = (value) => (value === null ? 'No data yet' : `${value} days`);
</script>

<template>
    <Head title="Trends" />

    <AuthenticatedLayout>
        <div class="flex flex-col gap-1">
            <h1 class="font-headline-sm text-headline-sm text-on-surface">Trend Analysis</h1>
            <p class="font-body-sm text-body-sm text-outline">{{ periodLabel }}. Only incidents you are allowed to see are counted; drafts are not.</p>
        </div>

        <form class="rounded-xl bg-surface-container-lowest p-space-md shadow-sm flex flex-wrap items-end gap-space-md" @submit.prevent="apply">
            <div class="flex flex-col gap-1">
                <label for="trend_from" class="font-label-md text-label-md text-on-surface font-semibold">From</label>
                <input id="trend_from" v-model="form.from" type="month" class="p-2 rounded-lg bg-surface-container-low" />
            </div>
            <div class="flex flex-col gap-1">
                <label for="trend_to" class="font-label-md text-label-md text-on-surface font-semibold">To</label>
                <input id="trend_to" v-model="form.to" type="month" class="p-2 rounded-lg bg-surface-container-low" />
            </div>
            <div class="flex flex-col gap-1 min-w-[220px]">
                <label for="trend_department" class="font-label-md text-label-md text-on-surface font-semibold">Department</label>
                <select id="trend_department" v-model="form.department_id" class="p-2 rounded-lg bg-surface-container-low">
                    <option :value="null">All departments</option>
                    <option v-for="department in options.departments" :key="department.id" :value="department.id">{{ department.name }}</option>
                </select>
            </div>
            <div class="flex flex-col gap-1 min-w-[220px]">
                <label for="trend_type" class="font-label-md text-label-md text-on-surface font-semibold">Incident type</label>
                <select id="trend_type" v-model="form.incident_type_id" class="p-2 rounded-lg bg-surface-container-low">
                    <option :value="null">All types</option>
                    <option v-for="type in options.incidentTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
                </select>
            </div>
            <button type="submit" class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold">Apply</button>
            <button type="button" class="px-4 py-2 rounded-lg bg-surface-container text-on-surface font-label-md text-label-md" @click="reset">Reset</button>
            <span class="font-body-sm text-body-sm text-outline w-full">Up to 24 months at a time.</span>
        </form>

        <section class="grid grid-cols-1 sm:grid-cols-3 gap-space-md">
            <KpiStatTile label="Incidents in period" :value="String(total)" :delta="totalChange" />
            <KpiStatTile label="Closed in period" :value="String(resolution.closedCount)" />
            <KpiStatTile label="Average days from report to closure" :value="days(resolution.averageDays)" />
        </section>

        <section class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
            <div>
                <h2 class="font-title-lg text-title-lg text-primary font-bold">Incidents per Month</h2>
                <p class="font-body-sm text-body-sm text-outline">By severity. Hover a month for its breakdown.</p>
            </div>
            <MonthlySeverityChart :months="monthly" />
        </section>

        <section class="grid grid-cols-1 lg:grid-cols-2 gap-space-md">
            <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
                <div>
                    <h2 class="font-title-lg text-title-lg text-primary font-bold">Top Incident Types</h2>
                    <p class="font-body-sm text-body-sm text-outline">An incident with several types counts toward each.</p>
                </div>
                <ChangeBarList :items="byType" />
            </div>
            <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
                <div>
                    <h2 class="font-title-lg text-title-lg text-primary font-bold">Top Departments</h2>
                    <p class="font-body-sm text-body-sm text-outline">Where incidents were reported.</p>
                </div>
                <ChangeBarList :items="byDepartment" />
            </div>
        </section>

        <section class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
            <div>
                <h2 class="font-title-lg text-title-lg text-primary font-bold">Department × Severity</h2>
                <p class="font-body-sm text-body-sm text-outline">Darker cells mean more incidents. Top 10 departments in the period.</p>
            </div>
            <p v-if="!heatmap.rows.length" class="font-body-sm text-body-sm text-outline text-center p-space-md">No incidents in this period.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-left border-separate border-spacing-[2px]">
                    <thead>
                        <tr class="font-label-sm text-body-sm uppercase text-outline">
                            <th class="p-2">Department</th>
                            <th v-for="severity in heatmap.severities" :key="severity.value" class="p-2 text-center">{{ severityLabel(severity.value) }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in heatmap.rows" :key="row.department">
                            <td class="p-2 font-body-sm text-body-sm text-on-surface">{{ row.department }}</td>
                            <td
                                v-for="severity in heatmap.severities"
                                :key="severity.value"
                                class="p-2 text-center font-code-tabular text-body-sm rounded bg-surface-container-low"
                                :style="cellStyle(row.cells[severity.value])"
                                :title="`${row.department} · ${severityLabel(severity.value)}: ${row.cells[severity.value]}`"
                            >
                                {{ row.cells[severity.value] }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
            <div>
                <h2 class="font-title-lg text-title-lg text-primary font-bold">Time to Closure</h2>
                <p class="font-body-sm text-body-sm text-outline">Average days from report to closure, for incidents closed in the period.</p>
            </div>
            <table class="w-full text-left">
                <thead>
                    <tr class="font-label-sm text-body-sm uppercase text-outline">
                        <th class="py-1">Severity</th>
                        <th class="py-1 text-right">Closed</th>
                        <th class="py-1 text-right">Average days</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in resolution.bySeverity" :key="row.value" class="font-body-sm text-body-sm text-on-surface border-t border-surface-container">
                        <td class="py-1.5">{{ severityLabel(row.value) }}</td>
                        <td class="py-1.5 text-right font-code-tabular">{{ row.count }}</td>
                        <td class="py-1.5 text-right font-code-tabular">{{ row.averageDays ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </AuthenticatedLayout>
</template>
