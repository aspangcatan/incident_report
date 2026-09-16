<script setup>
import SeverityBadge from '@/Components/SeverityBadge.vue';

defineProps({
    form: { type: Object, required: true },
    incidentTypes: { type: Array, required: true },
    departments: { type: Array, required: true },
});

function typeName(incidentTypes, id) {
    return incidentTypes.find((t) => t.id === id)?.name ?? '—';
}

function departmentName(departments, id) {
    return departments.find((d) => d.id === id)?.name ?? '—';
}
</script>

<template>
    <div class="flex flex-col gap-space-lg">
        <h2 class="font-title-lg text-title-lg text-primary font-bold">Section 8: Review & Submit</h2>

        <div class="p-space-md rounded-lg bg-surface-container-low flex flex-col gap-2">
            <div class="flex items-center gap-2">
                <span class="font-label-sm text-body-sm text-outline">Type:</span>
                <span class="font-body-md text-body-md text-on-surface">{{ typeName(incidentTypes, form.incident_type_id) }}</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="font-label-sm text-body-sm text-outline">Department:</span>
                <span class="font-body-md text-body-md text-on-surface">{{ departmentName(departments, form.department_id) }}</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="font-label-sm text-body-sm text-outline">Severity:</span>
                <SeverityBadge v-if="form.severity" :severity="form.severity" />
            </div>
            <div class="flex items-center gap-2">
                <span class="font-label-sm text-body-sm text-outline">Location:</span>
                <span class="font-body-md text-body-md text-on-surface">{{ form.location || '—' }}</span>
            </div>
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
