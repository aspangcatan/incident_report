<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ConfirmationDialog from '@/Components/ConfirmationDialog.vue';
import SeverityBadge from '@/Components/SeverityBadge.vue';

const props = defineProps({
    incident: { type: Object, required: true },
    can: { type: Object, required: true },
    departments: { type: Array, default: () => [] },
});

const severities = [
    { value: 'level_1_low', label: 'Low Risk', numeral: 'Level I' },
    { value: 'level_2_moderate', label: 'Moderate Risk', numeral: 'Level II' },
    { value: 'level_3_high', label: 'High Severity', numeral: 'Level III' },
    { value: 'level_4_critical_sentinel', label: 'Critical / Sentinel', numeral: 'Level IV' },
];

const actionStatuses = [
    { value: 'pending', label: 'Pending' },
    { value: 'completed', label: 'Completed' },
];

let nextKey = 0;
const toRow = (action) => ({
    _key: nextKey++,
    description: action.description ?? '',
    responsible_name: action.responsible_name ?? '',
    performed_at: action.performed_at ? action.performed_at.slice(0, 16) : '',
    status: action.status ?? 'pending',
});

const editable = computed(() => props.incident.status === 'submitted' && props.can.assess);

const form = useForm({
    actions_taken: (props.incident.actions ?? []).map(toRow),
    recommendations: props.incident.recommendations ?? '',
    severity: props.incident.severity ?? null,
    department_id: props.incident.department_id ?? null,
});

const returnForm = useForm({ comments: '' });
const showCompleteConfirm = ref(false);
const showReturnConfirm = ref(false);

function addAction() {
    form.actions_taken.push(toRow({}));
}

function removeAction(index) {
    form.actions_taken.splice(index, 1);
}

function payload(data) {
    return { ...data, actions_taken: data.actions_taken.map(({ _key, ...row }) => row) };
}

function save() {
    form.transform(payload).post(`/incidents/${props.incident.id}/assessment`, { preserveScroll: true });
}

function complete() {
    form.transform(payload).post(`/incidents/${props.incident.id}/assessment/complete`, {
        preserveScroll: true,
        onFinish: () => (showCompleteConfirm.value = false),
    });
}

function confirmReturn() {
    if (!returnForm.comments.trim()) {
        returnForm.setError('comments', 'A comment is required when returning the report to the reporter.');
        return;
    }
    showReturnConfirm.value = true;
}

function returnToReporter() {
    returnForm.post(`/incidents/${props.incident.id}/return`, {
        onFinish: () => (showReturnConfirm.value = false),
    });
}
</script>

