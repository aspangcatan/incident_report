<script setup>
import { computed } from 'vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import ChecklistWithOther from '@/Components/Incidents/ChecklistWithOther.vue';
import InjuryDetailsFields from '@/Components/Incidents/InjuryDetailsFields.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

// Photo: Age Cymru on Unsplash (Unsplash License, free to use).
const HERO_IMAGE = '/images/landing/report-nurse-patient.jpg';

const props = defineProps({
    incidentTypes: { type: Array, required: true },
    departments: { type: Array, required: true },
    injuryOptions: { type: Object, required: true },
});

const typeOptions = computed(() => props.incidentTypes.map((type) => ({ value: type.id, label: type.name })));

const relationships = [
    { value: 'patient', label: 'Patient', icon: 'user' },
    { value: 'relative', label: 'Relative', icon: 'user-plus' },
    { value: 'visitor', label: 'Visitor', icon: 'building' },
    { value: 'other', label: 'Other', icon: 'circle-info' },
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

const inputClass = 'form-input';

const nextSteps = [
    { title: 'We receive it', text: 'Your report goes straight to the Patient Safety/CQI Office.' },
    { title: 'We look into it', text: 'The department involved reviews what happened and why.' },
    { title: 'We act on it', text: 'Changes are made so it does not happen again. We may contact you for details.' },
];

function submit() {
    form.post('/report', { preserveScroll: (page) => Object.keys(page.props.errors ?? {}).length > 0 });
}
</script>

<template>
    <Head title="Report an Incident" />

    <PublicLayout>
        <template #actions>
            <Link
                href="/login"
                class="inline-flex items-center gap-2 px-3 sm:px-4 py-2 rounded-lg bg-surface-container-lowest text-primary font-label-md text-label-md font-semibold ring-1 ring-outline-variant hover:bg-surface-container-low"
            >
                <FontAwesomeIcon icon="right-to-bracket" /> <span class="sm:hidden">Sign in</span><span class="hidden sm:inline">Staff sign in</span>
            </Link>
        </template>

        <!-- Banner -->
        <section class="relative overflow-hidden bg-primary text-on-primary">
            <img :src="HERO_IMAGE" alt="" class="absolute inset-0 h-full w-full object-cover object-[70%_30%] opacity-40 md:opacity-100 md:left-1/2 md:w-1/2" />
            <div class="absolute inset-0 bg-gradient-to-r from-primary via-primary/95 to-primary/40 md:via-primary md:via-50% md:to-transparent" />
            <div class="relative max-w-6xl mx-auto px-4 sm:px-6 py-10 md:py-16">
                <div class="max-w-xl flex flex-col gap-4">
                    <span class="inline-flex w-fit items-center gap-2 px-3 py-1 rounded-full bg-white/15 font-label-sm text-label-sm uppercase">
                        <FontAwesomeIcon icon="shield-halved" /> For patients, relatives &amp; visitors
                    </span>
                    <h1 class="font-headline-xl text-headline-xl-mobile md:text-headline-xl font-bold tracking-tight">Tell us what happened</h1>
                    <p class="font-body-lg text-body-lg text-white/85">
                        Saw or experienced an incident or accident at Cebu South Medical Center? Your report helps us keep every patient and visitor safe.
                    </p>
                    <ul class="flex flex-wrap gap-2 pt-1">
                        <li class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-white/10 ring-1 ring-white/20 font-label-md text-label-md"><FontAwesomeIcon icon="clock" /> About 5 minutes</li>
                        <li class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-white/10 ring-1 ring-white/20 font-label-md text-label-md"><FontAwesomeIcon icon="lock" /> Handled confidentially</li>
                        <li class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-white/10 ring-1 ring-white/20 font-label-md text-label-md"><FontAwesomeIcon icon="user" /> No account needed</li>
                    </ul>
                </div>
            </div>
        </section>

        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 md:py-12 grid lg:grid-cols-[minmax(0,1fr)_20rem] gap-6 lg:gap-8 items-start">
            <form class="report-form flex flex-col gap-5 min-w-0" novalidate @submit.prevent="submit">
                <div class="hidden" aria-hidden="true">
                    <label for="website">Website</label>
                    <input id="website" v-model="form.website" type="text" tabindex="-1" autocomplete="off" />
                </div>

                <p v-if="form.hasErrors" role="alert" class="rounded-xl bg-error-container text-on-error-container p-4 font-body-md text-body-md flex items-start gap-3">
                    <FontAwesomeIcon icon="circle-exclamation" class="mt-0.5" />
                    Some details are missing or need a fix. Please check the fields marked in red.
                </p>

                <!-- 1. About you -->
                <section class="rounded-2xl bg-surface-container-lowest p-5 sm:p-6 shadow-sm ring-1 ring-outline-variant/40 flex flex-col gap-5">
                    <header class="flex items-center gap-3">
                        <span class="h-9 w-9 shrink-0 rounded-full bg-primary text-on-primary font-bold flex items-center justify-center">1</span>
                        <div>
                            <h2 class="font-title-lg text-title-lg text-primary font-bold">About you</h2>
                            <p class="font-body-sm text-body-sm text-outline">So we can reach you if we need more details.</p>
                        </div>
                    </header>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="flex flex-col gap-1.5">
                            <label for="guest_name" class="font-label-md text-label-md text-on-surface font-semibold">Your name *</label>
                            <input id="guest_name" v-model="form.guest_name" type="text" autocomplete="name" placeholder="Full name" :class="inputClass" />
                            <span v-if="form.errors.guest_name" class="font-body-sm text-body-sm text-error">{{ form.errors.guest_name }}</span>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="guest_contact" class="font-label-md text-label-md text-on-surface font-semibold">Phone or email *</label>
                            <input id="guest_contact" v-model="form.guest_contact" type="text" placeholder="How can we reach you?" :class="inputClass" />
                            <span v-if="form.errors.guest_contact" class="font-body-sm text-body-sm text-error">{{ form.errors.guest_contact }}</span>
                        </div>
                    </div>

                    <fieldset class="flex flex-col gap-2">
                        <legend class="font-label-md text-label-md text-on-surface font-semibold mb-2">You are a *</legend>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            <label
                                v-for="option in relationships"
                                :key="option.value"
                                class="relative flex items-center gap-2 p-3 rounded-lg cursor-pointer ring-1 transition-colors font-label-md text-label-md"
                                :class="form.guest_relationship === option.value
                                    ? 'bg-primary-fixed ring-2 ring-primary text-primary font-semibold'
                                    : 'bg-surface-container-low ring-outline-variant/60 text-on-surface hover:bg-surface-container'"
                            >
                                <input v-model="form.guest_relationship" type="radio" name="guest_relationship" :value="option.value" class="sr-only" />
                                <FontAwesomeIcon :icon="option.icon" />
                                {{ option.label }}
                            </label>
                        </div>
                        <span v-if="form.errors.guest_relationship" class="font-body-sm text-body-sm text-error">{{ form.errors.guest_relationship }}</span>
                    </fieldset>
                </section>

                <!-- 2. When & where -->
                <section class="rounded-2xl bg-surface-container-lowest p-5 sm:p-6 shadow-sm ring-1 ring-outline-variant/40 flex flex-col gap-5">
                    <header class="flex items-center gap-3">
                        <span class="h-9 w-9 shrink-0 rounded-full bg-primary text-on-primary font-bold flex items-center justify-center">2</span>
                        <div>
                            <h2 class="font-title-lg text-title-lg text-primary font-bold">When and where</h2>
                            <p class="font-body-sm text-body-sm text-outline">As close as you remember is fine.</p>
                        </div>
                    </header>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="flex flex-col gap-1.5">
                            <label for="occurred_at" class="font-label-md text-label-md text-on-surface font-semibold">Date and time *</label>
                            <input id="occurred_at" v-model="form.occurred_at" type="datetime-local" :max="now" :class="inputClass" />
                            <span v-if="form.errors.occurred_at" class="font-body-sm text-body-sm text-error">{{ form.errors.occurred_at }}</span>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="department_id" class="font-label-md text-label-md text-on-surface font-semibold">Department / clinical unit</label>
                            <select id="department_id" v-model="form.department_id" :class="inputClass">
                                <option :value="null">I don't know</option>
                                <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                            </select>
                            <span v-if="form.errors.department_id" class="font-body-sm text-body-sm text-error">{{ form.errors.department_id }}</span>
                        </div>
                        <div class="flex flex-col gap-1.5 md:col-span-2">
                            <label for="location" class="font-label-md text-label-md text-on-surface font-semibold">Exact location *</label>
                            <input id="location" v-model="form.location" type="text" placeholder="Building, floor, room or bed number" :class="inputClass" />
                            <span v-if="form.errors.location" class="font-body-sm text-body-sm text-error">{{ form.errors.location }}</span>
                        </div>
                    </div>
                </section>

                <!-- 3. What happened -->
                <section class="rounded-2xl bg-surface-container-lowest p-5 sm:p-6 shadow-sm ring-1 ring-outline-variant/40 flex flex-col gap-5">
                    <header class="flex items-center gap-3">
                        <span class="h-9 w-9 shrink-0 rounded-full bg-primary text-on-primary font-bold flex items-center justify-center">3</span>
                        <div>
                            <h2 class="font-title-lg text-title-lg text-primary font-bold">What happened</h2>
                            <p class="font-body-sm text-body-sm text-outline">Choose the type, then describe it in your own words.</p>
                        </div>
                    </header>

                    <div class="flex flex-col gap-5">
                        <ChecklistWithOther
                            :form="form"
                            field="incident_type_ids"
                            other-field="incident_type_other"
                            :options="typeOptions"
                            label="Type of incident"
                            hint="Tick all that apply."
                            id-prefix="incident_type"
                        />

                        <InjuryDetailsFields :form="form" :injury-options="injuryOptions" />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="summary" class="font-label-md text-label-md text-on-surface font-semibold">Describe what happened *</label>
                        <textarea
                            id="summary"
                            v-model="form.summary"
                            rows="5"
                            placeholder="What happened, who was involved, and what was done right after."
                            :class="inputClass"
                        />
                        <span v-if="form.errors.summary" class="font-body-sm text-body-sm text-error">{{ form.errors.summary }}</span>
                    </div>
                </section>

                <!-- Submit -->
                <section class="rounded-2xl bg-surface-container-lowest p-5 sm:p-6 shadow-sm ring-1 ring-outline-variant/40 flex flex-col gap-4">
                    <label class="flex items-start gap-3 p-3 rounded-lg bg-surface-container-low cursor-pointer">
                        <input v-model="form.legal_attestation" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 rounded accent-primary" />
                        <span class="font-body-md text-body-md text-on-surface">
                            I confirm that, to the best of my knowledge, the information above is true and accurate.
                        </span>
                    </label>
                    <span v-if="form.errors.legal_attestation" class="font-body-sm text-body-sm text-error -mt-2">{{ form.errors.legal_attestation }}</span>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full inline-flex items-center justify-center gap-2 py-3.5 rounded-lg bg-primary text-on-primary hover:bg-primary-container font-label-md text-body-lg font-semibold transition-colors shadow-sm disabled:opacity-60"
                    >
                        <FontAwesomeIcon icon="paper-plane" /> {{ form.processing ? 'Sending…' : 'Submit report' }}
                    </button>
                    <p class="font-body-sm text-body-sm text-outline text-center">You can send up to 3 reports per hour.</p>
                </section>
            </form>

            <!-- Side panel -->
            <aside class="flex flex-col gap-4 lg:sticky lg:top-24">
                <div role="note" class="rounded-2xl bg-error-container text-on-error-container p-5 flex gap-3">
                    <FontAwesomeIcon icon="triangle-exclamation" class="mt-1 text-error" />
                    <div class="flex flex-col gap-1">
                        <span class="font-title-sm text-title-sm font-bold">Someone needs help now?</span>
                        <span class="font-body-md text-body-md">Tell the nearest nurse or staff member right away. This form is not monitored around the clock.</span>
                    </div>
                </div>

                <div class="rounded-2xl bg-surface-container-lowest p-5 shadow-sm ring-1 ring-outline-variant/40 flex flex-col gap-4">
                    <h2 class="font-title-md text-title-md text-primary font-bold">What happens next</h2>
                    <ol class="flex flex-col gap-4">
                        <li v-for="(step, index) in nextSteps" :key="step.title" class="flex gap-3">
                            <span class="h-7 w-7 shrink-0 rounded-full bg-secondary-container text-on-secondary-container font-label-md text-label-md font-bold flex items-center justify-center">{{ index + 1 }}</span>
                            <span class="flex flex-col gap-0.5">
                                <span class="font-label-md text-label-md text-on-surface font-semibold">{{ step.title }}</span>
                                <span class="font-body-sm text-body-sm text-on-surface-variant">{{ step.text }}</span>
                            </span>
                        </li>
                    </ol>
                </div>

                <p class="px-1 font-body-sm text-body-sm text-outline">
                    Hospital staff?
                    <Link href="/login" class="text-primary font-semibold">Sign in</Link>
                    to report with your account.
                </p>
            </aside>
        </div>
    </PublicLayout>
</template>
