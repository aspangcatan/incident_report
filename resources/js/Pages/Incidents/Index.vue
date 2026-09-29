<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import SeverityBadge from '@/Components/SeverityBadge.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
    incidents: { type: Object, required: true },
    scope: { type: String, required: true },
    queue: { type: Object, default: null },
});

const scopes = [
    { value: 'my-reports', label: 'My Reports' },
    { value: 'drafts', label: 'Draft Reports' },
    { value: 'all', label: 'All Incidents' },
];

function switchScope(value) {
    router.get('/incidents', { scope: value }, { preserveState: true });
}
</script>

<template>
    <Head title="Incidents" />

    <AuthenticatedLayout>
        <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
            <div class="flex items-center justify-between flex-wrap gap-2">
                <div class="flex flex-col gap-0.5">
                    <h1 class="font-headline-sm text-headline-sm text-on-surface">{{ queue ? queue.title : 'Incidents' }}</h1>
                    <p v-if="queue" class="font-body-sm text-body-sm text-outline">{{ queue.description }}</p>
                </div>
                <Link href="/incidents/create" class="px-4 py-2 rounded-lg bg-secondary text-on-secondary font-label-md text-label-md font-semibold">
                    + Report an Incident
                </Link>
            </div>

            <div v-if="!queue" class="inline-flex p-1 rounded-lg bg-surface-container-low w-fit">
                <button
                    v-for="option in scopes"
                    :key="option.value"
                    type="button"
                    class="px-3 py-1.5 rounded-md font-label-sm text-body-sm"
                    :class="scope === option.value ? 'bg-surface-container-lowest text-primary font-semibold shadow-sm' : 'text-outline'"
                    @click="switchScope(option.value)"
                >
                    {{ option.label }}
                </button>
            </div>

            <div v-if="incidents.data.length === 0" class="p-space-lg text-center font-body-sm text-body-sm text-outline">
                {{ queue ? `Nothing in ${queue.title} right now.` : 'No incidents found in this view.' }}
            </div>

            <div v-else class="overflow-x-auto rounded-lg bg-surface-container-low">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-surface-container text-on-surface-variant font-label-sm text-label-sm uppercase tracking-wider">
                            <th class="p-3">Incident No.</th>
                            <th class="p-3">Type</th>
                            <th class="p-3">Department</th>
                            <th class="p-3">Severity</th>
                            <th class="p-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-container">
                        <tr v-for="incident in incidents.data" :key="incident.id" class="bg-surface-container-lowest hover:bg-surface-container-low">
                            <td class="p-3">
                                <Link :href="incident.status === 'draft' ? `/incidents/${incident.id}/edit` : `/incidents/${incident.id}`" class="font-code-tabular text-body-sm text-primary font-semibold">
                                    {{ incident.incident_number ?? `Draft #${incident.id}` }}
                                </Link>
                            </td>
                            <td class="p-3 font-body-sm text-body-sm text-on-surface">{{ [...(incident.incident_types ?? []).map((type) => type.name), ...(incident.incident_type_other ? [incident.incident_type_other] : [])].join(', ') || '—' }}</td>
                            <td class="p-3 font-body-sm text-body-sm text-on-surface">{{ incident.department?.name ?? '—' }}</td>
                            <td class="p-3">
                                <SeverityBadge :severity="incident.severity" />
                            </td>
                            <td class="p-3"><StatusBadge :status="incident.status" :returned="incident.is_returned" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="incidents" />
        </div>
    </AuthenticatedLayout>
</template>
