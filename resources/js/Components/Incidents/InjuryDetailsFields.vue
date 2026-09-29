<script setup>
import { computed } from 'vue';
import ChecklistWithOther from '@/Components/Incidents/ChecklistWithOther.vue';

const props = defineProps({
    form: { type: Object, required: true },
    injuryOptions: { type: Object, required: true }, // { causes: {value: label}, agents: {value: label} }
});

const toList = (map) => Object.entries(map).map(([value, label]) => ({ value, label }));
const causes = computed(() => toList(props.injuryOptions.causes));
const agents = computed(() => toList(props.injuryOptions.agents));
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
        </template>
    </div>
</template>
