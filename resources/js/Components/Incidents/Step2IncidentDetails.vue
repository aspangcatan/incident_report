<script setup>
import { computed } from 'vue';
import ChecklistWithOther from '@/Components/Incidents/ChecklistWithOther.vue';

const props = defineProps({
    form: { type: Object, required: true },
    incidentTypes: { type: Array, required: true },
    departments: { type: Array, required: true },
});

const typeOptions = computed(() => props.incidentTypes.map((type) => ({ value: type.id, label: type.name })));
</script>

<template>
    <div class="flex flex-col gap-5">

        <ChecklistWithOther
            :form="form"
            field="incident_type_ids"
            other-field="incident_type_other"
            :options="typeOptions"
            label="Incident Type"
            id-prefix="incident_type"
        />

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

            <div class="flex flex-col gap-1.5">
                <label for="department_id" class="form-label">Department / Clinical Unit *</label>
                <select id="department_id" v-model="form.department_id" class="form-input">
                    <option :value="null" disabled>Select a department</option>
                    <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                </select>
                <span v-if="form.errors.department_id" class="form-error">{{ form.errors.department_id }}</span>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="occurred_at" class="form-label">Date & Time of Incident *</label>
                <input id="occurred_at" v-model="form.occurred_at" type="datetime-local" class="form-input" />
                <span v-if="form.errors.occurred_at" class="form-error">{{ form.errors.occurred_at }}</span>
            </div>

            <div class="flex flex-col gap-1.5 md:col-span-2">
                <label for="location" class="form-label">Location of Incident *</label>
                <input id="location" v-model="form.location" type="text" placeholder="Building, floor, room / bed number" class="form-input" />
                <span v-if="form.errors.location" class="form-error">{{ form.errors.location }}</span>
            </div>
        </div>
    </div>
</template>
