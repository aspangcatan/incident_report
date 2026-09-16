<script setup>
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    incident: { type: Object, required: true },
    can: { type: Object, required: true },
    investigators: { type: Array, default: () => [] },
});

const reviewForm = useForm({ comments: '' });
const assignForm = useForm({ assigned_investigator_id: null, target_closure_date: '' });

function markReviewed() {
    reviewForm.post(`/incidents/${props.incident.id}/review`, {
        preserveScroll: true,
        onSuccess: () => reviewForm.reset(),
    });
}

function returnForRevision() {
    if (!reviewForm.comments.trim()) {
        reviewForm.setError('comments', 'A comment is required when returning an incident for revision.');
        return;
    }
    reviewForm.post(`/incidents/${props.incident.id}/return`, { preserveScroll: true });
}

function assignInvestigator() {
    assignForm.post(`/incidents/${props.incident.id}/assign`, {
        preserveScroll: true,
        onSuccess: () => assignForm.reset(),
    });
}
</script>

<template>
    <div
        v-if="can.review && ['submitted', 'for_review'].includes(incident.status)"
        class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-sm"
    >
        <h2 class="font-title-lg text-title-lg text-primary font-bold">Review This Incident</h2>
        <textarea
            v-model="reviewForm.comments"
            rows="3"
            placeholder="Review comments (required if returning for revision)"
            class="w-full p-3 rounded-lg bg-surface-container-low"
        />
        <span v-if="reviewForm.errors.comments" class="font-body-sm text-body-sm text-error">{{ reviewForm.errors.comments }}</span>
        <div class="flex gap-2">
            <button
                type="button"
                :disabled="reviewForm.processing"
                class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60"
                @click="markReviewed"
            >
                Mark Reviewed
            </button>
            <button
                type="button"
                :disabled="reviewForm.processing"
                class="px-4 py-2 rounded-lg bg-surface-container text-on-surface font-label-md text-label-md disabled:opacity-60"
                @click="returnForRevision"
            >
                Return for Revision
            </button>
        </div>
    </div>

    <div
        v-if="can.assign && incident.status === 'reviewed'"
        class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-sm"
    >
        <h2 class="font-title-lg text-title-lg text-primary font-bold">Assign Investigator</h2>
        <select v-model="assignForm.assigned_investigator_id" class="w-full p-3 rounded-lg bg-surface-container-low">
            <option :value="null" disabled>Select an investigator</option>
            <option v-for="investigator in investigators" :key="investigator.id" :value="investigator.id">
                {{ investigator.name }}
            </option>
        </select>
        <span v-if="assignForm.errors.assigned_investigator_id" class="font-body-sm text-body-sm text-error">
            {{ assignForm.errors.assigned_investigator_id }}
        </span>
        <input v-model="assignForm.target_closure_date" type="date" class="w-full p-3 rounded-lg bg-surface-container-low" />
        <button
            type="button"
            :disabled="assignForm.processing"
            class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60 w-fit"
            @click="assignInvestigator"
        >
            Assign Investigator
        </button>
    </div>
</template>
