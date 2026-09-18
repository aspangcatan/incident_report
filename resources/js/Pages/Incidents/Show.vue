<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import SeverityBadge from '@/Components/SeverityBadge.vue';
import WorkflowActionsPanel from '@/Components/Incidents/WorkflowActionsPanel.vue';
import InvestigationPanel from '@/Components/Incidents/InvestigationPanel.vue';
import { formatDate } from '@/Utils/formatDate';

const props = defineProps({
    incident: { type: Object, required: true },
    tab: { type: String, required: true },
    can: { type: Object, required: true },
    investigators: { type: Array, default: () => [] },
    investigation: { type: Object, default: null },
    potentialTeamMembers: { type: Array, default: () => [] },
    auditLogs: { type: Array, required: true },
});

const tabs = [
    { value: 'overview', label: 'Overview & Case Summary' },
    { value: 'investigation', label: 'Investigation & Root Cause' },
    { value: 'capa', label: 'Corrective & Preventive Actions' },
    { value: 'attachments', label: 'Evidence & Attachments' },
    { value: 'approvals', label: 'Approvals' },
    { value: 'audit', label: 'Audit Trail' },
];

const activeTab = ref(props.tab);

function switchTab(value) {
    activeTab.value = value;
    router.get(`/incidents/${props.incident.id}`, { tab: value }, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <Head :title="incident.incident_number ?? `Incident #${incident.id}`" />

    <AuthenticatedLayout>
        <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-sm">
            <div class="flex flex-wrap items-center gap-space-sm">
                <StatusBadge :status="incident.status" />
                <SeverityBadge v-if="incident.severity" :severity="incident.severity" />
                <span v-if="incident.is_sentinel_event" class="px-2.5 py-0.5 rounded-full bg-error-container text-on-error-container font-label-sm text-body-sm font-semibold">
                    Sentinel Event
                </span>
                <span class="font-code-tabular text-body-sm text-outline">{{ incident.incident_number ?? `Draft #${incident.id}` }}</span>
            </div>
            <h1 class="font-headline-md text-headline-md text-primary tracking-tight">
                {{ incident.summary || 'Incident report' }}
            </h1>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 bg-surface-container-low p-space-md rounded-xl">
            <div class="flex flex-col gap-0.5">
                <span class="font-label-sm text-body-sm text-outline">Incident Time</span>
                <span class="font-code-tabular text-body-md text-on-surface font-semibold">{{ formatDate(incident.occurred_at) }}</span>
            </div>
            <div class="flex flex-col gap-0.5">
                <span class="font-label-sm text-body-sm text-outline">Reported</span>
                <span class="font-code-tabular text-body-md text-on-surface font-semibold">{{ formatDate(incident.reported_at) }}</span>
            </div>
            <div class="flex flex-col gap-0.5">
                <span class="font-label-sm text-body-sm text-outline">Clinical Unit</span>
                <span class="font-title-sm text-title-sm text-primary font-semibold">{{ incident.department?.name ?? '—' }}</span>
            </div>
            <div class="flex flex-col gap-0.5">
                <span class="font-label-sm text-body-sm text-outline">Reporter</span>
                <span class="font-body-md text-body-md text-on-surface font-semibold">{{ incident.reporter?.name ?? '—' }}</span>
            </div>
            <div class="flex flex-col gap-0.5">
                <span class="font-label-sm text-body-sm text-outline">Lead Investigator</span>
                <span class="font-body-md text-body-md text-secondary font-semibold">{{ incident.assigned_investigator?.name ?? 'Not yet assigned' }}</span>
            </div>
            <div class="flex flex-col gap-0.5">
                <span class="font-label-sm text-body-sm text-outline">Type</span>
                <span class="font-body-md text-body-md text-on-surface font-semibold">{{ incident.incident_type?.name ?? '—' }}</span>
            </div>
        </div>

        <WorkflowActionsPanel :incident="incident" :can="can" :investigators="investigators" />

        <div class="flex items-center gap-2 overflow-x-auto pb-1">
            <button
                v-for="tabOption in tabs"
                :key="tabOption.value"
                type="button"
                class="px-4 py-2.5 rounded-lg font-label-md text-label-md whitespace-nowrap"
                :class="activeTab === tabOption.value ? 'bg-primary text-on-primary font-semibold shadow-sm' : 'text-on-surface-variant hover:bg-surface-container'"
                @click="switchTab(tabOption.value)"
            >
                {{ tabOption.label }}
            </button>
        </div>

        <div v-if="activeTab === 'overview'" class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-lg">
            <div class="flex flex-col gap-1">
                <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">Executive Narrative Summary</span>
                <p class="font-body-md text-body-md text-on-surface">{{ incident.summary || '—' }}</p>
            </div>

            <div v-if="incident.individuals?.length" class="flex flex-col gap-2">
                <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">People Involved</span>
                <div v-for="person in incident.individuals" :key="person.id" class="p-3 rounded-lg bg-surface-container-low flex flex-col">
                    <span class="font-title-sm text-title-sm text-on-surface font-semibold">{{ person.name }} ({{ person.person_type }})</span>
                    <span class="font-body-sm text-body-sm text-on-surface-variant">{{ person.role_description }}</span>
                </div>
            </div>

            <div v-if="incident.witnesses?.length" class="flex flex-col gap-2">
                <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">Witnesses</span>
                <div v-for="witness in incident.witnesses" :key="witness.id" class="p-3 rounded-lg bg-surface-container-low flex flex-col">
                    <span class="font-title-sm text-title-sm text-on-surface font-semibold">{{ witness.name }}</span>
                    <span class="font-body-sm text-body-sm text-on-surface-variant">{{ witness.statement }}</span>
                </div>
            </div>

            <div v-if="incident.police_notified" class="flex flex-col gap-1">
                <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">Police Notification</span>
                <div class="p-3 rounded-lg bg-surface-container-low flex flex-col gap-0.5">
                    <span class="font-body-md text-body-md text-on-surface">{{ incident.police_station || '—' }}</span>
                    <span class="font-body-sm text-body-sm text-on-surface-variant">
                        Officer: {{ incident.police_officer_in_charge || '—' }} · Blotter #: {{ incident.police_blotter_no || '—' }}
                    </span>
                    <span class="font-body-sm text-body-sm text-on-surface-variant">
                        Notified: {{ formatDate(incident.police_notified_at) }}
                    </span>
                </div>
            </div>

            <div v-if="incident.narrative_events?.length" class="flex flex-col gap-2">
                <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">Sequence of Events</span>
                <div v-for="event in incident.narrative_events" :key="event.id" class="flex items-start gap-3 p-3 rounded-lg bg-surface-container-low">
                    <span class="font-code-tabular text-body-sm text-primary font-semibold min-w-[70px]">{{ event.occurred_at }}</span>
                    <span class="font-body-sm text-body-sm text-on-surface">{{ event.description }}</span>
                </div>
            </div>

            <div v-if="incident.contributing_factors?.length" class="flex flex-col gap-2">
                <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">Contributing Factors</span>
                <div class="flex flex-wrap gap-2">
                    <span v-for="factor in incident.contributing_factors" :key="factor.id" class="px-2.5 py-0.5 rounded-full bg-surface-container-low text-on-surface font-label-sm text-body-sm">
                        {{ factor.label }}
                    </span>
                </div>
            </div>

            <div v-if="incident.actions?.length" class="flex flex-col gap-2">
                <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">Immediate Actions Taken</span>
                <div v-for="action in incident.actions" :key="action.id" class="p-3 rounded-lg bg-surface-container-low flex flex-col">
                    <span class="font-body-md text-body-md text-on-surface">{{ action.description }}</span>
                    <span class="font-body-sm text-body-sm text-on-surface-variant">{{ action.responsible_name }} — {{ action.status }}</span>
                </div>
            </div>

            <div v-if="incident.recommendations" class="flex flex-col gap-1">
                <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">Recommendations / Preventive Measures</span>
                <p class="font-body-md text-body-md text-on-surface">{{ incident.recommendations }}</p>
            </div>
        </div>

        <div v-else-if="activeTab === 'attachments'" class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-2">
            <div v-if="!incident.attachments?.length" class="text-center font-body-sm text-body-sm text-outline p-space-lg">
                No attachments uploaded.
            </div>
            <a
                v-for="file in incident.attachments"
                :key="file.id"
                :href="`/attachments/${file.id}`"
                class="p-3 rounded-lg bg-surface-container-low flex items-center justify-between hover:bg-surface-container"
            >
                <span class="font-body-sm text-body-sm text-on-surface">{{ file.original_filename }}</span>
                <FontAwesomeIcon icon="eye" class="text-primary" />
            </a>
        </div>

        <div v-else-if="activeTab === 'audit'" class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-2">
            <div v-if="!auditLogs.length" class="text-center font-body-sm text-body-sm text-outline p-space-lg">
                No audit trail entries yet.
            </div>
            <div v-for="log in auditLogs" :key="log.id" class="flex items-start gap-3 p-3 rounded-lg bg-surface-container-low">
                <div class="w-8 h-8 rounded-full bg-primary-container text-on-primary flex items-center justify-center flex-shrink-0">
                    <FontAwesomeIcon icon="clock-rotate-left" class="text-body-sm" />
                </div>
                <div class="flex flex-col">
                    <span class="font-title-sm text-title-sm text-on-surface font-semibold">
                        {{ log.actor?.name ?? 'System' }} — {{ log.action.replaceAll('_', ' ') }}
                    </span>
                    <span v-if="log.description" class="font-body-sm text-body-sm text-on-surface-variant">{{ log.description }}</span>
                    <span class="font-code-tabular text-body-sm text-outline">{{ formatDate(log.created_at) }}</span>
                </div>
            </div>
        </div>

        <InvestigationPanel
            v-else-if="activeTab === 'investigation'"
            :incident="incident"
            :investigation="investigation"
            :can="can"
            :potential-team-members="potentialTeamMembers"
        />

        <div v-else class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm text-center">
            <FontAwesomeIcon icon="circle-info" class="text-primary text-title-lg mb-2" />
            <p class="font-body-md text-body-md text-on-surface-variant">
                This tab will be available once the corresponding module ships in a later phase.
            </p>
        </div>
    </AuthenticatedLayout>
</template>
