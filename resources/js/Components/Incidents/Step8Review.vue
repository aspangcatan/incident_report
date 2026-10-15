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
    <div class="flex flex-col gap-5">
        <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4 p-5 rounded-xl bg-surface-container-low">
            <div class="flex flex-col gap-0.5 md:col-span-2">
                <dt class="font-label-sm text-label-sm uppercase tracking-wider text-outline">Type of incident</dt>
                <dd class="font-body-md text-body-md text-on-surface font-semibold">{{ typeNames(incidentTypes, form) }}</dd>
            </div>
            <div class="flex flex-col gap-0.5">
                <dt class="font-label-sm text-label-sm uppercase tracking-wider text-outline">Department</dt>
                <dd class="font-body-md text-body-md text-on-surface">{{ departmentName(departments, form.department_id) }}</dd>
            </div>
            <div class="flex flex-col gap-0.5">
                <dt class="font-label-sm text-label-sm uppercase tracking-wider text-outline">Location</dt>
                <dd class="font-body-md text-body-md text-on-surface">{{ form.location || '—' }}</dd>
            </div>
            <div class="flex flex-col gap-0.5">
                <dt class="font-label-sm text-label-sm uppercase tracking-wider text-outline">Anyone injured</dt>
                <dd class="font-body-md text-body-md text-on-surface">{{ form.has_injury === null ? '—' : form.has_injury ? 'Yes' : 'No' }}</dd>
            </div>
            <template v-if="form.has_injury">
                <div class="flex flex-col gap-0.5">
                    <dt class="font-label-sm text-label-sm uppercase tracking-wider text-outline">Cause of injury</dt>
                    <dd class="font-body-md text-body-md text-on-surface">{{ injuryText(form, injuryOptions.causes, 'injury_causes', 'injury_cause_other') }}</dd>
                </div>
                <div class="flex flex-col gap-0.5">
                    <dt class="font-label-sm text-label-sm uppercase tracking-wider text-outline">Agent of injury</dt>
                    <dd class="font-body-md text-body-md text-on-surface">{{ injuryText(form, injuryOptions.agents, 'injury_agents', 'injury_agent_other') }}</dd>
                </div>
                <div v-if="form.injury_chemical_details" class="flex flex-col gap-0.5">
                    <dt class="font-label-sm text-label-sm uppercase tracking-wider text-outline">Chemical involved</dt>
                    <dd class="font-body-md text-body-md text-on-surface">{{ form.injury_chemical_details }}</dd>
                </div>
            </template>
            <div class="flex flex-col gap-0.5 md:col-span-2">
                <dt class="font-label-sm text-label-sm uppercase tracking-wider text-outline">Description</dt>
                <dd class="font-body-md text-body-md text-on-surface whitespace-pre-line">{{ form.summary || '—' }}</dd>
            </div>
        </dl>

        <div class="grid grid-cols-3 gap-3">
            <div class="flex flex-col items-center gap-0.5 p-3 rounded-xl ring-1 ring-outline-variant/60">
                <span class="font-code-tabular text-headline-sm text-primary font-bold">{{ form.individuals.length }}</span>
                <span class="font-body-sm text-body-sm text-on-surface-variant text-center">People involved</span>
            </div>
            <div class="flex flex-col items-center gap-0.5 p-3 rounded-xl ring-1 ring-outline-variant/60">
                <span class="font-code-tabular text-headline-sm text-primary font-bold">{{ form.witnesses.length }}</span>
                <span class="font-body-sm text-body-sm text-on-surface-variant text-center">Witnesses</span>
            </div>
            <div class="flex flex-col items-center gap-0.5 p-3 rounded-xl ring-1 ring-outline-variant/60">
                <span class="font-code-tabular text-headline-sm text-primary font-bold">{{ form.attachments.length }}</span>
                <span class="font-body-sm text-body-sm text-on-surface-variant text-center">New attachments</span>
            </div>
        </div>

        <p class="flex items-start gap-2 p-3 rounded-lg bg-amber-50 text-amber-900 font-body-md text-body-md">
            <FontAwesomeIcon icon="circle-info" class="mt-0.5" />
            Review every section before submitting. Once submitted, this report can no longer be edited and enters the review workflow.
        </p>
    </div>
</template>
