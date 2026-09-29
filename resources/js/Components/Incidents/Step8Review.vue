<script setup>
defineProps({
    form: { type: Object, required: true },
    incidentTypes: { type: Array, required: true },
    departments: { type: Array, required: true },
    injuryOptions: { type: Object, required: true },
});

// Chosen labels plus the "Others (Specify)" text, as one readable line.
function listWithOther(labels, other) {
    const all = [...labels, ...(other ? [`Others: ${other}`] : [])];
    return all.length ? all.join('; ') : '—';
}

function typeNames(incidentTypes, form) {
    return listWithOther(form.incident_type_ids.map((id) => incidentTypes.find((t) => t.id === id)?.name).filter(Boolean), form.incident_type_other);
}

function injuryText(form, map, field, otherField) {
    return listWithOther((form[field] ?? []).map((value) => map[value] ?? value), form[otherField]);
}

function departmentName(departments, id) {
    return departments.find((d) => d.id === id)?.name ?? '—';
}
</script>

<template>
    <div class="flex flex-col gap-space-lg">
        <h2 class="font-title-lg text-title-lg text-primary font-bold">Section 7: Review & Submit</h2>

        <div class="p-space-md rounded-lg bg-surface-container-low flex flex-col gap-2">
            <div class="flex items-start gap-2">
                <span class="font-label-sm text-body-sm text-outline">Type:</span>
                <span class="font-body-md text-body-md text-on-surface">{{ typeNames(incidentTypes, form) }}</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="font-label-sm text-body-sm text-outline">Department:</span>
                <span class="font-body-md text-body-md text-on-surface">{{ departmentName(departments, form.department_id) }}</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="font-label-sm text-body-sm text-outline">Location:</span>
                <span class="font-body-md text-body-md text-on-surface">{{ form.location || '—' }}</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="font-label-sm text-body-sm text-outline">Anyone injured:</span>
                <span class="font-body-md text-body-md text-on-surface">{{ form.has_injury === null ? '—' : form.has_injury ? 'Yes' : 'No' }}</span>
            </div>
            <template v-if="form.has_injury">
                <div class="flex items-start gap-2">
                    <span class="font-label-sm text-body-sm text-outline">Cause of injury:</span>
                    <span class="font-body-md text-body-md text-on-surface">{{ injuryText(form, injuryOptions.causes, 'injury_causes', 'injury_cause_other') }}</span>
                </div>
                <div class="flex items-start gap-2">
                    <span class="font-label-sm text-body-sm text-outline">Agent of injury:</span>
                    <span class="font-body-md text-body-md text-on-surface">{{ injuryText(form, injuryOptions.agents, 'injury_agents', 'injury_agent_other') }}</span>
                </div>
                <div v-if="form.injury_chemical_details" class="flex items-start gap-2">
                    <span class="font-label-sm text-body-sm text-outline">Chemical involved:</span>
                    <span class="font-body-md text-body-md text-on-surface">{{ form.injury_chemical_details }}</span>
                </div>
            </template>
            <div class="flex flex-col gap-1">
                <span class="font-label-sm text-body-sm text-outline">Summary:</span>
                <span class="font-body-md text-body-md text-on-surface">{{ form.summary || '—' }}</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="font-label-sm text-body-sm text-outline">People involved:</span>
                <span class="font-body-md text-body-md text-on-surface">{{ form.individuals.length }}</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="font-label-sm text-body-sm text-outline">Witnesses:</span>
                <span class="font-body-md text-body-md text-on-surface">{{ form.witnesses.length }}</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="font-label-sm text-body-sm text-outline">Attachments:</span>
                <span class="font-body-md text-body-md text-on-surface">{{ form.attachments.length }}</span>
            </div>
        </div>

        <p class="font-body-sm text-body-sm text-outline">
            Review every section before submitting. Once submitted, this report can no longer be edited and enters
            the review workflow.
        </p>
    </div>
</template>
