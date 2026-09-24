<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ConfirmationDialog from '@/Components/ConfirmationDialog.vue';
import { formatDate } from '@/Utils/formatDate';

const props = defineProps({
    incident: { type: Object, required: true },
    investigation: { type: Object, default: null },
    can: { type: Object, required: true },
    potentialTeamMembers: { type: Array, default: () => [] },
});

const methodologyOptions = {
    five_whys: '5 Whys',
    fishbone: 'Fishbone (Ishikawa)',
    hfacs: 'Human Factors (HFACS)',
    contributing_factors: 'Contributing Factors',
};

const startForm = useForm({
    objective: '',
    methodology: 'five_whys',
    target_completion_at: '',
});

function startInvestigation() {
    startForm.post(`/incidents/${props.incident.id}/investigation`, { preserveScroll: true });
}

const memberForm = useForm({ user_id: null, role_in_team: '' });

function addTeamMember() {
    memberForm.post(`/investigations/${props.investigation.id}/team-members`, {
        preserveScroll: true,
        onSuccess: () => memberForm.reset(),
    });
}

function removeTeamMember(memberId) {
    memberForm.delete(`/investigations/${props.investigation.id}/team-members/${memberId}`, { preserveScroll: true });
}

const usesSequence = computed(() => props.investigation?.methodology?.uses_sequence ?? false);
const usesCategory = computed(() => props.investigation?.methodology?.uses_category ?? false);

const findingForm = useForm({ category: '', question: '', finding: '', is_root_cause: false });
const editingFindingId = ref(null);
const editForm = useForm({ category: '', question: '', finding: '', is_root_cause: false });

function addFinding() {
    findingForm.post(`/investigations/${props.investigation.id}/findings`, {
        preserveScroll: true,
        onSuccess: () => findingForm.reset(),
    });
}

function startEditing(finding) {
    editingFindingId.value = finding.id;
    editForm.category = finding.category ?? '';
    editForm.question = finding.question ?? '';
    editForm.finding = finding.finding;
    editForm.is_root_cause = finding.is_root_cause;
}

function saveEdit(findingId) {
    editForm.patch(`/investigations/${props.investigation.id}/findings/${findingId}`, {
        preserveScroll: true,
        onSuccess: () => (editingFindingId.value = null),
    });
}

function deleteFinding(findingId) {
    findingForm.delete(`/investigations/${props.investigation.id}/findings/${findingId}`, { preserveScroll: true });
}

const completeForm = useForm({ conclusion: '' });
const showCompleteConfirm = ref(false);

function completeInvestigation() {
    completeForm.post(`/investigations/${props.investigation.id}/complete`, {
        preserveScroll: true,
        onFinish: () => (showCompleteConfirm.value = false),
    });
}
</script>

