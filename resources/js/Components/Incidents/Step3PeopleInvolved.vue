<script setup>
defineProps({
    form: { type: Object, required: true },
});

const personTypes = ['patient', 'staff', 'visitor', 'other'];

function addIndividual(form) {
    form.individuals.push({ person_type: 'patient', name: '', identifier: '', role_description: '', details: '' });
}

function removeIndividual(form, index) {
    form.individuals.splice(index, 1);
}
</script>

<template>
    <div class="flex flex-col gap-space-md">
        <div class="flex items-center justify-between">
            <h2 class="font-title-lg text-title-lg text-primary font-bold">Section 3: People Involved</h2>
            <button type="button" class="font-label-sm text-label-sm font-bold text-primary" @click="addIndividual(form)">
                + Add Person
            </button>
        </div>

        <div v-if="form.individuals.length === 0" class="p-space-md rounded-lg bg-surface-container-low text-center font-body-sm text-body-sm text-outline">
            No individuals added yet.
        </div>

        <div v-for="(person, index) in form.individuals" :key="index" class="p-space-md rounded-lg bg-surface-container-low flex flex-col gap-space-sm">
            <div class="flex justify-between items-center">
                <span class="font-label-sm text-label-sm uppercase text-outline">Person {{ index + 1 }}</span>
                <button type="button" class="text-error font-label-sm text-body-sm" @click="removeIndividual(form, index)">Remove</button>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-space-sm">
                <select v-model="person.person_type" class="p-2.5 rounded-lg bg-surface-container-lowest">
                    <option v-for="type in personTypes" :key="type" :value="type">{{ type }}</option>
                </select>
                <input v-model="person.name" type="text" placeholder="Full name" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                <input v-model="person.identifier" type="text" placeholder="HRN / Employee No." class="p-2.5 rounded-lg bg-surface-container-lowest" />
                <input v-model="person.role_description" type="text" placeholder="Role / designation" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                <textarea v-model="person.details" placeholder="Additional details" class="p-2.5 rounded-lg bg-surface-container-lowest md:col-span-2" rows="2" />
            </div>
        </div>
    </div>
</template>
