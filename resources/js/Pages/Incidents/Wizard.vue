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
    { title: 'Reporter Info', description: 'Who is reporting, and your attestation.', icon: 'user', component: Step1ReporterInfo },
    { title: 'Incident Details', description: 'What kind of incident, when and where it happened.', icon: 'clipboard-list', component: Step2IncidentDetails },
    { title: 'Injury Details', description: 'Whether anyone was hurt, and how.', icon: 'kit-medical', component: Step3InjuryDetails },
    { title: 'People Involved', description: 'Patients, staff or visitors involved in the incident.', icon: 'user-plus', component: Step3PeopleInvolved },
    { title: 'Witnesses & Police', description: 'Anyone who saw it, and whether the police were told.', icon: 'eye', component: Step4WitnessesPolice },
    { title: 'Description', description: 'What happened, in your own words, with any evidence.', icon: 'pen-to-square', component: Step5Description },
    { title: 'Review & Submit', description: 'Check everything before you send it.', icon: 'circle-check', component: Step8Review },
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
const step = computed(() => steps[currentStep.value - 1]);

// Shown on the step list: a step with an error from the last check or save.
function stepHasError(index) {
    return Object.keys(form.errors).some((field) => belongsToStep(field, index));
}

const blank = (value) => value === null || value === undefined || String(value).trim() === '';