<template>
    <div class="flex flex-col gap-space-lg">
        <div v-if="!investigation">
            <div v-if="can.startInvestigation" class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-sm">
                <h2 class="font-title-lg text-title-lg text-primary font-bold">Start Investigation</h2>
                <label class="font-label-md text-label-md text-on-surface font-semibold" for="objective">Investigation objective</label>
                <textarea
                    id="objective"
                    v-model="startForm.objective"
                    rows="3"
                    class="w-full p-3 rounded-lg bg-surface-container-low"
                />
                <span v-if="startForm.errors.objective" class="font-body-sm text-body-sm text-error">{{ startForm.errors.objective }}</span>

                <label class="font-label-md text-label-md text-on-surface font-semibold" for="methodology">RCA methodology</label>
                <select id="methodology" v-model="startForm.methodology" class="w-full p-3 rounded-lg bg-surface-container-low">
                    <option v-for="(label, value) in methodologyOptions" :key="value" :value="value">{{ label }}</option>
                </select>
                <span v-if="startForm.errors.methodology" class="font-body-sm text-body-sm text-error">{{ startForm.errors.methodology }}</span>

                <label class="font-label-md text-label-md text-on-surface font-semibold" for="target_completion_at">Target completion date</label>
                <input
                    id="target_completion_at"
                    v-model="startForm.target_completion_at"
                    type="date"
                    class="w-full p-3 rounded-lg bg-surface-container-low"
                />

                <button
                    type="button"
                    :disabled="startForm.processing"
                    class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60 w-fit"
                    @click="startInvestigation"
                >
                    Start Investigation
                </button>
            </div>
            <div v-else class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm text-center">
                <p class="font-body-md text-body-md text-on-surface-variant">
                    Investigation has not started yet. It becomes available once an investigator is assigned.
                </p>
            </div>
        </div>

        <template v-else>
            <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
                <div class="flex flex-wrap items-center justify-between gap-space-sm">
                    <h3 class="font-title-lg text-title-lg text-on-surface">Investigation Mandate</h3>
                    <span class="px-3 py-1 rounded-full bg-secondary-container text-on-secondary-container font-label-sm text-body-sm font-semibold">
                        {{ investigation.status.label }}
                    </span>
                </div>
                <div class="p-space-md rounded-lg bg-surface-container-low flex flex-col gap-space-xs">
                    <span class="font-label-sm text-body-sm uppercase tracking-wider text-primary font-bold">Objective</span>
                    <p class="font-body-md text-body-md text-on-surface">{{ investigation.objective }}</p>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="flex flex-col gap-0.5">
                        <span class="font-label-sm text-body-sm text-outline">Methodology</span>
                        <span class="font-title-sm text-title-sm text-primary font-semibold">{{ investigation.methodology.label }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="font-label-sm text-body-sm text-outline">Started</span>
                        <span class="font-code-tabular text-body-sm text-on-surface">{{ formatDate(investigation.started_at) }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="font-label-sm text-body-sm text-outline">Target Completion</span>
                        <span class="font-code-tabular text-body-sm text-on-surface">{{ formatDate(investigation.target_completion_at) }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="font-label-sm text-body-sm text-outline">Lead Investigator</span>
                        <span class="font-body-sm text-body-sm text-on-surface">{{ investigation.lead_investigator?.name }}</span>
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">Investigation Team</span>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                        <div v-for="member in investigation.team_members" :key="member.id" class="flex items-center justify-between gap-2 p-2.5 rounded-lg bg-surface-container">
                            <div class="flex flex-col min-w-0">
                                <span class="font-title-sm text-title-sm text-on-surface truncate">{{ member.user?.name }}</span>
                                <span class="font-label-sm text-body-sm text-outline truncate">{{ member.role_in_team }}</span>
                            </div>
                            <button
                                v-if="can.manageInvestigationTeam"
                                type="button"
                                class="font-label-sm text-body-sm text-error"
                                @click="removeTeamMember(member.id)"
                            >
                                Remove
                            </button>
                        </div>
                    </div>

                    <form v-if="can.manageInvestigationTeam" class="flex flex-wrap items-start gap-2" @submit.prevent="addTeamMember">
                        <div class="flex flex-col">
                            <label class="font-label-sm text-body-sm text-on-surface" for="member_user_id">Add member</label>
                            <select id="member_user_id" v-model="memberForm.user_id" class="p-2 rounded-lg bg-surface-container-low">
                                <option :value="null" disabled>Select a user</option>
                                <option v-for="option in potentialTeamMembers" :key="option.id" :value="option.id">{{ option.label }}</option>
                            </select>
                            <span v-if="memberForm.errors.user_id" class="font-body-sm text-body-sm text-error">{{ memberForm.errors.user_id }}</span>
                        </div>
                        <div class="flex flex-col">
                            <label class="font-label-sm text-body-sm text-on-surface" for="member_role">Role on team</label>
                            <input id="member_role" v-model="memberForm.role_in_team" type="text" class="p-2 rounded-lg bg-surface-container-low" />
                            <span v-if="memberForm.errors.role_in_team" class="font-body-sm text-body-sm text-error">{{ memberForm.errors.role_in_team }}</span>
                        </div>
                        <button type="submit" :disabled="memberForm.processing" class="px-3 py-2 rounded-lg bg-primary text-on-primary font-label-sm text-body-sm font-semibold disabled:opacity-60">
                            Add
                        </button>
                    </form>
                </div>
            </div>

            <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
                <h3 class="font-title-lg text-title-lg text-on-surface">Root Cause Analysis — {{ investigation.methodology.label }}</h3>

                <div v-if="!investigation.findings?.length" class="text-center font-body-sm text-body-sm text-outline p-space-md">
                    No findings recorded yet.
                </div>

                <div v-for="finding in investigation.findings" :key="finding.id" class="flex items-start gap-3 p-3 rounded-lg" :class="finding.is_root_cause ? 'bg-error-container/30' : 'bg-surface-container-low'">
                    <div v-if="usesSequence" class="w-8 h-8 rounded-full flex items-center justify-center font-code-tabular text-body-sm font-bold flex-shrink-0" :class="finding.is_root_cause ? 'bg-error text-on-error' : 'bg-primary text-on-primary'">
                        {{ finding.sequence }}
                    </div>

                    <template v-if="editingFindingId === finding.id">
                        <div class="flex-1 flex flex-col gap-2">
                            <input v-if="usesCategory" v-model="editForm.category" type="text" placeholder="Category" class="p-2 rounded-lg bg-surface-container" />
                            <input v-if="usesSequence" v-model="editForm.question" type="text" placeholder="Why…?" class="p-2 rounded-lg bg-surface-container" />
                            <textarea v-model="editForm.finding" rows="2" class="p-2 rounded-lg bg-surface-container" />
                            <label class="flex items-center gap-2 font-label-sm text-body-sm text-on-surface">
                                <input v-model="editForm.is_root_cause" type="checkbox" /> Root cause
                            </label>
                            <div class="flex gap-2">
                                <button type="button" class="px-3 py-1.5 rounded-lg bg-primary text-on-primary font-label-sm text-body-sm" @click="saveEdit(finding.id)">Save</button>
                                <button type="button" class="px-3 py-1.5 rounded-lg bg-surface-container font-label-sm text-body-sm" @click="editingFindingId = null">Cancel</button>
                            </div>
                        </div>
                    </template>
                    <template v-else>
                        <div class="flex-1 flex flex-col gap-0.5">
                            <span v-if="finding.category" class="font-label-sm text-body-sm text-secondary font-semibold">{{ finding.category }}</span>
                            <span v-if="finding.question" class="font-label-sm text-body-sm text-primary font-semibold">{{ finding.question }}</span>
                            <p class="font-body-md text-body-md text-on-surface">{{ finding.finding }}</p>
                            <span v-if="finding.is_root_cause" class="px-2 py-0.5 rounded-md bg-error text-on-error font-label-sm text-body-sm uppercase font-bold w-fit">Root Cause</span>
                        </div>
                        <div v-if="can.recordFindings" class="flex flex-col gap-1 flex-shrink-0">
                            <button type="button" class="font-label-sm text-body-sm text-primary" @click="startEditing(finding)">Edit</button>
                            <button type="button" class="font-label-sm text-body-sm text-error" @click="deleteFinding(finding.id)">Delete</button>
                        </div>
                    </template>
                </div>

                <form v-if="can.recordFindings" class="flex flex-col gap-2 p-space-md rounded-lg bg-surface-container-low" @submit.prevent="addFinding">
                    <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">Add Finding</span>
                    <input v-if="usesCategory" v-model="findingForm.category" type="text" placeholder="Category (e.g. Equipment, Environment)" class="p-2 rounded-lg bg-surface-container" />
                    <input v-if="usesSequence" v-model="findingForm.question" type="text" placeholder="Why…?" class="p-2 rounded-lg bg-surface-container" />
                    <textarea v-model="findingForm.finding" rows="2" placeholder="Finding" class="p-2 rounded-lg bg-surface-container" />
                    <span v-if="findingForm.errors.finding" class="font-body-sm text-body-sm text-error">{{ findingForm.errors.finding }}</span>
                    <label class="flex items-center gap-2 font-label-sm text-body-sm text-on-surface">
                        <input v-model="findingForm.is_root_cause" type="checkbox" /> Mark as root cause
                    </label>
                    <button type="submit" :disabled="findingForm.processing" class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60 w-fit">
                        Add Finding
                    </button>
                </form>
            </div>

            <div v-if="investigation.status.value === 'completed'" class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-xs">
                <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">Conclusion</span>
                <p class="font-body-md text-body-md text-on-surface">{{ investigation.conclusion }}</p>
                <span class="font-code-tabular text-body-sm text-outline">Completed {{ formatDate(investigation.completed_at) }}</span>
            </div>

            <div v-else-if="can.completeInvestigation" class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-sm">
                <h2 class="font-title-lg text-title-lg text-primary font-bold">Complete Investigation</h2>
                <label class="font-label-md text-label-md text-on-surface font-semibold" for="conclusion">Conclusion</label>
                <textarea id="conclusion" v-model="completeForm.conclusion" rows="3" class="w-full p-3 rounded-lg bg-surface-container-low" />
                <span v-if="completeForm.errors.conclusion" class="font-body-sm text-body-sm text-error">{{ completeForm.errors.conclusion }}</span>
                <button
                    type="button"
                    :disabled="completeForm.processing"
                    class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60 w-fit"
                    @click="showCompleteConfirm = true"
                >
                    Complete Investigation
                </button>
            </div>
        </template>

        <ConfirmationDialog
            :show="showCompleteConfirm"
            title="Complete this investigation?"
            message="This moves the incident forward to Corrective & Preventive Actions. Findings can no longer be edited afterward."
            confirm-label="Complete Investigation"
            :processing="completeForm.processing"
            @cancel="showCompleteConfirm = false"
            @confirm="completeInvestigation"
        />
    </div>
</template>
