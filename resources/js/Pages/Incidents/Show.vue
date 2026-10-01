<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import SeverityBadge from '@/Components/SeverityBadge.vue';
import WorkflowActionsPanel from '@/Components/Incidents/WorkflowActionsPanel.vue';
import AssessmentPanel from '@/Components/Incidents/AssessmentPanel.vue';
import InvestigationPanel from '@/Components/Incidents/InvestigationPanel.vue';
import SentinelPathwayPanel from '@/Components/Incidents/SentinelPathwayPanel.vue';
import CapaPanel from '@/Components/Incidents/CapaPanel.vue';
import ApprovalPanel from '@/Components/Incidents/ApprovalPanel.vue';
import { formatDate } from '@/Utils/formatDate';

const props = defineProps({
    incident: { type: Object, required: true },
    tab: { type: String, required: true },
    can: { type: Object, required: true },
    investigators: { type: Array, default: () => [] },
    investigation: { type: Object, default: null },
    potentialTeamMembers: { type: Array, default: () => [] },
    correctiveActions: { type: Array, default: () => [] },
    investigationFindings: { type: Array, default: () => [] },
    potentialResponsibleUsers: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    approvals: { type: Array, default: () => [] },
    auditLogs: { type: Array, required: true },
    injuryOptions: { type: Object, required: true },
    similarIncidents: { type: Array, default: () => [] },
    evidenceStage: { type: String, default: null },
});

const stageLabels = { report: 'Report', assessment: 'Department Assessment', investigation: 'Investigation', capa: 'Corrective Action proof' };
const evidenceForm = useForm({ files: [] });

function addEvidence() {
    evidenceForm.post(`/incidents/${props.incident.id}/evidence`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => evidenceForm.reset(),
    });
}

// Chosen labels plus the "Others (Specify)" text, as one readable line.
function listWithOther(labels, other) {
    const all = [...labels, ...(other ? [`Others: ${other}`] : [])];
    return all.length ? all.join('; ') : '—';
}

// Header headline: every incident type and the location, e.g. "Bodily Injury, Falls · Room 312".
const headline = computed(() => {
    const types = [...(props.incident.incident_types ?? []).map((type) => type.name), ...(props.incident.incident_type_other ? [props.incident.incident_type_other] : [])];
    const type = types.length ? types.join(', ') : 'Incident report';
    return props.incident.location ? `${type} · ${props.incident.location}` : type;
});

// Long descriptions start folded to 6 lines so the tabs stay in view.
const descriptionEl = ref(null);
const showFullDescription = ref(false);
const descriptionIsLong = ref(false);
onMounted(() => {
    const el = descriptionEl.value;
    descriptionIsLong.value = !!el && el.scrollHeight > el.clientHeight + 1;
});

