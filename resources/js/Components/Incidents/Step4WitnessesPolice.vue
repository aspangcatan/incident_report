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
            <h3 class="font-title-md text-title-md text-on-surface font-semibold">Witnesses</h3>

            <div v-if="form.witnesses.length === 0" class="flex flex-col items-center gap-3 p-6 rounded-xl border-2 border-dashed border-outline-variant text-center">
                <span class="font-body-md text-body-md text-on-surface-variant">No witnesses recorded. Add anyone who saw what happened.</span>
                <button type="button" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold" @click="addWitness(form)">
                    <FontAwesomeIcon icon="user-plus" /> Add a witness
                </button>
            </div>

            <div v-for="(witness, index) in form.witnesses" :key="witness._key ?? index" class="p-4 rounded-xl bg-surface-container-lowest ring-1 ring-outline-variant/60 flex flex-col gap-3">
                <div class="flex justify-between items-center">
                    <span class="inline-flex items-center gap-2 font-title-sm text-title-sm text-primary font-semibold"><FontAwesomeIcon icon="eye" /> Witness {{ index + 1 }}</span>
                    <button type="button" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-error hover:bg-error-container font-label-md text-label-md" @click="removeWitness(form, index)"><FontAwesomeIcon icon="trash" /> Remove</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-space-sm">
                    <div class="flex flex-col gap-1.5">
                        <label class="form-label" :for="'witness-name-' + index">Full name *</label>
                        <input :id="'witness-name-' + index" v-model="witness.name" type="text" class="form-input" />
                        <span v-if="form.errors[`witnesses.${index}.name`]" class="form-error">{{ form.errors[`witnesses.${index}.name`] }}</span>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="form-label" :for="'witness-designation-' + index">Designation</label>
                        <input :id="'witness-designation-' + index" v-model="witness.designation" type="text" class="form-input" />
                        <span v-if="form.errors[`witnesses.${index}.designation`]" class="form-error">{{ form.errors[`witnesses.${index}.designation`] }}</span>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="form-label" :for="'witness-address-' + index">Address</label>
                        <input :id="'witness-address-' + index" v-model="witness.address" type="text" class="form-input" />
                        <span v-if="form.errors[`witnesses.${index}.address`]" class="form-error">{{ form.errors[`witnesses.${index}.address`] }}</span>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="form-label" :for="'witness-contact-' + index">Contact number</label>
                        <input :id="'witness-contact-' + index" v-model="witness.contact_number" type="text" class="form-input" />
                        <span v-if="form.errors[`witnesses.${index}.contact_number`]" class="form-error">{{ form.errors[`witnesses.${index}.contact_number`] }}</span>
                    </div>
                    <div class="flex flex-col gap-1.5 md:col-span-2">
                        <label class="form-label" :for="'witness-statement-' + index">Statement</label>
                        <textarea :id="'witness-statement-' + index" v-model="witness.statement" class="form-input" rows="2" />
                        <span v-if="form.errors[`witnesses.${index}.statement`]" class="form-error">{{ form.errors[`witnesses.${index}.statement`] }}</span>
                    </div>
                </div>
            </div>
            <button v-if="form.witnesses.length" type="button" class="self-start inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-surface-container-lowest text-primary ring-1 ring-outline-variant hover:bg-surface-container-low font-label-md text-label-md font-semibold" @click="addWitness(form)">
                <FontAwesomeIcon icon="user-plus" /> Add another witness
            </button>
        </div>

        <div class="flex flex-col gap-3 pt-5 border-t border-outline-variant/50">
            <h3 class="font-title-md text-title-md text-on-surface font-semibold">Police or external agency</h3>
            <label class="choice-tile">
                <input v-model="form.police_notified" type="checkbox" />
                <span>The Philippine National Police (PNP) or an external agency was notified</span>
            </label>
            <div v-if="form.police_notified" class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-1">
                <div class="flex flex-col gap-1.5">
                    <label for="police_station" class="form-label">Police station / precinct</label>
                    <input id="police_station" v-model="form.police_station" type="text" class="form-input" />
                    <span v-if="form.errors.police_station" class="form-error">{{ form.errors.police_station }}</span>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="police_officer_in_charge" class="form-label">Officer-in-charge</label>
                    <input id="police_officer_in_charge" v-model="form.police_officer_in_charge" type="text" class="form-input" />
                    <span v-if="form.errors.police_officer_in_charge" class="form-error">{{ form.errors.police_officer_in_charge }}</span>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="police_blotter_no" class="form-label">Blotter reference no.</label>
                    <input id="police_blotter_no" v-model="form.police_blotter_no" type="text" class="form-input" />
                    <span v-if="form.errors.police_blotter_no" class="form-error">{{ form.errors.police_blotter_no }}</span>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="police_notified_at" class="form-label">Date & time notified</label>
                    <input id="police_notified_at" v-model="form.police_notified_at" type="datetime-local" class="form-input" />
                    <span v-if="form.errors.police_notified_at" class="form-error">{{ form.errors.police_notified_at }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
