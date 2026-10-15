<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import SeverityBadge from '@/Components/SeverityBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import { formatDate } from '@/Utils/formatDate';

const props = defineProps({
    incidents: { type: Object, required: true },
    scope: { type: String, required: true },
    queue: { type: Object, default: null },
    search: { type: String, default: '' },
});

const scopes = [
    { value: 'my-reports', label: 'My Reports' },
    { value: 'drafts', label: 'Draft Reports' },
    { value: 'all', label: 'All Incidents' },
];

// Same icons as the sidebar.
const icons = {
    'my-reports': 'user',
    drafts: 'pen-to-square',
    all: 'kit-medical',
    'awaiting-assessment': 'clipboard-list',
    'pending-review': 'hourglass-half',
    'under-investigation': 'magnifying-glass',
    'corrective-actions': 'square-check',
    'effectiveness-checks': 'clipboard-check',
    'ready-for-closure': 'flag-checkered',
    'closure-approvals': 'stamp',
    resolved: 'circle-check',
    'investigation-queue': 'notes-medical',
    'assigned-to-me': 'clipboard-user',
    'investigation-history': 'clock-rotate-left',
};

const descriptions = {
    'my-reports': 'Incidents you reported, and where each one is now.',
    drafts: 'Reports you have not submitted yet, and reports returned to you for revision.',
    all: 'Every incident you have access to.',
};

const title = computed(() => props.queue?.title ?? scopes.find((option) => option.value === props.scope)?.label ?? 'Incidents');
const description = computed(() => props.queue?.description ?? descriptions[props.scope] ?? null);

// The effectiveness check and closure request forms live on the Approvals tab.
const approvalsTabScopes = ['effectiveness-checks', 'ready-for-closure', 'closure-approvals'];

function incidentUrl(incident) {
    if (incident.status === 'draft') return `/incidents/${incident.id}/edit`;
    return approvalsTabScopes.includes(props.scope) ? `/incidents/${incident.id}?tab=approvals` : `/incidents/${incident.id}`;
}

function switchScope(value) {
    router.get('/incidents', { scope: value }, { preserveState: true });
}

function typeNames(incident) {
    return [...(incident.incident_types ?? []).map((type) => type.name), ...(incident.incident_type_other ? [incident.incident_type_other] : [])].join(', ') || '—';
}
</script>

<template>
    <Head :title="title" />

    <AuthenticatedLayout :refresh="['incidents']">
        <PageHeader :title="title" :description="description" :icon="icons[scope] ?? 'kit-medical'" :eyebrow="queue ? 'Work queue' : 'Incidents'">
            <template #actions>
                <Link
                    href="/incidents/create"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-secondary text-on-secondary hover:bg-on-secondary-container font-label-md text-label-md font-semibold shadow-sm transition-colors"
                >
                    <FontAwesomeIcon icon="circle-plus" /> Report an Incident
                </Link>
            </template>
            <div v-if="!queue" class="inline-flex max-w-full overflow-x-auto p-1 rounded-lg bg-surface-container-low ring-1 ring-outline-variant/40 w-fit">
                <button
                    v-for="option in scopes"
                    :key="option.value"
                    type="button"
                    class="px-3 py-1.5 rounded-md font-label-md text-label-md whitespace-nowrap transition-colors"
                    :class="scope === option.value ? 'bg-surface-container-lowest text-primary font-semibold shadow-sm' : 'text-on-surface-variant hover:text-on-surface'"
                    @click="switchScope(option.value)"
                >
                    {{ option.label }}
                </button>
            </div>
        </PageHeader>

        <div v-if="search" class="flex flex-wrap items-center gap-2 font-body-md text-body-md text-on-surface-variant">
            <FontAwesomeIcon icon="magnifying-glass" class="text-outline" />
            Showing results for <span class="font-semibold text-on-surface">“{{ search }}”</span>
            <Link :href="`/incidents?scope=${scope}`" class="font-label-md text-label-md text-primary font-semibold">Clear search</Link>
        </div>

        <section class="rounded-2xl bg-surface-container-lowest shadow-sm ring-1 ring-outline-variant/40 overflow-hidden">
            <EmptyState
                v-if="incidents.data.length === 0"
                :icon="search ? 'magnifying-glass' : (queue ? 'circle-check' : 'kit-medical')"
                :title="search ? 'No incidents match your search' : (queue ? `Nothing in ${queue.title} right now` : 'No incidents in this view yet')"
                :text="search ? 'Try another incident number or location.' : (queue ? 'You are all caught up.' : null)"
            />

            <div v-else class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-outline-variant/40 text-outline font-label-sm text-label-sm uppercase tracking-wider">
                            <th class="px-4 py-3">Incident No.</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Department</th>
                            <th class="px-4 py-3">Reported</th>
                            <th class="px-4 py-3">Severity</th>
                            <th class="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/30">
                        <tr
                            v-for="incident in incidents.data"
                            :key="incident.id"
                            class="hover:bg-surface-container-low cursor-pointer transition-colors"
                            @click="router.visit(incidentUrl(incident))"
                        >
                            <td class="px-4 py-3 whitespace-nowrap">
                                <Link :href="incidentUrl(incident)" class="font-code-tabular text-body-md text-primary font-semibold hover:underline" @click.stop>
                                    {{ incident.incident_number ?? `Draft #${incident.id}` }}
                                </Link>
                                <span v-if="incident.location" class="block font-body-sm text-body-sm text-outline truncate max-w-[14rem]">{{ incident.location }}</span>
                            </td>
                            <td class="px-4 py-3 font-body-md text-body-md text-on-surface max-w-[18rem]">{{ typeNames(incident) }}</td>
                            <td class="px-4 py-3 font-body-md text-body-md text-on-surface">{{ incident.department?.name ?? '—' }}</td>
                            <td class="px-4 py-3 font-body-sm text-body-sm text-on-surface-variant whitespace-nowrap">{{ formatDate(incident.reported_at ?? incident.created_at) }}</td>
                            <td class="px-4 py-3"><SeverityBadge :severity="incident.severity" /></td>
                            <td class="px-4 py-3"><StatusBadge :status="incident.status" :returned="incident.is_returned" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <Pagination :paginator="incidents" />
    </AuthenticatedLayout>
</template>