// What a step needs before Next. Save as Draft skips these checks, so a draft can be saved untouched.
// Messages match the server's (ValidatesIncidentData) where it has one.
function stepErrors(step) {
    const errors = {};

    if (step === 1 && !form.legal_attestation) {
        errors.legal_attestation = 'Tick the Electronic Acknowledgment box to continue.';
    }

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
        <!-- Banner -->
        <section class="relative overflow-hidden rounded-2xl bg-primary text-on-primary shadow-sm">
            <img src="/images/landing/report-nurse-patient.jpg" alt="" class="absolute inset-y-0 right-0 h-full w-1/2 object-cover object-[70%_30%] hidden md:block" />
            <div class="absolute inset-0 bg-gradient-to-r from-primary via-primary md:via-55% to-primary/30" />
            <div class="relative p-6 md:p-8 flex flex-col gap-3 max-w-2xl">
                <span class="inline-flex w-fit items-center gap-2 px-3 py-1 rounded-full bg-white/15 font-label-sm text-label-sm uppercase">
                    <FontAwesomeIcon icon="shield-halved" /> {{ incident ? 'Draft report' : 'New incident report' }}
                </span>
                <h1 class="font-headline-xl text-headline-xl-mobile md:text-headline-xl font-bold tracking-tight">Report an incident</h1>
                <p class="font-body-lg text-body-lg text-white/85">
                    Seven short steps. You can save a draft at any time and finish it later from Draft Reports.
                </p>
            </div>
        </section>

        <div
            v-if="incident?.supervisor_comments"
            role="note"
            class="rounded-xl bg-amber-50 text-amber-900 p-4 flex flex-col gap-1 border-l-4 border-amber-500"
        >
            <span class="font-title-sm text-title-sm font-semibold flex flex-wrap items-center gap-2">
                <FontAwesomeIcon icon="triangle-exclamation" />
                Returned for revision
                <span class="font-body-sm text-body-sm font-normal">
                    by {{ incident.supervisor_reviewer?.name ?? 'the department' }} on {{ formatDate(incident.supervisor_reviewed_at) }}
                </span>
            </span>
            <p class="font-body-md text-body-md whitespace-pre-line">"{{ incident.supervisor_comments }}"</p>
            <span class="font-body-sm text-body-sm">Fix the report, then submit it again from the last step.</span>
        </div>

        <!-- Step list on small screens -->
        <ol class="lg:hidden flex gap-2 overflow-x-auto pb-1">
            <li v-for="(item, index) in steps" :key="item.title">
                <button
                    type="button"
                    class="flex items-center gap-2 px-3 py-2 rounded-lg font-label-md text-label-md whitespace-nowrap ring-1"
                    :class="currentStep === index + 1
                        ? 'bg-primary text-on-primary ring-primary font-semibold'
                        : stepHasError(index + 1)
                          ? 'bg-error-container text-on-error-container ring-error/40'
                          : 'bg-surface-container-lowest text-on-surface-variant ring-outline-variant/60'"
                    @click="goTo(index + 1)"
                >
                    {{ index + 1 }}. {{ item.title }}
                </button>
            </li>
        </ol>

        <div class="grid lg:grid-cols-[16rem_minmax(0,1fr)] gap-6 items-start">
            <!-- Step list on large screens -->
            <nav class="hidden lg:block lg:sticky lg:top-24 rounded-2xl bg-surface-container-lowest p-3 shadow-sm ring-1 ring-outline-variant/40" aria-label="Report steps">
                <ol class="flex flex-col gap-1">
                    <li v-for="(item, index) in steps" :key="item.title">
                        <button
                            type="button"
                            class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-left transition-colors"
                            :class="currentStep === index + 1 ? 'bg-primary-fixed' : 'hover:bg-surface-container-low'"
                            :aria-current="currentStep === index + 1 ? 'step' : undefined"
                            @click="goTo(index + 1)"
                        >
                            <span
                                class="h-8 w-8 shrink-0 rounded-full flex items-center justify-center font-label-md text-label-md font-bold"
                                :class="stepHasError(index + 1)
                                    ? 'bg-error text-on-error'
                                    : currentStep === index + 1
                                      ? 'bg-primary text-on-primary'
                                      : index + 1 < currentStep
                                        ? 'bg-secondary text-on-secondary'
                                        : 'bg-surface-container text-on-surface-variant'"
                            >
                                <FontAwesomeIcon v-if="stepHasError(index + 1)" icon="triangle-exclamation" />
                                <FontAwesomeIcon v-else-if="index + 1 < currentStep" icon="circle-check" />
                                <template v-else>{{ index + 1 }}</template>
                            </span>
                            <span class="font-label-md text-label-md" :class="currentStep === index + 1 ? 'text-primary font-semibold' : 'text-on-surface'">{{ item.title }}</span>
                        </button>
                    </li>
                </ol>
            </nav>

            <div class="flex flex-col gap-4 min-w-0">
                <div v-if="form.hasErrors" role="alert" class="rounded-xl bg-error-container text-on-error-container p-4 flex items-start gap-3">
                    <FontAwesomeIcon icon="circle-exclamation" class="mt-1" />
                    <div class="flex flex-col gap-1">
                        <span class="font-title-sm text-title-sm font-semibold">Please fix the following before continuing:</span>
                        <ul class="list-disc list-inside font-body-md text-body-md">
                            <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
                        </ul>
                    </div>
                </div>

                <section class="rounded-2xl bg-surface-container-lowest p-5 sm:p-6 shadow-sm ring-1 ring-outline-variant/40 flex flex-col gap-5">
                    <header class="flex items-center gap-3">
                        <span class="h-10 w-10 shrink-0 rounded-full bg-primary text-on-primary font-bold flex items-center justify-center">{{ currentStep }}</span>
                        <div class="min-w-0">
                            <h2 class="font-title-lg text-title-lg text-primary font-bold">{{ step.title }}</h2>
                            <p class="font-body-sm text-body-sm text-outline">Step {{ currentStep }} of {{ steps.length }} · {{ step.description }}</p>
                        </div>
                    </header>

                    <component
                        :is="step.component"
                        :form="form"
                        :incident-types="incidentTypes"
                        :departments="departments"
                        :injury-options="injuryOptions"
                        :existing-attachments="incident?.attachments ?? []"
                    />
                </section>

                <!-- Actions -->
                <div class="sticky bottom-0 z-10 -mx-4 lg:mx-0 px-4 py-3 lg:rounded-2xl bg-surface-container-lowest/95 backdrop-blur shadow-[0_-4px_16px_rgba(0,0,0,0.06)] lg:shadow-sm ring-1 ring-outline-variant/40 flex items-center justify-between gap-2">
                    <button
                        type="button"
                        :disabled="currentStep === 1"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-on-surface-variant hover:bg-surface-container font-label-md text-label-md font-semibold disabled:opacity-40 disabled:hover:bg-transparent"
                        @click="back"
                    >
                        <FontAwesomeIcon icon="chevron-right" class="rotate-180" /> Back
                    </button>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            :disabled="form.processing"
                            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-surface-container-lowest text-primary ring-1 ring-outline-variant hover:bg-surface-container-low font-label-md text-label-md font-semibold disabled:opacity-60"
                            @click="saveDraft"
                        >
                            <span><span class="hidden sm:inline">Save as </span>Draft</span>
                        </button>
                        <button
                            v-if="!isLastStep"
                            type="button"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-primary text-on-primary hover:bg-primary-container font-label-md text-label-md font-semibold shadow-sm"
                            @click="next"
                        >
                            Next <FontAwesomeIcon icon="arrow-right" />
                        </button>
                        <button
                            v-else
                            type="button"
                            :disabled="form.processing"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-secondary text-on-secondary hover:bg-on-secondary-container font-label-md text-label-md font-semibold shadow-sm disabled:opacity-60"
                            @click="confirmSubmit"
                        >
                            <FontAwesomeIcon icon="paper-plane" /> Submit Report
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <ConfirmationDialog
            :show="showConfirm"
            title="Submit incident report?"
            message="Once submitted, this report can no longer be edited and enters the review workflow."
            confirm-label="Submit Report"
            :processing="form.processing"
            @cancel="showConfirm = false"
            @confirm="submitAs('submit')"
        />
    </AuthenticatedLayout>
</template>