<template>
    <section class="flex flex-col gap-space-md">
        <div class="flex items-center justify-between">
            <h2 class="font-title-lg text-title-lg text-primary font-bold">Department Assessment</h2>
            <span v-if="incident.assessed_at && incident.status !== 'submitted'" class="font-body-sm text-body-sm text-outline">
                Assessed by {{ incident.assessor?.name ?? 'a former user' }} on {{ new Date(incident.assessed_at).toLocaleString() }}
            </span>
        </div>

        <!-- Severity -->
        <div class="flex flex-col gap-1.5">
            <span class="font-label-md text-label-md text-on-surface font-semibold">Severity</span>
            <div v-if="editable && can.completeAssessment" class="grid grid-cols-2 md:grid-cols-4 gap-2">
                <label
                    v-for="option in severities"
                    :key="option.value"
                    class="p-3 rounded-lg cursor-pointer text-center"
                    :class="form.severity === option.value ? 'bg-amber-50 ring-2 ring-amber-500' : 'bg-surface-container-low hover:bg-surface-container'"
                >
                    <input v-model="form.severity" type="radio" :value="option.value" class="hidden" />
                    <span class="block font-label-sm text-body-sm text-outline">{{ option.numeral }}</span>
                    <span class="block font-body-md text-body-md text-on-surface font-semibold">{{ option.label }}</span>
                </label>
            </div>
            <SeverityBadge v-else :severity="incident.severity" class="self-start" />
            <span v-if="form.errors.severity" class="font-body-sm text-body-sm text-error">{{ form.errors.severity }}</span>
        </div>

        <!-- Department (QSO/Admin) -->
        <div v-if="editable && can.changeDepartment" class="flex flex-col gap-1.5">
            <label for="assessment_department" class="font-label-md text-label-md text-on-surface font-semibold">Department</label>
            <select id="assessment_department" v-model="form.department_id" class="w-full md:w-1/2 p-3 rounded-lg bg-surface-container-low">
                <option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option>
            </select>
            <span v-if="form.errors.department_id" class="font-body-sm text-body-sm text-error">{{ form.errors.department_id }}</span>
        </div>

        <!-- Immediate actions -->
        <div class="flex flex-col gap-2">
            <div class="flex items-center justify-between">
                <span class="font-label-md text-label-md text-on-surface font-semibold">Immediate Actions Taken</span>
                <button v-if="editable" type="button" class="font-label-sm text-label-sm font-bold text-primary" @click="addAction">+ Add Action</button>
            </div>

            <p v-if="!editable && !(incident.actions ?? []).length" class="font-body-sm text-body-sm text-outline">No immediate actions recorded.</p>

            <template v-if="editable">
                <div v-for="(action, index) in form.actions_taken" :key="action._key" class="p-3 rounded-lg bg-surface-container-low flex flex-col gap-2">
                    <div class="flex items-center justify-between">
                        <span class="font-label-sm text-label-sm uppercase text-outline">Action {{ index + 1 }}</span>
                        <button type="button" class="text-error font-label-sm text-body-sm" @click="removeAction(index)">Remove</button>
                    </div>
                    <label class="sr-only" :for="'assessment-action-' + index">Intervention taken</label>
                    <textarea :id="'assessment-action-' + index" v-model="action.description" rows="2" placeholder="Intervention taken" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                    <span v-if="form.errors[`actions_taken.${index}.description`]" class="font-body-sm text-body-sm text-error">{{ form.errors[`actions_taken.${index}.description`] }}</span>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                        <label class="sr-only" :for="'assessment-responsible-' + index">Responsible officer</label>
                        <input :id="'assessment-responsible-' + index" v-model="action.responsible_name" type="text" placeholder="Responsible officer" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                        <label class="sr-only" :for="'assessment-performed-' + index">Date and time performed</label>
                        <input :id="'assessment-performed-' + index" v-model="action.performed_at" type="datetime-local" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                        <label class="sr-only" :for="'assessment-status-' + index">Status</label>
                        <select :id="'assessment-status-' + index" v-model="action.status" class="p-2.5 rounded-lg bg-surface-container-lowest">
                            <option v-for="status in actionStatuses" :key="status.value" :value="status.value">{{ status.label }}</option>
                        </select>
                    </div>
                </div>
            </template>
            <ul v-else class="flex flex-col gap-2">
                <li v-for="action in incident.actions" :key="action.id" class="p-3 rounded-lg bg-surface-container-low">
                    <p class="font-body-md text-body-md text-on-surface">{{ action.description }}</p>
                    <p class="font-body-sm text-body-sm text-outline">
                        {{ action.responsible_name || '—' }} · {{ action.performed_at ? new Date(action.performed_at).toLocaleString() : '—' }} · {{ action.status }}
                    </p>
                </li>
            </ul>
        </div>

        <!-- Recommendations -->
        <div class="flex flex-col gap-1.5">
            <label for="assessment_recommendations" class="font-label-md text-label-md text-on-surface font-semibold">Recommendations</label>
            <textarea v-if="editable" id="assessment_recommendations" v-model="form.recommendations" rows="4" class="w-full p-3 rounded-lg bg-surface-container-low" placeholder="What should change to prevent this from happening again?" />
            <p v-else class="font-body-md text-body-md text-on-surface whitespace-pre-line">{{ incident.recommendations || '—' }}</p>
        </div>

        <!-- Buttons -->
        <div v-if="editable" class="flex flex-wrap gap-2">
            <button type="button" :disabled="form.processing" class="px-4 py-2 rounded-lg bg-surface-container text-on-surface font-label-md text-label-md disabled:opacity-60" @click="save">
                Save
            </button>
            <button
                v-if="can.completeAssessment"
                type="button"
                :disabled="form.processing"
                class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60"
                @click="showCompleteConfirm = true"
            >
                Complete Assessment
            </button>
        </div>

        <!-- Return to reporter -->
        <div v-if="editable && can.returnToReporter" class="flex flex-col gap-2 pt-space-sm border-t border-outline-variant">
            <label for="return_comments" class="font-label-md text-label-md text-on-surface font-semibold">Return to reporter</label>
            <textarea id="return_comments" v-model="returnForm.comments" rows="2" class="w-full p-3 rounded-lg bg-surface-container-low" placeholder="What does the reporter need to fix?" />
            <span v-if="returnForm.errors.comments" class="font-body-sm text-body-sm text-error">{{ returnForm.errors.comments }}</span>
            <button type="button" :disabled="returnForm.processing" class="self-start px-4 py-2 rounded-lg bg-surface-container text-on-surface font-label-md text-label-md disabled:opacity-60" @click="confirmReturn">
                Return to Reporter
            </button>
        </div>

        <ConfirmationDialog
            :show="showCompleteConfirm"
            title="Complete the assessment?"
            message="The incident will be sent for review. Department edits will be locked unless a reviewer returns it."
            confirm-label="Complete Assessment"
            :processing="form.processing"
            @confirm="complete"
            @cancel="showCompleteConfirm = false"
        />
        <ConfirmationDialog
            :show="showReturnConfirm"
            title="Return to the reporter?"
            message="The report goes back to the reporter as a draft."
            confirm-label="Return to Reporter"
            :processing="returnForm.processing"
            @confirm="returnToReporter"
            @cancel="showReturnConfirm = false"
        />
    </section>
</template>
