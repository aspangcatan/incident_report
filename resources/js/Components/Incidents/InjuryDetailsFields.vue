<script setup>
import { computed, watch } from 'vue';
import ChecklistWithOther from '@/Components/Incidents/ChecklistWithOther.vue';

const props = defineProps({
    form: { type: Object, required: true },
    injuryOptions: { type: Object, required: true }, // { causes: {value: label}, agents: {value: label} }
});

const toList = (map) => Object.entries(map).map(([value, label]) => ({ value, label }));
const causes = computed(() => toList(props.injuryOptions.causes));
const agents = computed(() => toList(props.injuryOptions.agents));

// Chemical details belong only to the Chemicals agent.
watch(() => props.form.injury_agents.includes('chemicals'), (ticked) => {
    if (!ticked) props.form.injury_chemical_details = '';
});
</script>

<template>
    <div class="flex flex-col gap-space-md">
        <fieldset class="flex flex-col gap-1.5">
            <legend class="font-label-md text-label-md text-on-surface font-semibold">Was anyone injured? *</legend>
            <p class="font-body-sm text-body-sm text-outline">If yes, tell us the cause and agent of the injury.</p>
            <div class="flex items-center gap-space-md mt-1">
                <label class="flex items-center gap-2 font-body-md text-body-md text-on-surface">
                    <input v-model="form.has_injury" type="radio" name="has_injury" :value="true" /> Yes
                </label>
                <label class="flex items-center gap-2 font-body-md text-body-md text-on-surface">
                    <input v-model="form.has_injury" type="radio" name="has_injury" :value="false" /> No
                </label>
            </div>
            <span v-if="form.errors.has_injury" class="font-body-sm text-body-sm text-error">{{ form.errors.has_injury }}</span>
        </fieldset>

        <template v-if="form.has_injury">
            <ChecklistWithOther
                :form="form"
                field="injury_causes"
                other-field="injury_cause_other"
                :options="causes"
                label="Cause of Injury"
                hint="How did the injury happen? Tick all that apply."
                id-prefix="injury_cause"
            />
            <ChecklistWithOther
                :form="form"
                field="injury_agents"
                other-field="injury_agent_other"
                :options="agents"
                label="Agent of Injury"
                hint="What object, substance or person caused the injury? Tick all that apply."
                id-prefix="injury_agent"
            />

            <div v-if="form.injury_agents.includes('chemicals')" class="flex flex-col gap-1.5">
                <label for="injury_chemical_details" class="font-label-md text-label-md text-on-surface font-semibold">Chemical involved *</label>
                <p class="font-body-sm text-body-sm text-outline">Name of the chemical and how it was involved (e.g. splashed, inhaled).</p>
                <input
                    id="injury_chemical_details"
                    v-model="form.injury_chemical_details"
                    type="text"
                    maxlength="255"
                    class="w-full p-3 rounded-lg bg-surface-container-low"
                />
                <span v-if="form.errors.injury_chemical_details" class="font-body-sm text-body-sm text-error">{{ form.errors.injury_chemical_details }}</span>
            </div>
        </template>
    </div>
</template>
