<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ConfirmationDialog from '@/Components/ConfirmationDialog.vue';
import Step1ReporterInfo from '@/Components/Incidents/Step1ReporterInfo.vue';
import Step2IncidentDetails from '@/Components/Incidents/Step2IncidentDetails.vue';
import Step3PeopleInvolved from '@/Components/Incidents/Step3PeopleInvolved.vue';
import Step4WitnessesPolice from '@/Components/Incidents/Step4WitnessesPolice.vue';
import Step5Description from '@/Components/Incidents/Step5Description.vue';
import Step6ActionsTaken from '@/Components/Incidents/Step6ActionsTaken.vue';
import Step7Recommendations from '@/Components/Incidents/Step7Recommendations.vue';
import Step8Review from '@/Components/Incidents/Step8Review.vue';

const props = defineProps({
    incident: { type: Object, default: null },
    incidentTypes: { type: Array, required: true },
    departments: { type: Array, required: true },
    contributingFactors: { type: Array, required: true },
});

const steps = [
    { title: 'Reporter Info', component: Step1ReporterInfo },
    { title: 'Incident Details', component: Step2IncidentDetails },
    { title: 'People Involved', component: Step3PeopleInvolved },
    { title: 'Witnesses & Police', component: Step4WitnessesPolice },
    { title: 'Description', component: Step5Description },
    { title: 'Actions Taken', component: Step6ActionsTaken },
    { title: 'Recommendations', component: Step7Recommendations },
    { title: 'Review & Submit', component: Step8Review },
];

const currentStep = ref(1);
const showConfirm = ref(false);

const form = useForm({
    action: 'draft',
    incident_type_id: props.incident?.incident_type_id ?? null,
    department_id: props.incident?.department_id ?? null,
    severity: props.incident?.severity ?? null,
    occurred_at: props.incident?.occurred_at?.slice(0, 16) ?? '',
    location: props.incident?.location ?? '',
    summary: props.incident?.summary ?? '',
    recommendations: props.incident?.recommendations ?? '',
    legal_attestation: !!props.incident?.legal_attestation_at,
    police_notified: props.incident?.police_notified ?? false,
    police_station: props.incident?.police_station ?? '',
    police_officer_in_charge: props.incident?.police_officer_in_charge ?? '',
    police_blotter_no: props.incident?.police_blotter_no ?? '',
    police_notified_at: props.incident?.police_notified_at?.slice(0, 16) ?? '',
    individuals: props.incident?.individuals ?? [],
    witnesses: props.incident?.witnesses ?? [],
    narrative_events: props.incident?.narrative_events ?? [],
    actions_taken: props.incident?.actions ?? [],
    contributing_factor_ids: props.incident?.contributing_factors?.map((f) => f.id) ?? [],
    attachments: [],
});

const isLastStep = computed(() => currentStep.value === steps.length);

function next() {
    if (currentStep.value < steps.length) currentStep.value += 1;
}

function back() {
    if (currentStep.value > 1) currentStep.value -= 1;
}

function targetUrl() {
    return props.incident ? `/incidents/${props.incident.id}` : '/incidents';
}

function submitAs(action) {
    form.action = action;
    const options = { preserveScroll: true, onFinish: () => (showConfirm.value = false) };

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
                @click="currentStep = index + 1"
            >
                {{ index + 1 }}. {{ step.title }}
            </button>
        </div>

        <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm">
            <component
                :is="steps[currentStep - 1].component"
                :form="form"
                :incident-types="incidentTypes"
                :departments="departments"
                :contributing-factors="contributingFactors"
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
