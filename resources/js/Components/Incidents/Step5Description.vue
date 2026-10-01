<script setup>
defineProps({
    form: { type: Object, required: true },
    // Files already saved with this draft (they stay; new ones are added).
    existingAttachments: { type: Array, default: () => [] },
});

let nextEventKey = 0;

function addEvent(form) {
    form.narrative_events.push({ _key: nextEventKey++, occurred_at: '', description: '' });
}

function removeEvent(form, index) {
    form.narrative_events.splice(index, 1);
}

function onFilesSelected(form, event) {
    form.attachments.push(...Array.from(event.target.files));
    event.target.value = '';
}

function removeAttachment(form, index) {
    form.attachments.splice(index, 1);
}
</script>

<template>
    <div class="flex flex-col gap-space-lg">
        <h2 class="font-title-lg text-title-lg text-primary font-bold">Section 6: Description & Evidence</h2>

        <div class="flex flex-col gap-1.5">
            <label for="summary" class="font-label-md text-label-md text-on-surface font-semibold">Complete description of incident *</label>
            <textarea id="summary" v-model="form.summary" rows="4" class="w-full p-3 rounded-lg bg-surface-container-low" />
            <span v-if="form.errors.summary" class="font-body-sm text-body-sm text-error">{{ form.errors.summary }}</span>
        </div>

        <div class="flex flex-col gap-space-sm">
            <div class="flex items-start justify-between gap-space-md">
                <div class="flex flex-col gap-1">
                    <span class="font-label-md text-label-md text-on-surface font-semibold">
                        Chronological Sequence of Events <span class="font-normal text-outline">(optional)</span>
                    </span>
                    <span class="font-body-sm text-body-sm text-outline">
                        Break the incident down into steps, in the order they happened, with the time of each. This helps the investigator see exactly what happened when.
                    </span>
                </div>
                <button v-if="form.narrative_events.length" type="button" class="font-label-sm text-label-sm font-bold text-primary whitespace-nowrap" @click="addEvent(form)">+ Add Event</button>
            </div>

            <div v-if="form.narrative_events.length === 0" class="p-space-md rounded-lg border-2 border-dashed border-outline-variant bg-surface-container-low flex flex-col items-center gap-2 text-center">
                <span class="font-body-sm text-body-sm text-on-surface-variant">No events added yet. For example:</span>
                <span class="font-body-sm text-body-sm text-outline italic">
                    2:30 PM: Patient found on the floor beside the bed. &nbsp;·&nbsp; 2:35 PM: Nurse on duty checked vital signs and called the doctor.
                </span>
                <button type="button" class="mt-1 px-4 py-2 rounded-lg bg-surface-container-high text-primary font-label-md text-label-md font-semibold" @click="addEvent(form)">
                    + Add the first event
                </button>
            </div>
            <div v-for="(event, index) in form.narrative_events" :key="event._key ?? index" class="flex flex-col gap-space-sm bg-surface-container-low p-2.5 rounded-lg">
                <div class="flex justify-between items-center">
                    <span class="font-label-sm text-label-sm uppercase text-outline">Event {{ index + 1 }}</span>
                    <button type="button" class="text-error font-label-sm text-body-sm" @click="removeEvent(form, index)">Remove</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-[10rem_1fr] gap-space-sm">
                    <div class="flex flex-col gap-1.5">
                        <label class="font-label-md text-label-md text-on-surface font-semibold" :for="'event-occurred-at-' + index">Time</label>
                        <span class="font-body-sm text-body-sm text-outline">e.g. 2:30 PM</span>
                        <input :id="'event-occurred-at-' + index" v-model="event.occurred_at" type="text" class="p-2 rounded bg-surface-container-lowest" />
                        <span v-if="form.errors[`narrative_events.${index}.occurred_at`]" class="font-body-sm text-body-sm text-error">{{ form.errors[`narrative_events.${index}.occurred_at`] }}</span>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="font-label-md text-label-md text-on-surface font-semibold" :for="'event-description-' + index">What happened *</label>
                        <span class="font-body-sm text-body-sm text-outline">One step of the incident, in the order it happened.</span>
                        <input :id="'event-description-' + index" v-model="event.description" type="text" class="p-2 rounded bg-surface-container-lowest" />
                        <span v-if="form.errors[`narrative_events.${index}.description`]" class="font-body-sm text-body-sm text-error">{{ form.errors[`narrative_events.${index}.description`] }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-space-sm">
            <label class="font-label-md text-label-md text-on-surface font-semibold">Evidence & Attachments</label>
            <label class="p-space-lg rounded-xl bg-surface-container-low border-2 border-dashed border-outline-variant flex flex-col items-center justify-center text-center gap-2 cursor-pointer">
                <FontAwesomeIcon icon="cloud-arrow-up" class="text-title-lg text-primary" />
                <span class="font-title-sm text-title-sm text-primary font-bold">Upload photos, documents, or reports</span>
                <span class="font-body-sm text-body-sm text-outline">PDF, PNG, JPG, DOC — max 25MB per file</span>
                <input type="file" multiple class="hidden" @change="onFilesSelected(form, $event)" />
            </label>
            <a
                v-for="file in existingAttachments"
                :key="'saved-' + file.id"
                :href="`/attachments/${file.id}`"
                class="flex items-center justify-between p-2.5 rounded-lg bg-surface-container-low hover:bg-surface-container"
            >
                <span class="font-body-sm text-body-sm text-on-surface truncate">{{ file.original_filename }}</span>
                <span class="font-body-sm text-body-sm text-outline whitespace-nowrap">Already uploaded</span>
            </a>
            <div v-for="(file, index) in form.attachments" :key="index" class="flex items-center justify-between p-2.5 rounded-lg bg-surface-container-low">
                <span class="font-body-sm text-body-sm text-on-surface truncate">{{ file.name }}</span>
                <button type="button" class="text-error" @click="removeAttachment(form, index)">
                    <FontAwesomeIcon icon="trash" />
                </button>
            </div>
        </div>
    </div>
</template>
