<script setup>
defineProps({
    form: { type: Object, required: true },
});

let nextKey = 0;

function addWitness(form) {
    form.witnesses.push({ _key: nextKey++, name: '', designation: '', address: '', contact_number: '', statement: '' });
}

function removeWitness(form, index) {
    form.witnesses.splice(index, 1);
}
</script>

<template>
    <div class="flex flex-col gap-space-lg">
        <div class="flex flex-col gap-space-md">
            <div class="flex items-center justify-between">
                <h2 class="font-title-lg text-title-lg text-primary font-bold">Section 5: Witnesses</h2>
                <button type="button" class="font-label-sm text-label-sm font-bold text-primary" @click="addWitness(form)">
                    + Add Witness
                </button>
            </div>

            <div v-if="form.witnesses.length === 0" class="p-space-md rounded-lg bg-surface-container-low text-center font-body-sm text-body-sm text-outline">
                No witnesses recorded.
            </div>

            <div v-for="(witness, index) in form.witnesses" :key="witness._key ?? index" class="p-space-md rounded-lg bg-surface-container-low flex flex-col gap-space-sm">
                <div class="flex justify-between items-center">
                    <span class="font-label-sm text-label-sm uppercase text-outline">Witness {{ index + 1 }}</span>
                    <button type="button" class="text-error font-label-sm text-body-sm" @click="removeWitness(form, index)">Remove</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-space-sm">
                    <div class="flex flex-col gap-1.5">
                        <label class="font-label-md text-label-md text-on-surface font-semibold" :for="'witness-name-' + index">Full name *</label>
                        <input :id="'witness-name-' + index" v-model="witness.name" type="text" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                        <span v-if="form.errors[`witnesses.${index}.name`]" class="font-body-sm text-body-sm text-error">{{ form.errors[`witnesses.${index}.name`] }}</span>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="font-label-md text-label-md text-on-surface font-semibold" :for="'witness-designation-' + index">Designation</label>
                        <input :id="'witness-designation-' + index" v-model="witness.designation" type="text" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                        <span v-if="form.errors[`witnesses.${index}.designation`]" class="font-body-sm text-body-sm text-error">{{ form.errors[`witnesses.${index}.designation`] }}</span>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="font-label-md text-label-md text-on-surface font-semibold" :for="'witness-address-' + index">Address</label>
                        <input :id="'witness-address-' + index" v-model="witness.address" type="text" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                        <span v-if="form.errors[`witnesses.${index}.address`]" class="font-body-sm text-body-sm text-error">{{ form.errors[`witnesses.${index}.address`] }}</span>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="font-label-md text-label-md text-on-surface font-semibold" :for="'witness-contact-' + index">Contact number</label>
                        <input :id="'witness-contact-' + index" v-model="witness.contact_number" type="text" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                        <span v-if="form.errors[`witnesses.${index}.contact_number`]" class="font-body-sm text-body-sm text-error">{{ form.errors[`witnesses.${index}.contact_number`] }}</span>
                    </div>
                    <div class="flex flex-col gap-1.5 md:col-span-2">
                        <label class="font-label-md text-label-md text-on-surface font-semibold" :for="'witness-statement-' + index">Statement</label>
                        <textarea :id="'witness-statement-' + index" v-model="witness.statement" class="p-2.5 rounded-lg bg-surface-container-lowest" rows="2" />
                        <span v-if="form.errors[`witnesses.${index}.statement`]" class="font-body-sm text-body-sm text-error">{{ form.errors[`witnesses.${index}.statement`] }}</span>
                    </div>
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
                <div class="flex flex-col gap-1.5">
                    <label for="police_station" class="font-label-md text-label-md text-on-surface font-semibold">Police station / precinct</label>
                    <input id="police_station" v-model="form.police_station" type="text" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                    <span v-if="form.errors.police_station" class="font-body-sm text-body-sm text-error">{{ form.errors.police_station }}</span>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="police_officer_in_charge" class="font-label-md text-label-md text-on-surface font-semibold">Officer-in-charge</label>
                    <input id="police_officer_in_charge" v-model="form.police_officer_in_charge" type="text" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                    <span v-if="form.errors.police_officer_in_charge" class="font-body-sm text-body-sm text-error">{{ form.errors.police_officer_in_charge }}</span>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="police_blotter_no" class="font-label-md text-label-md text-on-surface font-semibold">Blotter reference no.</label>
                    <input id="police_blotter_no" v-model="form.police_blotter_no" type="text" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                    <span v-if="form.errors.police_blotter_no" class="font-body-sm text-body-sm text-error">{{ form.errors.police_blotter_no }}</span>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="police_notified_at" class="font-label-md text-label-md text-on-surface font-semibold">Date & time notified</label>
                    <input id="police_notified_at" v-model="form.police_notified_at" type="datetime-local" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                    <span v-if="form.errors.police_notified_at" class="font-body-sm text-body-sm text-error">{{ form.errors.police_notified_at }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
