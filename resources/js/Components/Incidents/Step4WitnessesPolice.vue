<script setup>
defineProps({
    form: { type: Object, required: true },
});

function addWitness(form) {
    form.witnesses.push({ name: '', designation: '', address: '', contact_number: '', statement: '' });
}

function removeWitness(form, index) {
    form.witnesses.splice(index, 1);
}
</script>

<template>
    <div class="flex flex-col gap-space-lg">
        <div class="flex flex-col gap-space-md">
            <div class="flex items-center justify-between">
                <h2 class="font-title-lg text-title-lg text-primary font-bold">Section 4: Witnesses</h2>
                <button type="button" class="font-label-sm text-label-sm font-bold text-primary" @click="addWitness(form)">
                    + Add Witness
                </button>
            </div>

            <div v-if="form.witnesses.length === 0" class="p-space-md rounded-lg bg-surface-container-low text-center font-body-sm text-body-sm text-outline">
                No witnesses recorded.
            </div>

            <div v-for="(witness, index) in form.witnesses" :key="index" class="p-space-md rounded-lg bg-surface-container-low flex flex-col gap-space-sm">
                <div class="flex justify-between items-center">
                    <span class="font-label-sm text-label-sm uppercase text-outline">Witness {{ index + 1 }}</span>
                    <button type="button" class="text-error font-label-sm text-body-sm" @click="removeWitness(form, index)">Remove</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-space-sm">
                    <input v-model="witness.name" type="text" placeholder="Full name" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                    <input v-model="witness.designation" type="text" placeholder="Designation" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                    <input v-model="witness.address" type="text" placeholder="Address" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                    <input v-model="witness.contact_number" type="text" placeholder="Contact number" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                    <textarea v-model="witness.statement" placeholder="Statement" class="p-2.5 rounded-lg bg-surface-container-lowest md:col-span-2" rows="2" />
                </div>
            </div>
        </div>

        <div class="p-space-md rounded-xl bg-surface-container-low flex flex-col gap-space-sm">
            <label class="flex items-center gap-2">
                <input v-model="form.police_notified" type="checkbox" class="w-4 h-4 rounded accent-primary" />
                <span class="font-label-md text-label-md text-on-surface font-semibold">
                    Philippine National Police (PNP) or external agency notified?
                </span>
            </label>
            <div v-if="form.police_notified" class="grid grid-cols-1 md:grid-cols-2 gap-space-sm pt-2">
                <input v-model="form.police_station" type="text" placeholder="Police station / precinct" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                <input v-model="form.police_officer_in_charge" type="text" placeholder="Officer-in-charge" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                <input v-model="form.police_blotter_no" type="text" placeholder="Blotter reference no." class="p-2.5 rounded-lg bg-surface-container-lowest" />
                <input v-model="form.police_notified_at" type="datetime-local" class="p-2.5 rounded-lg bg-surface-container-lowest" />
            </div>
        </div>
    </div>
</template>
