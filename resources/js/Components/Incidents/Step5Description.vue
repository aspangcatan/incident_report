<script setup>
defineProps({
    form: { type: Object, required: true },
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
            <div class="flex items-center justify-between">
                <label class="font-label-md text-label-md text-on-surface font-semibold">Chronological Sequence of Events</label>
                <button type="button" class="font-label-sm text-label-sm font-bold text-primary" @click="addEvent(form)">+ Add Event</button>
            </div>
            <div v-for="(event, index) in form.narrative_events" :key="event._key ?? index" class="flex items-start gap-2 bg-surface-container-low p-2.5 rounded-lg">
                <label class="sr-only" :for="'event-occurred-at-' + index">Time</label>
                <input :id="'event-occurred-at-' + index" v-model="event.occurred_at" type="text" placeholder="Time" class="w-32 p-2 rounded bg-surface-container-lowest" />
                <label class="sr-only" :for="'event-description-' + index">What happened</label>
                <input :id="'event-description-' + index" v-model="event.description" type="text" placeholder="What happened" class="flex-1 p-2 rounded bg-surface-container-lowest" />
                <button type="button" class="text-error" @click="removeEvent(form, index)">✕</button>
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
            <div v-for="(file, index) in form.attachments" :key="index" class="flex items-center justify-between p-2.5 rounded-lg bg-surface-container-low">
                <span class="font-body-sm text-body-sm text-on-surface truncate">{{ file.name }}</span>
                <button type="button" class="text-error" @click="removeAttachment(form, index)">
                    <FontAwesomeIcon icon="trash" />
                </button>
            </div>
        </div>
    </div>
</template>
