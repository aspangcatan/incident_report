<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ConfirmationDialog from '@/Components/ConfirmationDialog.vue';
import { formatDate } from '@/Utils/formatDate';
import Step1ReporterInfo from '@/Components/Incidents/Step1ReporterInfo.vue';
import Step2IncidentDetails from '@/Components/Incidents/Step2IncidentDetails.vue';
import Step3InjuryDetails from '@/Components/Incidents/Step3InjuryDetails.vue';
import Step3PeopleInvolved from '@/Components/Incidents/Step3PeopleInvolved.vue';
import Step4WitnessesPolice from '@/Components/Incidents/Step4WitnessesPolice.vue';
import Step5Description from '@/Components/Incidents/Step5Description.vue';
import Step8Review from '@/Components/Incidents/Step8Review.vue';

const props = defineProps({
    incident: { type: Object, default: null },
    incidentTypes: { type: Array, required: true },
    departments: { type: Array, required: true },
    injuryOptions: { type: Object, required: true },
});

const steps = [
    { title: 'Reporter Info', component: Step1ReporterInfo },
    { title: 'Incident Details', component: Step2IncidentDetails },
    { title: 'Injury Details', component: Step3InjuryDetails },
    { title: 'People Involved', component: Step3PeopleInvolved },
    { title: 'Witnesses & Police', component: Step4WitnessesPolice },
    { title: 'Description', component: Step5Description },
    { title: 'Review & Submit', component: Step8Review },
];

const currentStep = ref(1);
const showConfirm = ref(false);

const STEP_FIELDS = {
    1: ['legal_attestation'],
    2: ['incident_type_ids', 'incident_type_other', 'department_id', 'occurred_at', 'location'],
    3: ['has_injury', 'injury_causes', 'injury_cause_other', 'injury_agents', 'injury_agent_other', 'injury_chemical_details'],
    4: ['individuals'],
    5: ['witnesses', 'police_notified', 'police_station', 'police_officer_in_charge', 'police_blotter_no', 'police_notified_at'],
    6: ['summary', 'narrative_events', 'attachments'],
};

function firstStepWithError() {
    const errorFields = Object.keys(form.errors);
    if (errorFields.length === 0) return null;

    for (const [step, fields] of Object.entries(STEP_FIELDS)) {
        if (fields.some((field) => errorFields.some((errorField) => errorField === field || errorField.startsWith(field + '.')))) {
            return Number(step);
        }
    }

    return 1;
}

const form = useForm({
    action: 'draft',
    incident_type_ids: props.incident?.incident_types?.map((type) => type.id) ?? [],
    incident_type_other: props.incident?.incident_type_other ?? '',
    department_id: props.incident?.department_id ?? null,
    occurred_at: props.incident?.occurred_at?.slice(0, 16) ?? '',
    location: props.incident?.location ?? '',
    has_injury: props.incident?.has_injury ?? null,
    injury_causes: props.incident?.injury_causes ?? [],
    injury_cause_other: props.incident?.injury_cause_other ?? '',
    injury_agents: props.incident?.injury_agents ?? [],
    injury_agent_other: props.incident?.injury_agent_other ?? '',
    injury_chemical_details: props.incident?.injury_chemical_details ?? '',
    summary: props.incident?.summary ?? '',
    legal_attestation: !!props.incident?.legal_attestation_at,
    police_notified: props.incident?.police_notified ?? false,
    police_station: props.incident?.police_station ?? '',
    police_officer_in_charge: props.incident?.police_officer_in_charge ?? '',
    police_blotter_no: props.incident?.police_blotter_no ?? '',
    police_notified_at: props.incident?.police_notified_at?.slice(0, 16) ?? '',
    individuals: props.incident?.individuals ?? [],
    witnesses: props.incident?.witnesses ?? [],
    narrative_events: props.incident?.narrative_events ?? [],
    attachments: [],
});

const isLastStep = computed(() => currentStep.value === steps.length);

const blank = (value) => value === null || value === undefined || String(value).trim() === '';

// What a step needs before Next (the Section 1 attestation is checked only on Submit).
// Messages match the server's (ValidatesIncidentData).
function stepErrors(step) {
    const errors = {};

    if (step === 2) {
        if (form.incident_type_ids.length === 0 && blank(form.incident_type_other)) {
            errors.incident_type_ids = 'Choose at least one incident type, or tick Others and specify it.';
        }
        if (blank(form.department_id)) errors.department_id = 'Choose the department or clinical unit.';
        if (blank(form.occurred_at)) errors.occurred_at = 'Enter the date and time of the incident.';
        if (blank(form.location)) errors.location = 'Enter where the incident happened.';
    }

    if (step === 3) {
        if (form.has_injury === null) errors.has_injury = 'Answer whether anyone was injured.';
        if (form.has_injury) {
            if (form.injury_causes.length === 0 && blank(form.injury_cause_other)) {
                errors.injury_causes = 'Choose at least one cause of injury, or tick Others and specify it.';
            }
            if (form.injury_agents.length === 0 && blank(form.injury_agent_other)) {
                errors.injury_agents = 'Choose at least one agent of injury, or tick Others and specify it.';
            }
            if (form.injury_agents.includes('chemicals') && blank(form.injury_chemical_details)) {
                errors.injury_chemical_details = 'Say which chemical was involved.';
            }
        }
    }

    if (step === 4) {
        form.individuals.forEach((person, i) => {
            if (blank(person.name)) errors[`individuals.${i}.name`] = `Enter the full name of person ${i + 1}.`;
        });
    }

    if (step === 5) {
        form.witnesses.forEach((witness, i) => {
            if (blank(witness.name)) errors[`witnesses.${i}.name`] = `Enter the full name of witness ${i + 1}.`;
        });
    }

    if (step === 6) {
        if (blank(form.summary)) errors.summary = 'Describe what happened.';
        form.narrative_events.forEach((event, i) => {
            if (blank(event.description)) errors[`narrative_events.${i}.description`] = `Describe what happened in event ${i + 1}, or remove it.`;
        });
    }

    return errors;
}

