<script setup>
defineProps({
    form: { type: Object, required: true },
});

const personTypes = [
    { value: 'patient', label: 'Patient' },
    { value: 'staff', label: 'Staff' },
    { value: 'visitor', label: 'Visitor' },
    { value: 'other', label: 'Other' },
];

let nextKey = 0;

function addIndividual(form) {
    form.individuals.push({ _key: nextKey++, person_type: 'patient', name: '', identifier: '', role_description: '', details: '' });
}

function removeIndividual(form, index) {
    form.individuals.splice(index, 1);
}
</script>

<template>
    <div class="flex flex-col gap-space-md">
        <div v-if="form.individuals.length === 0" class="flex flex-col items-center gap-3 p-6 rounded-xl border-2 border-dashed border-outline-variant text-center">
            <span class="font-body-md text-body-md text-on-surface-variant">No one added yet. Add each patient, staff member or visitor involved.</span>
            <button type="button" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold" @click="addIndividual(form)">
                <FontAwesomeIcon icon="user-plus" /> Add a person
            </button>
        </div>

        <div v-for="(person, index) in form.individuals" :key="person._key ?? index" class="p-4 rounded-xl bg-surface-container-lowest ring-1 ring-outline-variant/60 flex flex-col gap-3">
            <div class="flex justify-between items-center">
                <span class="inline-flex items-center gap-2 font-title-sm text-title-sm text-primary font-semibold"><FontAwesomeIcon icon="user" /> Person {{ index + 1 }}</span>
                <button type="button" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-error hover:bg-error-container font-label-md text-label-md" @click="removeIndividual(form, index)"><FontAwesomeIcon icon="trash" /> Remove</button>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-space-sm">
                <div class="flex flex-col gap-1.5">
                    <label class="form-label" :for="'person-type-' + index">Person type</label>
                    <select :id="'person-type-' + index" v-model="person.person_type" class="form-input">
                        <option v-for="type in personTypes" :key="type.value" :value="type.value">{{ type.label }}</option>
                    </select>
                    <span v-if="form.errors[`individuals.${index}.person_type`]" class="form-error">{{ form.errors[`individuals.${index}.person_type`] }}</span>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="form-label" :for="'person-name-' + index">Full name *</label>
                    <input :id="'person-name-' + index" v-model="person.name" type="text" class="form-input" />
                    <span v-if="form.errors[`individuals.${index}.name`]" class="form-error">{{ form.errors[`individuals.${index}.name`] }}</span>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="form-label" :for="'person-identifier-' + index">HRN / Employee No.</label>
                    <input :id="'person-identifier-' + index" v-model="person.identifier" type="text" class="form-input" />
                    <span v-if="form.errors[`individuals.${index}.identifier`]" class="form-error">{{ form.errors[`individuals.${index}.identifier`] }}</span>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="form-label" :for="'person-role-' + index">Role / Designation</label>
                    <input :id="'person-role-' + index" v-model="person.role_description" type="text" class="form-input" />
                    <span v-if="form.errors[`individuals.${index}.role_description`]" class="form-error">{{ form.errors[`individuals.${index}.role_description`] }}</span>
                </div>
                <div class="flex flex-col gap-1.5 md:col-span-2">
                    <label class="form-label" :for="'person-details-' + index">Additional details</label>
                    <textarea :id="'person-details-' + index" v-model="person.details" class="form-input" rows="2" />
                    <span v-if="form.errors[`individuals.${index}.details`]" class="form-error">{{ form.errors[`individuals.${index}.details`] }}</span>
                </div>
            </div>
        </div>
        <button v-if="form.individuals.length" type="button" class="self-start inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-surface-container-lowest text-primary ring-1 ring-outline-variant hover:bg-surface-container-low font-label-md text-label-md font-semibold" @click="addIndividual(form)">
            <FontAwesomeIcon icon="user-plus" /> Add another person
        </button>
    </div>
</template>
