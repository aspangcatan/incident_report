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
        <div class="flex items-center justify-between">
            <h2 class="font-title-lg text-title-lg text-primary font-bold">Section 4: People Involved</h2>
            <button type="button" class="font-label-sm text-label-sm font-bold text-primary" @click="addIndividual(form)">
                + Add Person
            </button>
        </div>

        <div v-if="form.individuals.length === 0" class="p-space-md rounded-lg bg-surface-container-low text-center font-body-sm text-body-sm text-outline">
            No individuals added yet.
        </div>

        <div v-for="(person, index) in form.individuals" :key="person._key ?? index" class="p-space-md rounded-lg bg-surface-container-low flex flex-col gap-space-sm">
            <div class="flex justify-between items-center">
                <span class="font-label-sm text-label-sm uppercase text-outline">Person {{ index + 1 }}</span>
                <button type="button" class="text-error font-label-sm text-body-sm" @click="removeIndividual(form, index)">Remove</button>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-space-sm">
                <div class="flex flex-col gap-1.5">
                    <label class="font-label-md text-label-md text-on-surface font-semibold" :for="'person-type-' + index">Person type</label>
                    <select :id="'person-type-' + index" v-model="person.person_type" class="p-2.5 rounded-lg bg-surface-container-lowest">
                        <option v-for="type in personTypes" :key="type.value" :value="type.value">{{ type.label }}</option>
                    </select>
                    <span v-if="form.errors[`individuals.${index}.person_type`]" class="font-body-sm text-body-sm text-error">{{ form.errors[`individuals.${index}.person_type`] }}</span>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="font-label-md text-label-md text-on-surface font-semibold" :for="'person-name-' + index">Full name *</label>
                    <input :id="'person-name-' + index" v-model="person.name" type="text" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                    <span v-if="form.errors[`individuals.${index}.name`]" class="font-body-sm text-body-sm text-error">{{ form.errors[`individuals.${index}.name`] }}</span>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="font-label-md text-label-md text-on-surface font-semibold" :for="'person-identifier-' + index">HRN / Employee No.</label>
                    <input :id="'person-identifier-' + index" v-model="person.identifier" type="text" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                    <span v-if="form.errors[`individuals.${index}.identifier`]" class="font-body-sm text-body-sm text-error">{{ form.errors[`individuals.${index}.identifier`] }}</span>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="font-label-md text-label-md text-on-surface font-semibold" :for="'person-role-' + index">Role / Designation</label>
                    <input :id="'person-role-' + index" v-model="person.role_description" type="text" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                    <span v-if="form.errors[`individuals.${index}.role_description`]" class="font-body-sm text-body-sm text-error">{{ form.errors[`individuals.${index}.role_description`] }}</span>
                </div>
                <div class="flex flex-col gap-1.5 md:col-span-2">
                    <label class="font-label-md text-label-md text-on-surface font-semibold" :for="'person-details-' + index">Additional details</label>
                    <textarea :id="'person-details-' + index" v-model="person.details" class="p-2.5 rounded-lg bg-surface-container-lowest" rows="2" />
                    <span v-if="form.errors[`individuals.${index}.details`]" class="font-body-sm text-body-sm text-error">{{ form.errors[`individuals.${index}.details`] }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
