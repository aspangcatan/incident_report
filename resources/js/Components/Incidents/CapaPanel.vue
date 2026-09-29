<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { formatDate } from '@/Utils/formatDate';

const props = defineProps({
    incident: { type: Object, required: true },
    correctiveActions: { type: Array, default: () => [] },
    investigationFindings: { type: Array, default: () => [] },
    potentialResponsibleUsers: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

const actionTypeOptions = { corrective: 'Corrective', preventive: 'Preventive', both: 'Corrective & Preventive' };
const priorityOptions = { low: 'Low', medium: 'Medium', high: 'High', critical: 'Critical' };

const showCreateForm = ref(false);
// The incident's department owns its CAPAs, so it is the default
// (when it is one of the selectable departments).
const defaultResponsibleDepartmentId = props.departments.some((department) => department.id === props.incident.department_id)
    ? props.incident.department_id
    : null;
const createForm = useForm({
    description: '', action_type: 'corrective', priority: 'medium', due_date: '',
    responsible_user_id: null, responsible_department_id: defaultResponsibleDepartmentId, root_cause_finding_id: null,
});

function createCorrectiveAction() {
    createForm.post(`/incidents/${props.incident.id}/corrective-actions`, {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset();
            showCreateForm.value = false;
        },
    });
}

const editingId = ref(null);
const editForm = useForm({
    description: '', action_type: 'corrective', priority: 'medium', due_date: '',
    responsible_user_id: null, responsible_department_id: null, root_cause_finding_id: null,
});

function startEditing(action) {
    editingId.value = action.id;
    editForm.description = action.description;
    editForm.action_type = action.action_type.value;
    editForm.priority = action.priority.value;
    editForm.due_date = action.due_date;
    editForm.responsible_user_id = action.responsible_user?.id ?? null;
    editForm.responsible_department_id = action.responsible_department?.id ?? null;
    editForm.root_cause_finding_id = action.root_cause_finding_id;
}

function saveEdit(actionId) {
    editForm.patch(`/corrective-actions/${actionId}`, {
        preserveScroll: true,
        onSuccess: () => (editingId.value = null),
    });
}

const progressForm = useForm({});

function markInProgress(actionId) {
    progressForm.post(`/corrective-actions/${actionId}/progress`, { preserveScroll: true });
}

const completingId = ref(null);
const completeForm = useForm({ completion_notes: '' });

function completeCorrectiveAction(actionId) {
    completeForm.post(`/corrective-actions/${actionId}/complete`, {
        preserveScroll: true,
        onSuccess: () => {
            completeForm.reset();
            completingId.value = null;
        },
    });
}

const verifyingId = ref(null);
const verifyForm = useForm({ verification_comments: '' });

function verifyCorrectiveAction(actionId) {
    verifyForm.post(`/corrective-actions/${actionId}/verify`, {
        preserveScroll: true,
        onSuccess: () => {
            verifyForm.reset();
            verifyingId.value = null;
        },
    });
}
</script>

<template>
    <div class="flex flex-col gap-space-lg">
        <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
            <div class="flex flex-wrap items-center justify-between gap-space-sm">
                <h2 class="font-title-lg text-title-lg text-primary font-bold">Corrective &amp; Preventive Actions</h2>
                <button
                    v-if="can.createCorrectiveAction"
                    type="button"
                    class="px-3 py-1.5 rounded-lg bg-secondary text-on-secondary font-label-md text-label-md font-semibold"
                    @click="showCreateForm = !showCreateForm"
                >
                    {{ showCreateForm ? 'Cancel' : 'Add Action Item' }}
                </button>
            </div>

            <form v-if="showCreateForm" class="flex flex-col gap-space-md p-space-md rounded-lg bg-surface-container-low" @submit.prevent="createCorrectiveAction">
                <h3 class="font-title-sm text-title-sm text-on-surface font-semibold">New action item</h3>

                <div class="flex flex-col gap-1">
                    <label for="capa_description" class="font-label-md text-label-md text-on-surface font-semibold">What needs to be done? *</label>
                    <p class="font-body-sm text-body-sm text-outline">Describe the specific task that fixes the problem or stops it from happening again.</p>
                    <textarea id="capa_description" v-model="createForm.description" rows="2" placeholder="e.g. Install non-slip mats at the server room entrance" class="p-2 rounded-lg bg-surface-container" />
                    <span v-if="createForm.errors.description" class="font-body-sm text-body-sm text-error">{{ createForm.errors.description }}</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-md">
                    <div class="flex flex-col gap-1">
                        <label for="capa_action_type" class="font-label-md text-label-md text-on-surface font-semibold">Type of action *</label>
                        <p class="font-body-sm text-body-sm text-outline">Corrective = fixes this incident. Preventive = stops it happening again.</p>
                        <select id="capa_action_type" v-model="createForm.action_type" class="p-2 rounded-lg bg-surface-container">
                            <option v-for="(label, value) in actionTypeOptions" :key="value" :value="value">{{ label }}</option>
                        </select>
                        <span v-if="createForm.errors.action_type" class="font-body-sm text-body-sm text-error">{{ createForm.errors.action_type }}</span>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="capa_priority" class="font-label-md text-label-md text-on-surface font-semibold">Priority *</label>
                        <p class="font-body-sm text-body-sm text-outline">How urgently this needs to be done.</p>
                        <select id="capa_priority" v-model="createForm.priority" class="p-2 rounded-lg bg-surface-container">
                            <option v-for="(label, value) in priorityOptions" :key="value" :value="value">{{ label }}</option>
                        </select>
                        <span v-if="createForm.errors.priority" class="font-body-sm text-body-sm text-error">{{ createForm.errors.priority }}</span>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="capa_due_date" class="font-label-md text-label-md text-on-surface font-semibold">Due date *</label>
                        <p class="font-body-sm text-body-sm text-outline">When this must be finished. It is flagged overdue after this date.</p>
                        <input id="capa_due_date" v-model="createForm.due_date" type="date" class="p-2 rounded-lg bg-surface-container" />
                        <span v-if="createForm.errors.due_date" class="font-body-sm text-body-sm text-error">{{ createForm.errors.due_date }}</span>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="capa_responsible_user" class="font-label-md text-label-md text-on-surface font-semibold">Assigned to</label>
                        <p class="font-body-sm text-body-sm text-outline">The staff member who will do this task. They mark it in progress and complete.</p>
                        <select id="capa_responsible_user" v-model="createForm.responsible_user_id" class="p-2 rounded-lg bg-surface-container">
                            <option :value="null">Not assigned yet</option>
                            <option v-for="option in potentialResponsibleUsers" :key="option.id" :value="option.id">{{ option.label }}</option>
                        </select>
                        <span v-if="createForm.errors.responsible_user_id" class="font-body-sm text-body-sm text-error">{{ createForm.errors.responsible_user_id }}</span>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="capa_responsible_department" class="font-label-md text-label-md text-on-surface font-semibold">Responsible department</label>
                        <p class="font-body-sm text-body-sm text-outline">The unit accountable for this task (usually your own).</p>
                        <select id="capa_responsible_department" v-model="createForm.responsible_department_id" class="p-2 rounded-lg bg-surface-container">
                            <option :value="null">None</option>
                            <option v-for="option in departments" :key="option.id" :value="option.id">{{ option.name }}</option>
                        </select>
                        <span v-if="createForm.errors.responsible_department_id" class="font-body-sm text-body-sm text-error">{{ createForm.errors.responsible_department_id }}</span>
                    </div>

                    <div v-if="investigationFindings.length" class="flex flex-col gap-1">
                        <label for="capa_root_cause" class="font-label-md text-label-md text-on-surface font-semibold">Addresses which finding? (optional)</label>
                        <p class="font-body-sm text-body-sm text-outline">Link this task to the investigation finding it fixes.</p>
                        <select id="capa_root_cause" v-model="createForm.root_cause_finding_id" class="p-2 rounded-lg bg-surface-container">
                            <option :value="null">Not linked to a specific finding</option>
                            <option v-for="finding in investigationFindings" :key="finding.id" :value="finding.id">{{ finding.finding }}</option>
                        </select>
                        <span v-if="createForm.errors.root_cause_finding_id" class="font-body-sm text-body-sm text-error">{{ createForm.errors.root_cause_finding_id }}</span>
                    </div>
                </div>

                <button type="submit" :disabled="createForm.processing" class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60 w-fit">
                    Add Action Item
                </button>
            </form>

            <div v-if="!correctiveActions.length" class="text-center font-body-sm text-body-sm text-outline p-space-md">
                No corrective actions yet.
            </div>

            <div v-for="action in correctiveActions" :key="action.id" class="p-space-md rounded-xl bg-surface-container-low flex flex-col gap-3">
                <template v-if="editingId === action.id">
                    <div class="flex flex-col gap-1">
                        <label :for="'edit_description_' + action.id" class="font-label-md text-label-md text-on-surface font-semibold">What needs to be done? *</label>
                        <textarea :id="'edit_description_' + action.id" v-model="editForm.description" rows="2" class="p-2 rounded-lg bg-surface-container" />
                        <span v-if="editForm.errors.description" class="font-body-sm text-body-sm text-error">{{ editForm.errors.description }}</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-md">
                        <div class="flex flex-col gap-1">
                            <label :for="'edit_type_' + action.id" class="font-label-md text-label-md text-on-surface font-semibold">Type of action *</label>
                            <select :id="'edit_type_' + action.id" v-model="editForm.action_type" class="p-2 rounded-lg bg-surface-container">
                                <option v-for="(label, value) in actionTypeOptions" :key="value" :value="value">{{ label }}</option>
                            </select>
                        </div>
                        <div class="flex flex-col gap-1">
                            <label :for="'edit_priority_' + action.id" class="font-label-md text-label-md text-on-surface font-semibold">Priority *</label>
                            <select :id="'edit_priority_' + action.id" v-model="editForm.priority" class="p-2 rounded-lg bg-surface-container">
                                <option v-for="(label, value) in priorityOptions" :key="value" :value="value">{{ label }}</option>
                            </select>
                        </div>
                        <div class="flex flex-col gap-1">
                            <label :for="'edit_due_' + action.id" class="font-label-md text-label-md text-on-surface font-semibold">Due date *</label>
                            <input :id="'edit_due_' + action.id" v-model="editForm.due_date" type="date" class="p-2 rounded-lg bg-surface-container" />
                            <span v-if="editForm.errors.due_date" class="font-body-sm text-body-sm text-error">{{ editForm.errors.due_date }}</span>
                        </div>
                        <div class="flex flex-col gap-1">
                            <label :for="'edit_assignee_' + action.id" class="font-label-md text-label-md text-on-surface font-semibold">Assigned to</label>
                            <select :id="'edit_assignee_' + action.id" v-model="editForm.responsible_user_id" class="p-2 rounded-lg bg-surface-container">
                                <option :value="null">Not assigned yet</option>
                                <option v-for="option in potentialResponsibleUsers" :key="option.id" :value="option.id">{{ option.label }}</option>
                            </select>
                            <span v-if="editForm.errors.responsible_user_id" class="font-body-sm text-body-sm text-error">{{ editForm.errors.responsible_user_id }}</span>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" class="px-3 py-1.5 rounded-lg bg-primary text-on-primary font-label-sm text-body-sm" @click="saveEdit(action.id)">Save</button>
                        <button type="button" class="px-3 py-1.5 rounded-lg bg-surface-container font-label-sm text-body-sm" @click="editingId = null">Cancel</button>
                    </div>
                </template>

                <template v-else>
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div class="flex flex-col gap-1 max-w-xl">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="px-2 py-0.5 rounded-full bg-error-container text-on-error-container font-code-tabular text-body-sm font-bold">{{ action.capa_number }}</span>
                                <span class="px-2 py-0.5 rounded-full bg-primary-container text-on-primary font-label-sm text-body-sm">Priority: {{ action.priority.label }}</span>
                                <span class="font-code-tabular text-body-sm" :class="action.is_overdue ? 'text-error font-bold' : 'text-outline'">
                                    Due: {{ formatDate(action.due_date) }}<template v-if="action.is_overdue"> (Overdue)</template>
                                </span>
                            </div>
                            <h4 class="font-title-sm text-title-sm text-on-surface font-semibold">{{ action.description }}</h4>
                            <p v-if="action.responsible_user || action.responsible_department" class="font-body-sm text-body-sm text-outline">
                                Owner: {{ action.responsible_user?.name }}<template v-if="action.responsible_user && action.responsible_department"> &middot; </template>{{ action.responsible_department?.name }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="px-3 py-1 rounded-full bg-secondary-container text-on-secondary-container font-label-sm text-body-sm font-semibold">
                                {{ action.status.label }}
                            </span>
                            <button v-if="action.can.progress" type="button" class="px-3 py-1.5 rounded-lg bg-surface-container text-primary font-label-md text-label-md" @click="markInProgress(action.id)">
                                Start Work
                            </button>
                            <span v-if="action.can.progress" class="font-body-sm text-body-sm text-outline">Start work first; then you can mark it complete.</span>
                            <button v-if="action.can.complete" type="button" class="px-3 py-1.5 rounded-lg bg-primary text-on-primary font-label-md text-label-md" @click="completingId = action.id">
                                Mark Complete
                            </button>
                            <button v-if="action.can.verify" type="button" class="px-3 py-1.5 rounded-lg bg-primary text-on-primary font-label-md text-label-md" @click="verifyingId = action.id">
                                Verify Action
                            </button>
                            <button v-if="action.can.update" type="button" class="font-label-sm text-body-sm text-primary" @click="startEditing(action)">Edit</button>
                        </div>
                    </div>

                    <div v-if="completingId === action.id" class="flex flex-col gap-2 p-space-sm rounded-lg bg-surface-container">
                        <label :for="'completion_notes_' + action.id" class="font-label-md text-label-md text-on-surface font-semibold">What was done? *</label>
                        <textarea :id="'completion_notes_' + action.id" v-model="completeForm.completion_notes" rows="2" placeholder="Describe how the task was completed" class="p-2 rounded-lg bg-surface-container-low" />
                        <span v-if="completeForm.errors.completion_notes" class="font-body-sm text-body-sm text-error">{{ completeForm.errors.completion_notes }}</span>
                        <div class="flex gap-2">
                            <button type="button" class="px-3 py-1.5 rounded-lg bg-primary text-on-primary font-label-sm text-body-sm" @click="completeCorrectiveAction(action.id)">Submit</button>
                            <button type="button" class="px-3 py-1.5 rounded-lg bg-surface-container-lowest font-label-sm text-body-sm" @click="completingId = null">Cancel</button>
                        </div>
                    </div>

                    <div v-if="verifyingId === action.id" class="flex flex-col gap-2 p-space-sm rounded-lg bg-surface-container">
                        <p v-if="action.completion_notes" class="font-body-sm text-body-sm text-on-surface-variant">Completion notes: {{ action.completion_notes }}</p>
                        <label :for="'verification_comments_' + action.id" class="font-label-md text-label-md text-on-surface font-semibold">Verification comments *</label>
                        <textarea :id="'verification_comments_' + action.id" v-model="verifyForm.verification_comments" rows="2" placeholder="How you checked that the task was really done" class="p-2 rounded-lg bg-surface-container-low" />
                        <span v-if="verifyForm.errors.verification_comments" class="font-body-sm text-body-sm text-error">{{ verifyForm.errors.verification_comments }}</span>
                        <div class="flex gap-2">
                            <button type="button" class="px-3 py-1.5 rounded-lg bg-primary text-on-primary font-label-sm text-body-sm" @click="verifyCorrectiveAction(action.id)">Submit</button>
                            <button type="button" class="px-3 py-1.5 rounded-lg bg-surface-container-lowest font-label-sm text-body-sm" @click="verifyingId = null">Cancel</button>
                        </div>
                    </div>

                    <div v-if="action.status.value === 'verified'" class="text-body-sm font-body-sm text-on-surface-variant">
                        Verified by {{ action.verified_by?.name }} on {{ formatDate(action.verified_at) }}: "{{ action.verification_comments }}"
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
