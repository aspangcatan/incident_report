<script setup>
import { BARRIER_STATUSES, FISHBONE_CATEGORIES, ROOT_CAUSE_TYPES } from '@/Components/Incidents/rca';

// The inputs for one finding of the given RCA tool, with labels, hints and errors.
defineProps({
    form: { type: Object, required: true },
    tool: { type: String, required: true },
    idPrefix: { type: String, required: true },
});

const questionLabel = { five_whys: 'Why? *', process_map: 'What should happen *', barrier: 'Barrier / defense *' };
const questionHint = {
    five_whys: 'e.g. "Why was the wrong dose given?"',
    process_map: 'The step as the policy or procedure says it should be done.',
    barrier: 'e.g. "Independent double check of high-alert drugs"',
};
const findingLabel = {
    simple: 'Finding *',
    five_whys: 'Because… *',
    fishbone: 'Cause *',
    process_map: 'What actually happened *',
    timeline: 'What happened *',
    barrier: 'Why it worked, failed or was missing *',
};
const flagLabel = { process_map: 'This step deviated from the process', timeline: 'Something went wrong here' };
</script>

<template>
    <div class="flex flex-col gap-2">
        <div v-if="tool === 'timeline'" class="flex flex-col gap-1">
            <label :for="idPrefix + '_occurred_at'" class="font-label-md text-label-md text-on-surface font-semibold">Date & time *</label>
            <input :id="idPrefix + '_occurred_at'" v-model="form.occurred_at" type="datetime-local" class="p-2 rounded-lg bg-surface-container w-full md:w-1/2" />
            <span v-if="form.errors.occurred_at" class="font-body-sm text-body-sm text-error">{{ form.errors.occurred_at }}</span>
        </div>

        <div v-if="tool === 'fishbone'" class="flex flex-col gap-1">
            <label :for="idPrefix + '_group'" class="font-label-md text-label-md text-on-surface font-semibold">Category *</label>
            <select :id="idPrefix + '_group'" v-model="form.group_name" class="p-2 rounded-lg bg-surface-container w-full md:w-1/2">
                <option value="" disabled>Choose one</option>
                <option v-for="(label, value) in FISHBONE_CATEGORIES" :key="value" :value="value">{{ label }}</option>
            </select>
            <span v-if="form.errors.group_name" class="font-body-sm text-body-sm text-error">{{ form.errors.group_name }}</span>
        </div>

        <div v-if="questionLabel[tool]" class="flex flex-col gap-1">
            <label :for="idPrefix + '_question'" class="font-label-md text-label-md text-on-surface font-semibold">{{ questionLabel[tool] }}</label>
            <span class="font-body-sm text-body-sm text-outline">{{ questionHint[tool] }}</span>
            <input :id="idPrefix + '_question'" v-model="form.question" type="text" class="p-2 rounded-lg bg-surface-container" />
            <span v-if="form.errors.question" class="font-body-sm text-body-sm text-error">{{ form.errors.question }}</span>
        </div>

        <div v-if="tool === 'barrier'" class="flex flex-col gap-1">
            <label :for="idPrefix + '_group'" class="font-label-md text-label-md text-on-surface font-semibold">Status *</label>
            <select :id="idPrefix + '_group'" v-model="form.group_name" class="p-2 rounded-lg bg-surface-container w-full md:w-1/2">
                <option value="" disabled>Choose one</option>
                <option v-for="(label, value) in BARRIER_STATUSES" :key="value" :value="value">{{ label }}</option>
            </select>
            <span v-if="form.errors.group_name" class="font-body-sm text-body-sm text-error">{{ form.errors.group_name }}</span>
        </div>

        <div class="flex flex-col gap-1">
            <label :for="idPrefix + '_finding'" class="font-label-md text-label-md text-on-surface font-semibold">{{ findingLabel[tool] }}</label>
            <textarea :id="idPrefix + '_finding'" v-model="form.finding" rows="2" class="p-2 rounded-lg bg-surface-container" />
            <span v-if="form.errors.finding" class="font-body-sm text-body-sm text-error">{{ form.errors.finding }}</span>
        </div>

        <label v-if="flagLabel[tool]" class="flex items-center gap-2 font-body-md text-body-md text-on-surface">
            <input v-model="form.is_flagged" type="checkbox" /> {{ flagLabel[tool] }}
        </label>

        <label class="flex items-center gap-2 font-body-md text-body-md text-on-surface">
            <input v-model="form.is_root_cause" type="checkbox" /> Mark as root cause
        </label>
        <div v-if="form.is_root_cause" class="flex flex-col gap-1">
            <label :for="idPrefix + '_cause_type'" class="font-label-md text-label-md text-on-surface font-semibold">Type of cause *</label>
            <span class="font-body-sm text-body-sm text-outline">What kind of problem caused the incident. Used for the hospital-wide root cause chart.</span>
            <select :id="idPrefix + '_cause_type'" v-model="form.category" class="p-2 rounded-lg bg-surface-container w-full md:w-1/2">
                <option value="" disabled>Choose one</option>
                <option v-for="(label, value) in ROOT_CAUSE_TYPES" :key="value" :value="value">{{ label }}</option>
            </select>
            <span v-if="form.errors.category" class="font-body-sm text-body-sm text-error">{{ form.errors.category }}</span>
        </div>
    </div>
</template>