const typeNames = computed(() => listWithOther((props.incident.incident_types ?? []).map((type) => type.name), props.incident.incident_type_other));
const injuryCauses = computed(() => listWithOther((props.incident.injury_causes ?? []).map((v) => props.injuryOptions.causes[v] ?? v), props.incident.injury_cause_other));
const injuryAgents = computed(() => listWithOther((props.incident.injury_agents ?? []).map((v) => props.injuryOptions.agents[v] ?? v), props.incident.injury_agent_other));
const hasReportDetails = computed(() => props.incident.has_injury !== null
    || props.incident.individuals?.length
    || props.incident.witnesses?.length
    || props.incident.police_notified
    || props.incident.narrative_events?.length);


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
                <StatusBadge :status="incident.status" :returned="incident.is_returned" />
                <SeverityBadge :severity="incident.severity" />
                <span v-if="incident.is_sentinel_event" class="px-2.5 py-0.5 rounded-full bg-error-container text-on-error-container font-label-sm text-body-sm font-semibold">
                    Sentinel Event
                </span>
                <span class="font-code-tabular text-body-sm text-outline">{{ incident.incident_number ?? `Draft #${incident.id}` }}</span>
            </div>
            <h1 class="font-headline-md text-headline-md text-primary tracking-tight">{{ headline }}</h1>
            <!-- The whole description, easy to read: the reporter's line breaks and paragraphs are kept. -->
            <div v-if="incident.summary" class="flex flex-col gap-1.5 border-l-4 border-secondary pl-space-md max-w-5xl">
                <span class="font-label-sm text-body-sm uppercase tracking-wider text-outline font-semibold">What happened</span>
                <p
                    ref="descriptionEl"
                    class="font-body-lg text-[17px] leading-[1.75] text-on-surface whitespace-pre-line"
                    :class="showFullDescription ? '' : 'line-clamp-6'"
                >{{ incident.summary }}</p>
                <button
                    v-if="descriptionIsLong"
                    type="button"
                    class="self-start font-label-md text-label-md text-primary font-semibold"
                    :aria-expanded="showFullDescription"
                    @click="showFullDescription = !showFullDescription"
                >
                    {{ showFullDescription ? 'Show less' : 'Show full description' }}
                </button>
            </div>
            <Link
                v-if="can.issueSafetyAlert && incident.incident_number"
                :href="`/safety-alerts/create?incident=${incident.id}`"
                class="self-start mt-2 px-3 py-1.5 rounded-lg bg-surface-container text-primary font-label-md text-label-md font-semibold"
            >
                <FontAwesomeIcon icon="bullhorn" /> Issue Safety Alert
            </Link>
        </div>

        <div v-if="incident.is_sentinel_event" role="alert" class="rounded-lg bg-error text-on-error p-space-md flex items-start gap-3">
            <FontAwesomeIcon icon="triangle-exclamation" class="mt-1" />
            <div class="flex flex-col gap-0.5">
                <span class="font-title-sm text-title-sm font-bold">Sentinel Event</span>
                <span class="font-body-md text-body-md">Immediate patient safety and clinical response takes priority over documentation. Follow the Sentinel Event Pathway on the Overview tab.</span>
            </div>
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
                <span v-if="incident.reporter_id === null" class="font-body-md text-body-md text-on-surface font-semibold">
                    {{ incident.guest_name }} <span class="font-body-sm text-body-sm text-outline">(Guest · {{ incident.guest_relationship }} · {{ incident.guest_contact }})</span>
                </span>
                <span v-else class="font-body-md text-body-md text-on-surface font-semibold">{{ incident.reporter?.name ?? '—' }}</span>
            </div>
            <div class="flex flex-col gap-0.5">
                <span class="font-label-sm text-body-sm text-outline">Lead Investigator</span>
                <span class="font-body-md text-body-md text-secondary font-semibold">{{ incident.assigned_investigator?.name ?? (incident.investigation_skipped_at ? 'Not required' : 'Not yet assigned') }}</span>
            </div>
            <div class="flex flex-col gap-0.5">
                <span class="font-label-sm text-body-sm text-outline">Type</span>
                <span class="font-body-md text-body-md text-on-surface font-semibold">{{ typeNames }}</span>
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

        <template v-if="activeTab === 'overview'">
            <SentinelPathwayPanel v-if="incident.is_sentinel_event" :incident="incident" :can="can" />
            <!-- What the reporter recorded: read this first, then assess. -->
            <section class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-lg">
                <div class="flex flex-col gap-1">
                    <h2 class="font-title-lg text-title-lg text-primary font-bold">Report Details</h2>
                    <span class="font-body-sm text-body-sm text-outline">What the reporter recorded about the incident.</span>
                </div>
                <div v-if="incident.has_injury !== null" class="flex flex-col gap-1">
                    <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">Accident / Injury Details</span>
                    <div class="p-3 rounded-lg bg-surface-container-low flex flex-col gap-1">
                        <span class="font-body-md text-body-md text-on-surface">Anyone injured: <strong>{{ incident.has_injury ? 'Yes' : 'No' }}</strong></span>
                        <template v-if="incident.has_injury">
                            <span class="font-body-md text-body-md text-on-surface">Cause of injury: {{ injuryCauses }}</span>
                            <span class="font-body-md text-body-md text-on-surface">Agent of injury: {{ injuryAgents }}</span>
                            <span v-if="incident.injury_chemical_details" class="font-body-md text-body-md text-on-surface">Chemical involved: {{ incident.injury_chemical_details }}</span>
                        </template>
                    </div>
                </div>

                <div v-if="incident.individuals?.length" class="flex flex-col gap-2">
                    <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">People Involved</span>
                    <div v-for="person in incident.individuals" :key="person.id" class="p-3 rounded-lg bg-surface-container-low flex flex-col">
                        <span class="font-title-sm text-title-sm text-on-surface font-semibold">{{ person.name }} <span class="capitalize">({{ person.person_type }})</span></span>
                        <span class="font-body-sm text-body-sm text-on-surface-variant">
                            {{ [person.identifier, person.role_description].filter(Boolean).join(' · ') }}
                        </span>
                        <span v-if="person.details" class="font-body-sm text-body-sm text-on-surface-variant">{{ person.details }}</span>
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

                <p v-if="!hasReportDetails" class="font-body-sm text-body-sm text-outline">No further details were recorded.</p>
            </section>

            <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-lg">
                <AssessmentPanel :incident="incident" :can="can" :departments="departments" :investigators="investigators" />

                <div v-if="incident.contributing_factors?.length" class="flex flex-col gap-2">
                    <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">Contributing Factors</span>
                    <div class="flex flex-wrap gap-2">
                        <span v-for="factor in incident.contributing_factors" :key="factor.id" class="px-2.5 py-0.5 rounded-full bg-surface-container-low text-on-surface font-label-sm text-body-sm">
                            {{ factor.label }}
                        </span>
                    </div>
                </div>

                <div v-if="similarIncidents.length" class="flex flex-col gap-2">
                    <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">Similar Past Incidents & Lessons</span>
                    <span class="font-body-sm text-body-sm text-outline -mt-1">Closed incidents of the same type, and what the hospital learned from them.</span>
                    <div v-for="similar in similarIncidents" :key="similar.id" class="p-3 rounded-lg bg-surface-container-low flex flex-col gap-0.5">
                        <span class="font-label-md text-label-md text-on-surface font-semibold">
                            {{ similar.types.join(', ') }} · {{ similar.department ?? 'Department not recorded' }} · {{ formatDate(similar.published_at) }}
                        </span>
                        <span class="font-body-md text-body-md text-on-surface whitespace-pre-line">{{ similar.lesson }}</span>
                    </div>
                </div>
            </div>
        </template>

        <div v-else-if="activeTab === 'attachments'" class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
            <form v-if="can.addEvidence" class="flex flex-col gap-1.5 p-space-md rounded-lg bg-surface-container-low" @submit.prevent="addEvidence">
                <label for="evidence_files" class="font-label-md text-label-md text-on-surface font-semibold">Add evidence ({{ evidenceStage }})</label>
                <span class="font-body-sm text-body-sm text-outline">Photos, statements, logs or documents. PDF, PNG, JPG, DOC or DOCX, up to 25 MB each. Files can't be removed once added.</span>
                <input id="evidence_files" type="file" multiple accept=".pdf,.png,.jpg,.jpeg,.doc,.docx" class="font-body-sm text-body-sm" @change="evidenceForm.files = Array.from($event.target.files)" />
                <span v-if="evidenceForm.errors.files" class="font-body-sm text-body-sm text-error">{{ evidenceForm.errors.files }}</span>
                <span v-for="(message, key) in evidenceForm.errors" v-show="key.startsWith('files.')" :key="key" class="font-body-sm text-body-sm text-error">{{ message }}</span>
                <button type="submit" :disabled="evidenceForm.processing || !evidenceForm.files.length" class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60 w-fit">
                    Upload
                </button>
            </form>

            <div v-if="!incident.attachments?.length" class="text-center font-body-sm text-body-sm text-outline p-space-lg">
                No attachments uploaded.
            </div>
            <a
                v-for="file in incident.attachments"
                :key="file.id"
                :href="`/attachments/${file.id}`"
                class="p-3 rounded-lg bg-surface-container-low flex items-center justify-between gap-3 hover:bg-surface-container"
            >
                <div class="flex flex-col min-w-0">
                    <span class="font-body-md text-body-md text-on-surface truncate">{{ file.original_filename }}</span>
                    <span class="font-body-sm text-body-sm text-outline">
                        {{ stageLabels[file.stage] ?? 'Report' }}<template v-if="file.corrective_action"> · {{ file.corrective_action.capa_number }}</template>
                        · {{ file.uploaded_by?.name ?? 'Unknown' }} · {{ formatDate(file.created_at) }}
                    </span>
                </div>
                <FontAwesomeIcon icon="eye" class="text-primary flex-shrink-0" />
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

        <div
            v-else-if="activeTab === 'investigation' && incident.investigation_skipped_at"
            class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-xs"
        >
            <h2 class="font-title-lg text-title-lg text-primary font-bold">No Investigation Needed</h2>
            <p class="font-body-md text-body-md text-on-surface whitespace-pre-line">"{{ incident.investigation_skipped_reason }}"</p>
            <span class="font-body-sm text-body-sm text-outline">
                Decided by {{ incident.investigation_skipped_by?.name ?? 'the Patient Safety/CQI Office' }} on {{ formatDate(incident.investigation_skipped_at) }}
            </span>
        </div>

        <InvestigationPanel
            v-else-if="activeTab === 'investigation'"
            :incident="incident"
            :investigation="investigation"
            :can="can"
            :potential-team-members="potentialTeamMembers"
        />

        <CapaPanel
            v-else-if="activeTab === 'capa'"
            :incident="incident"
            :corrective-actions="correctiveActions"
            :investigation-findings="investigationFindings"
            :potential-responsible-users="potentialResponsibleUsers"
            :departments="departments"
            :can="can"
        />

        <ApprovalPanel
            v-else-if="activeTab === 'approvals'"
            :incident="incident"
            :approvals="approvals"
            :can="can"
        />

        <div v-else class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm text-center">
            <FontAwesomeIcon icon="circle-info" class="text-primary text-title-lg mb-2" />
            <p class="font-body-md text-body-md text-on-surface-variant">
                This tab will be available once the corresponding module ships in a later phase.
            </p>
        </div>
    </AuthenticatedLayout>
</template>
