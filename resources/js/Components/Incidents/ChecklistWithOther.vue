<script setup>
import { ref, watch } from 'vue';

// A "tick all that apply" list ending in "Others (Specify)" with its own text box.
const props = defineProps({
    form: { type: Object, required: true },
    field: { type: String, required: true },
    otherField: { type: String, required: true },
    options: { type: Array, required: true }, // [{ value, label }]
    label: { type: String, required: true },
    hint: { type: String, default: 'Tick all that apply.' },
    idPrefix: { type: String, required: true },
});

const otherChecked = ref(!!props.form[props.otherField]);

watch(otherChecked, (checked) => {
    if (!checked) props.form[props.otherField] = '';
});

function itemError() {
    const key = Object.keys(props.form.errors).find((k) => k.startsWith(props.field + '.'));
    return key ? props.form.errors[key] : null;
}
</script>

<template>
    <fieldset class="flex flex-col gap-1.5">
        <legend class="font-label-md text-label-md text-on-surface font-semibold">{{ label }} *</legend>
        <p class="font-body-sm text-body-sm text-outline">{{ hint }}</p>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-space-md gap-y-2 mt-1">
            <label
                v-for="option in options"
                :key="option.value"
                class="flex items-start gap-2 font-body-md text-body-md text-on-surface"
            >
                <input v-model="form[field]" type="checkbox" :value="option.value" class="mt-1" />
                <span>{{ option.label }}</span>
            </label>
            <label class="flex items-start gap-2 font-body-md text-body-md text-on-surface">
                <input v-model="otherChecked" type="checkbox" class="mt-1" />
                <span>Others (Specify)</span>
            </label>
        </div>

        <div v-if="otherChecked" class="flex flex-col gap-1.5 mt-1">
            <label :for="idPrefix + '_other'" class="font-label-md text-label-md text-on-surface font-semibold">Please specify *</label>
            <input
                :id="idPrefix + '_other'"
                v-model="form[otherField]"
                type="text"
                maxlength="255"
                class="w-full p-3 rounded-lg bg-surface-container-low"
            />
            <span v-if="form.errors[otherField]" class="font-body-sm text-body-sm text-error">{{ form.errors[otherField] }}</span>
        </div>

        <span v-if="form.errors[field]" class="font-body-sm text-body-sm text-error">{{ form.errors[field] }}</span>
        <span v-if="itemError()" class="font-body-sm text-body-sm text-error">{{ itemError() }}</span>
    </fieldset>
</template>
