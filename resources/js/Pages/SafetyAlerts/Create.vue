<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    departments: { type: Array, required: true },
    urgencies: { type: Array, required: true },
    incident: { type: Object, default: null },
});

const form = useForm({
    title: '',
    message: '',
    urgency: null,
    audience: 'all',
    department_ids: [],
    incident_id: props.incident?.id ?? null,
});

const filter = ref('');
const shownDepartments = computed(() => {
    const term = filter.value.trim().toLowerCase();
    return term ? props.departments.filter((d) => d.name.toLowerCase().includes(term)) : props.departments;
});

function submit() {
    form.post('/safety-alerts');
}
</script>

<template>
    <Head title="Issue Safety Alert" />

    <AuthenticatedLayout>
        <form class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md" @submit.prevent="submit">
            <div class="flex flex-col gap-1">
                <h1 class="font-headline-sm text-headline-sm text-on-surface">Issue Safety Alert</h1>
                <p class="font-body-sm text-body-sm text-outline">
                    Recipients get a notification and a banner until they acknowledge it.
                    <template v-if="incident"> Linked to incident {{ incident.incident_number }}.</template>
                </p>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="alert_title" class="font-label-md text-label-md text-on-surface font-semibold">Title *</label>
                <input id="alert_title" v-model="form.title" type="text" maxlength="255" class="w-full p-3 rounded-lg bg-surface-container-low" />
                <span v-if="form.errors.title" class="font-body-sm text-body-sm text-error">{{ form.errors.title }}</span>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="alert_message" class="font-label-md text-label-md text-on-surface font-semibold">Message *</label>
                <span class="font-body-sm text-body-sm text-outline">What happened, what the risk is, and what staff must do. Don't include patient names.</span>
                <textarea id="alert_message" v-model="form.message" rows="6" class="w-full p-3 rounded-lg bg-surface-container-low" />
                <span v-if="form.errors.message" class="font-body-sm text-body-sm text-error">{{ form.errors.message }}</span>
            </div>

            <fieldset class="flex flex-col gap-1.5">
                <legend class="font-label-md text-label-md text-on-surface font-semibold">Urgency *</legend>
                <div class="flex flex-wrap items-center gap-space-md mt-1">
                    <label v-for="urgency in urgencies" :key="urgency.value" class="flex items-center gap-2 font-body-md text-body-md text-on-surface">
                        <input v-model="form.urgency" type="radio" name="urgency" :value="urgency.value" /> {{ urgency.label }}
                    </label>
                </div>
                <span v-if="form.errors.urgency" class="font-body-sm text-body-sm text-error">{{ form.errors.urgency }}</span>
            </fieldset>

            <fieldset class="flex flex-col gap-1.5">
                <legend class="font-label-md text-label-md text-on-surface font-semibold">Send to *</legend>
                <div class="flex flex-wrap items-center gap-space-md mt-1">
                    <label class="flex items-center gap-2 font-body-md text-body-md text-on-surface">
                        <input v-model="form.audience" type="radio" name="audience" value="all" /> All staff
                    </label>
                    <label class="flex items-center gap-2 font-body-md text-body-md text-on-surface">
                        <input v-model="form.audience" type="radio" name="audience" value="departments" /> Selected departments
                    </label>
                </div>
            </fieldset>

            <fieldset v-if="form.audience === 'departments'" class="flex flex-col gap-1.5">
                <legend class="font-label-md text-label-md text-on-surface font-semibold">Departments *</legend>
                <input v-model="filter" type="search" aria-label="Filter departments" placeholder="Type part of a department name" class="w-full md:w-1/2 p-3 rounded-lg bg-surface-container-low mt-1" />
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-x-space-md gap-y-2 max-h-72 overflow-y-auto mt-1">
                    <label v-for="department in shownDepartments" :key="department.id" class="flex items-start gap-2 font-body-md text-body-md text-on-surface">
                        <input v-model="form.department_ids" type="checkbox" :value="department.id" class="mt-1" />
                        <span>{{ department.name }}</span>
                    </label>
                </div>
                <span class="font-body-sm text-body-sm text-outline">{{ form.department_ids.length }} selected</span>
                <span v-if="form.errors.department_ids" class="font-body-sm text-body-sm text-error">{{ form.errors.department_ids }}</span>
            </fieldset>

            <button type="submit" :disabled="form.processing" class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60 w-fit">
                Issue Alert
            </button>
        </form>
    </AuthenticatedLayout>
</template>
