<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import ChecklistWithOther from '@/Components/Incidents/ChecklistWithOther.vue';
import InjuryDetailsFields from '@/Components/Incidents/InjuryDetailsFields.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    incidentTypes: { type: Array, required: true },
    departments: { type: Array, required: true },
    injuryOptions: { type: Object, required: true },
});

const typeOptions = computed(() => props.incidentTypes.map((type) => ({ value: type.id, label: type.name })));

const relationships = [
    { value: 'patient', label: 'Patient' },
    { value: 'relative', label: 'Relative' },
    { value: 'visitor', label: 'Visitor' },
    { value: 'other', label: 'Other' },
];

const form = useForm({
    guest_name: '',
    guest_contact: '',
    guest_relationship: null,
    incident_type_ids: [],
    incident_type_other: '',
    department_id: null,
    occurred_at: '',
    location: '',
    has_injury: null,
    injury_causes: [],
    injury_cause_other: '',
    injury_agents: [],
    injury_agent_other: '',
    injury_chemical_details: '',
    summary: '',
    legal_attestation: false,
    website: '',
});

// datetime-local expects the browser's LOCAL time; toISOString() would give UTC
// (8 hours behind in Manila) and block the last 8 hours.
const nowDate = new Date();
const now = new Date(nowDate.getTime() - nowDate.getTimezoneOffset() * 60000).toISOString().slice(0, 16);

function submit() {
    form.post('/report');
}
</script>

<template>
    <Head title="Report an Incident" />

    <GuestLayout wide>
        <h1 class="font-headline-sm text-headline-sm text-on-surface mb-1">Report an Incident</h1>
        <p class="font-body-sm text-body-sm text-outline mb-space-lg">
            For patients, relatives and visitors. Hospital staff should
            <Link href="/login" class="text-primary font-semibold">sign in</Link>
            instead.
        </p>

        <form class="flex flex-col gap-space-lg" @submit.prevent="submit">
            <div class="hidden" aria-hidden="true">
                <label for="website">Website</label>
                <input id="website" v-model="form.website" type="text" tabindex="-1" autocomplete="off" />
            </div>

            <div class="flex flex-col gap-space-md">
                <h2 class="font-title-lg text-title-lg text-primary font-bold">About you</h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-space-md">
                    <div class="flex flex-col gap-1.5">
                        <label for="guest_name" class="font-label-md text-label-md text-on-surface font-semibold">Your Name *</label>
                        <input
                            id="guest_name"
                            v-model="form.guest_name"
                            type="text"
                            autofocus
                            class="w-full p-3 rounded-lg bg-surface-container-low text-on-surface font-body-md text-body-md focus:outline-none focus:bg-surface-container-lowest focus:ring-2 focus:ring-primary shadow-sm"
                        />
                        <span v-if="form.errors.guest_name" class="font-body-sm text-body-sm text-error">{{ form.errors.guest_name }}</span>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="guest_contact" class="font-label-md text-label-md text-on-surface font-semibold">Phone or Email *</label>
                        <input
                            id="guest_contact"
                            v-model="form.guest_contact"
                            type="text"
                            placeholder="How can we reach you?"
                            class="w-full p-3 rounded-lg bg-surface-container-low text-on-surface font-body-md text-body-md focus:outline-none focus:bg-surface-container-lowest focus:ring-2 focus:ring-primary shadow-sm"
                        />
                        <span v-if="form.errors.guest_contact" class="font-body-sm text-body-sm text-error">{{ form.errors.guest_contact }}</span>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="guest_relationship" class="font-label-md text-label-md text-on-surface font-semibold">You are a *</label>
                        <select id="guest_relationship" v-model="form.guest_relationship" class="w-full p-3 rounded-lg bg-surface-container-low">
                            <option :value="null" disabled>Select one</option>
                            <option v-for="option in relationships" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                        <span v-if="form.errors.guest_relationship" class="font-body-sm text-body-sm text-error">{{ form.errors.guest_relationship }}</span>
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-space-md">
                <h2 class="font-title-lg text-title-lg text-primary font-bold">What happened</h2>

                <ChecklistWithOther
                    :form="form"
                    field="incident_type_ids"
                    other-field="incident_type_other"
                    :options="typeOptions"
                    label="Incident Type"
                    id-prefix="incident_type"
                />

                <div class="grid grid-cols-1 md:grid-cols-2 gap-space-md">

                    <div class="flex flex-col gap-1.5">
                        <label for="department_id" class="font-label-md text-label-md text-on-surface font-semibold">Department / Clinical Unit</label>
                        <select id="department_id" v-model="form.department_id" class="w-full p-3 rounded-lg bg-surface-container-low">
                            <option :value="null">I don't know</option>
                            <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                        </select>
                        <span v-if="form.errors.department_id" class="font-body-sm text-body-sm text-error">{{ form.errors.department_id }}</span>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="occurred_at" class="font-label-md text-label-md text-on-surface font-semibold">Date & Time of Incident *</label>
                        <input
                            id="occurred_at"
                            v-model="form.occurred_at"
                            type="datetime-local"
                            :max="now"
                            class="w-full p-3 rounded-lg bg-surface-container-low"
                        />
                        <span v-if="form.errors.occurred_at" class="font-body-sm text-body-sm text-error">{{ form.errors.occurred_at }}</span>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="location" class="font-label-md text-label-md text-on-surface font-semibold">Location of Incident *</label>
                        <input
                            id="location"
                            v-model="form.location"
                            type="text"
                            placeholder="Building, floor, room / bed number"
                            class="w-full p-3 rounded-lg bg-surface-container-low"
                        />
                        <span v-if="form.errors.location" class="font-body-sm text-body-sm text-error">{{ form.errors.location }}</span>
                    </div>
                </div>

                <InjuryDetailsFields :form="form" :injury-options="injuryOptions" />

                <div class="flex flex-col gap-1.5">
                    <label for="summary" class="font-label-md text-label-md text-on-surface font-semibold">What happened? *</label>
                    <textarea id="summary" v-model="form.summary" rows="4" class="w-full p-3 rounded-lg bg-surface-container-low" />
                    <span v-if="form.errors.summary" class="font-body-sm text-body-sm text-error">{{ form.errors.summary }}</span>
                </div>
            </div>

            <div class="flex flex-col gap-1.5 pt-space-sm border-t border-outline-variant">
                <label class="flex items-start gap-2">
                    <input v-model="form.legal_attestation" type="checkbox" class="mt-1" />
                    <span class="font-body-sm text-body-sm text-on-surface">
                        I confirm that, to the best of my knowledge, the information above is true and accurate.
                    </span>
                </label>
                <span v-if="form.errors.legal_attestation" class="font-body-sm text-body-sm text-error">{{ form.errors.legal_attestation }}</span>
            </div>

            <div class="flex flex-col gap-1.5">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full py-2.5 rounded-lg bg-primary text-on-primary hover:bg-primary-container font-label-md text-label-md font-semibold transition-colors shadow-sm disabled:opacity-60"
                >
                    Submit Report
                </button>
                <p class="font-body-sm text-body-sm text-outline text-center">You can send up to 3 reports per hour.</p>
            </div>
        </form>
    </GuestLayout>
</template>