function belongsToStep(errorField, step) {
    return (STEP_FIELDS[step] ?? []).some((field) => errorField === field || errorField.startsWith(field + '.'));
}

/** Re-checks a step; shows its problems and returns false if it is not complete. */
function checkStep(step) {
    const stale = Object.keys(form.errors).filter((field) => belongsToStep(field, step));
    if (stale.length) form.clearErrors(...stale);

    const errors = stepErrors(step);
    if (Object.keys(errors).length === 0) return true;

    form.setError(errors);
    currentStep.value = step;
    window.scrollTo({ top: 0, behavior: 'smooth' });
    return false;
}

/** Moving forward checks every step being passed; moving back is always allowed. */
function goTo(target) {
    for (let step = currentStep.value; step < target; step++) {
        if (!checkStep(step)) return;
    }
    currentStep.value = target;
}

function next() {
    if (currentStep.value < steps.length) goTo(currentStep.value + 1);
}

function back() {
    if (currentStep.value > 1) currentStep.value -= 1;
}

function targetUrl() {
    return props.incident ? `/incidents/${props.incident.id}` : '/incidents';
}

function submitAs(action) {
    form.action = action;
    const options = {
        preserveScroll: true,
        onFinish: () => (showConfirm.value = false),
        onError: () => {
            const step = firstStepWithError();
            if (step !== null) currentStep.value = step;
        },
    };

    if (props.incident) {
        form.transform((data) => ({ ...data, _method: 'patch' })).post(targetUrl(), options);
    } else {
        form.post(targetUrl(), options);
    }
}

function saveDraft() {
    submitAs('draft');
}

function confirmSubmit() {
    showConfirm.value = true;
}
</script>

<template>
    <Head title="Report an Incident" />

    <AuthenticatedLayout>
        <div class="flex items-center gap-2 overflow-x-auto pb-1">
            <button
                v-for="(step, index) in steps"
                :key="step.title"
                type="button"
                class="px-3 py-2 rounded-lg font-label-sm text-body-sm whitespace-nowrap"
                :class="currentStep === index + 1 ? 'bg-primary text-on-primary font-semibold' : 'bg-surface-container text-on-surface-variant'"
                @click="goTo(index + 1)"
            >
                {{ index + 1 }}. {{ step.title }}
            </button>
        </div>

        <div
            v-if="incident?.supervisor_comments"
            role="note"
            class="rounded-lg bg-amber-50 text-amber-900 p-space-md flex flex-col gap-1 border-l-4 border-amber-500"
        >
            <span class="font-title-sm text-title-sm font-semibold flex items-center gap-2">
                <FontAwesomeIcon icon="triangle-exclamation" />
                Returned for revision
                <span class="font-body-sm text-body-sm font-normal">
                    by {{ incident.supervisor_reviewer?.name ?? 'the department' }} on {{ formatDate(incident.supervisor_reviewed_at) }}
                </span>
            </span>
            <p class="font-body-md text-body-md whitespace-pre-line">"{{ incident.supervisor_comments }}"</p>
            <span class="font-body-sm text-body-sm">Fix the report, then submit it again from the last step.</span>
        </div>

        <div
            v-if="form.hasErrors"
            class="rounded-lg bg-error-container text-on-error-container p-space-md flex flex-col gap-1"
        >
            <span class="font-title-sm text-title-sm font-semibold">Please fix the following before continuing:</span>
            <ul class="list-disc list-inside font-body-sm text-body-sm">
                <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
            </ul>
        </div>

        <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm">
            <component
                :is="steps[currentStep - 1].component"
                :form="form"
                :incident-types="incidentTypes"
                :departments="departments"
                :injury-options="injuryOptions"
                :existing-attachments="incident?.attachments ?? []"
            />
        </div>

        <div class="flex items-center justify-between">
            <button
                type="button"
                :disabled="currentStep === 1"
                class="px-4 py-2 rounded-lg bg-surface-container text-on-surface font-label-md text-label-md disabled:opacity-40"
                @click="back"
            >
                Back
            </button>

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    :disabled="form.processing"
                    class="px-4 py-2 rounded-lg bg-surface-container-high text-primary font-label-md text-label-md disabled:opacity-60"
                    @click="saveDraft"
                >
                    Save as Draft
                </button>
                <button
                    v-if="!isLastStep"
                    type="button"
                    class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold"
                    @click="next"
                >
                    Next
                </button>
                <button
                    v-else
                    type="button"
                    :disabled="form.processing"
                    class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60"
                    @click="confirmSubmit"
                >
                    Submit Report
                </button>
            </div>
        </div>

        <ConfirmationDialog
            :show="showConfirm"
            title="Submit incident report?"
            message="Once submitted, this report can no longer be edited and enters the review workflow. Make sure the legal attestation in Section 1 is checked."
            confirm-label="Submit Report"
            :processing="form.processing"
            @cancel="showConfirm = false"
            @confirm="submitAs('submit')"
        />
    </AuthenticatedLayout>
</template>
