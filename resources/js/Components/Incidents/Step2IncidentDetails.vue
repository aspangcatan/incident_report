<script setup>
defineProps({
    form: { type: Object, required: true },
    incidentTypes: { type: Array, required: true },
    departments: { type: Array, required: true },
});
</script>

<template>
    <div class="flex flex-col gap-space-lg">
        <h2 class="font-title-lg text-title-lg text-primary font-bold">Section 2: Incident Details</h2>

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
    </div>
</template>
