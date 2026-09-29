<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import RecurrenceIncidentList from '@/Components/RecurrenceIncidentList.vue';

const props = defineProps({
    department: { type: Object, required: true },
    incidentType: { type: Object, required: true },
    incidents: { type: Array, required: true },
    assignees: { type: Array, required: true },
    windowDays: { type: Number, required: true },
});

const form = useForm({
    department_id: props.department.id,
    incident_type_id: props.incidentType.id,
    assigned_to: props.assignees.length === 1 ? props.assignees[0].id : null,
    due_date: '',
    cqi_notes: '',
});

function submit() {
    form.post('/recurrence-reviews');
}
</script>

<template>
    <Head title="Open Recurrence Review" />

    <AuthenticatedLayout>
        <form class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md" @submit.prevent="submit">
            <div class="flex flex-col gap-1">
                <h1 class="font-headline-sm text-headline-sm text-on-surface">Open Recurrence Review</h1>
                <p class="font-body-md text-body-md text-on-surface">
                    <strong>{{ incidentType.name }}</strong> keeps happening in <strong>{{ department.name }}</strong>.
                    Ask the department to find and fix the underlying cause, beyond the individual incidents.
                </p>
            </div>

            <RecurrenceIncidentList :incidents="incidents" :window-days="windowDays" />

            <div class="flex flex-col gap-1.5">
                <label for="rr_assignee" class="font-label-md text-label-md text-on-surface font-semibold">Assign to *</label>
                <span class="font-body-sm text-body-sm text-outline">The department's Head or Safety Focal Person.</span>
                <select id="rr_assignee" v-model="form.assigned_to" class="w-full md:w-1/2 p-3 rounded-lg bg-surface-container-low">
                    <option :value="null" disabled>Choose a person</option>
                    <option v-for="person in assignees" :key="person.id" :value="person.id">{{ person.name }} — {{ person.role }}</option>
                </select>
                <span v-if="!assignees.length" class="font-body-sm text-body-sm text-error">This department has no active Head or Safety Focal Person in the system yet.</span>
                <span v-if="form.errors.assigned_to" class="font-body-sm text-body-sm text-error">{{ form.errors.assigned_to }}</span>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="rr_due" class="font-label-md text-label-md text-on-surface font-semibold">Due date *</label>
                <input id="rr_due" v-model="form.due_date" type="date" class="w-full md:w-1/3 p-3 rounded-lg bg-surface-container-low" />
                <span v-if="form.errors.due_date" class="font-body-sm text-body-sm text-error">{{ form.errors.due_date }}</span>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="rr_notes" class="font-label-md text-label-md text-on-surface font-semibold">What to look into *</label>
                <span class="font-body-sm text-body-sm text-outline">What the CQI Office noticed and what the department should examine.</span>
                <textarea id="rr_notes" v-model="form.cqi_notes" rows="4" class="w-full p-3 rounded-lg bg-surface-container-low" />
                <span v-if="form.errors.cqi_notes" class="font-body-sm text-body-sm text-error">{{ form.errors.cqi_notes }}</span>
            </div>

            <button type="submit" :disabled="form.processing" class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60 w-fit">
                Open Review
            </button>
        </form>
    </AuthenticatedLayout>
</template>
