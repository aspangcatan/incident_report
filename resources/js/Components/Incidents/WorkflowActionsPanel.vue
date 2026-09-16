<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ConfirmationDialog from '@/Components/ConfirmationDialog.vue';

const props = defineProps({
    incident: { type: Object, required: true },
    can: { type: Object, required: true },
    investigators: { type: Array, default: () => [] },
});

const reviewForm = useForm({ comments: '' });
const assignForm = useForm({ assigned_investigator_id: null, target_closure_date: '' });

const showReviewConfirm = ref(false);
const showReturnConfirm = ref(false);

function confirmMarkReviewed() {
    showReviewConfirm.value = true;
}

function confirmReturnForRevision() {
    if (!reviewForm.comments.trim()) {
        reviewForm.setError('comments', 'A comment is required when returning an incident for revision.');
        return;
    }
    showReturnConfirm.value = true;
}

function markReviewed() {
    reviewForm.post(`/incidents/${props.incident.id}/review`, {
        preserveScroll: true,
        onSuccess: () => reviewForm.reset(),
        onFinish: () => (showReviewConfirm.value = false),
    });
}

function returnForRevision() {
    reviewForm.post(`/incidents/${props.incident.id}/return`, {
        preserveScroll: true,
        onFinish: () => (showReturnConfirm.value = false),
    });
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
        <label class="font-label-md text-label-md text-on-surface font-semibold" for="review_comments">Review comments</label>
        <textarea
            id="review_comments"
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
                @click="confirmMarkReviewed"
            >
                Mark Reviewed
            </button>
            <button
                type="button"
                :disabled="reviewForm.processing"
                class="px-4 py-2 rounded-lg bg-surface-container text-on-surface font-label-md text-label-md disabled:opacity-60"
                @click="confirmReturnForRevision"
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
        <label class="font-label-md text-label-md text-on-surface font-semibold" for="assigned_investigator_id">Investigator</label>
        <select id="assigned_investigator_id" v-model="assignForm.assigned_investigator_id" class="w-full p-3 rounded-lg bg-surface-container-low">
            <option :value="null" disabled>Select an investigator</option>
            <option v-for="investigator in investigators" :key="investigator.id" :value="investigator.id">
                {{ investigator.name }}
            </option>
        </select>
        <span v-if="assignForm.errors.assigned_investigator_id" class="font-body-sm text-body-sm text-error">
            {{ assignForm.errors.assigned_investigator_id }}
        </span>
        <label class="font-label-md text-label-md text-on-surface font-semibold" for="target_closure_date">Target closure date</label>
        <input id="target_closure_date" v-model="assignForm.target_closure_date" type="date" class="w-full p-3 rounded-lg bg-surface-container-low" />
        <button
            type="button"
            :disabled="assignForm.processing"
            class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60 w-fit"
            @click="assignInvestigator"
        >
            Assign Investigator
        </button>
    </div>

    <ConfirmationDialog
        :show="showReviewConfirm"
        title="Mark this incident reviewed?"
        message="This confirms the report is complete and moves it forward to assignment. This cannot be undone from here."
        confirm-label="Mark Reviewed"
        :processing="reviewForm.processing"
        @cancel="showReviewConfirm = false"
        @confirm="markReviewed"
    />

    <ConfirmationDialog
        :show="showReturnConfirm"
        title="Return this incident for revision?"
        message="This sends the report back to the reporter as a draft for editing. They will need to resubmit it."
        confirm-label="Return for Revision"
        :processing="reviewForm.processing"
        @cancel="showReturnConfirm = false"
        @confirm="returnForRevision"
    />
</template>
