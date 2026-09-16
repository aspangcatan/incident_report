<script setup>
defineProps({
    form: { type: Object, required: true },
    incidentTypes: { type: Array, required: true },
    departments: { type: Array, required: true },
});

const severities = [
    { value: 'level_1_low', label: 'Low Risk', numeral: 'Level I' },
    { value: 'level_2_moderate', label: 'Moderate Risk', numeral: 'Level II' },
    { value: 'level_3_high', label: 'High Severity', numeral: 'Level III' },
    { value: 'level_4_critical_sentinel', label: 'Critical / Sentinel', numeral: 'Level IV' },
];
</script>

<template>
    <div class="flex flex-col gap-space-lg">
        <h2 class="font-title-lg text-title-lg text-primary font-bold">Section 2: Incident Details & Risk Level</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-space-md">
            <div class="flex flex-col gap-1.5">
                <label for="incident_type_id" class="font-label-md text-label-md text-on-surface font-semibold">Incident Type *</label>
                <select id="incident_type_id" v-model="form.incident_type_id" class="w-full p-3 rounded-lg bg-surface-container-low">
                    <option :value="null" disabled>Select a type</option>
                    <option v-for="type in incidentTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
                </select>
                <span v-if="form.errors.incident_type_id" class="font-body-sm text-body-sm text-error">{{ form.errors.incident_type_id }}</span>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="department_id" class="font-label-md text-label-md text-on-surface font-semibold">Department / Clinical Unit *</label>
                <select id="department_id" v-model="form.department_id" class="w-full p-3 rounded-lg bg-surface-container-low">
                    <option :value="null" disabled>Select a department</option>
                    <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                </select>
                <span v-if="form.errors.department_id" class="font-body-sm text-body-sm text-error">{{ form.errors.department_id }}</span>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="occurred_at" class="font-label-md text-label-md text-on-surface font-semibold">Date & Time of Incident *</label>
                <input id="occurred_at" v-model="form.occurred_at" type="datetime-local" class="w-full p-3 rounded-lg bg-surface-container-low" />
                <span v-if="form.errors.occurred_at" class="font-body-sm text-body-sm text-error">{{ form.errors.occurred_at }}</span>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="location" class="font-label-md text-label-md text-on-surface font-semibold">Precise Location *</label>
                <input id="location" v-model="form.location" type="text" placeholder="Building, floor, room / bed number" class="w-full p-3 rounded-lg bg-surface-container-low" />
                <span v-if="form.errors.location" class="font-body-sm text-body-sm text-error">{{ form.errors.location }}</span>
            </div>
        </div>

        <div class="flex flex-col gap-space-sm">
            <label class="font-label-md text-label-md text-on-surface font-semibold">Severity & Harm Level *</label>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-space-sm">
                <label
                    v-for="option in severities"
                    :key="option.value"
                    class="flex flex-col p-3 rounded-lg cursor-pointer transition-colors"
                    :class="form.severity === option.value ? 'bg-amber-50 ring-2 ring-amber-500' : 'bg-surface-container-low hover:bg-surface-container'"
                >
                    <input v-model="form.severity" type="radio" :value="option.value" class="hidden" />
                    <span class="font-label-sm text-body-sm font-bold text-outline">{{ option.numeral }}</span>
                    <span class="font-title-sm text-title-sm text-on-surface font-bold">{{ option.label }}</span>
                </label>
            </div>
            <span v-if="form.errors.severity" class="font-body-sm text-body-sm text-error">{{ form.errors.severity }}</span>
        </div>
    </div>
</template>
