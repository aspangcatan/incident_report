<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ConfirmationDialog from '@/Components/ConfirmationDialog.vue';
import { SEVERITIES as severities } from '@/Utils/severities';

const props = defineProps({
    incident: { type: Object, required: true },
    can: { type: Object, required: true },
    investigators: { type: Array, default: () => [] },
});

// CQI triage: confirm or change the department's severity.
const reviewForm = useForm({ comments: '', severity: props.incident.severity ?? null });
// The department's recommendation is pre-selected; the CQI Office decides.
const assignForm = useForm({ assigned_investigator_id: props.incident.recommended_investigator_id ?? null, target_closure_date: '' });
const skipForm = useForm({ reason: '' });

const showReviewConfirm = ref(false);
const showReturnConfirm = ref(false);
const showSkipConfirm = ref(false);

const severityChanged = computed(() => reviewForm.severity !== props.incident.severity);

function confirmMarkReviewed() {
    showReviewConfirm.value = true;
}

function confirmReturnForRevision() {
    if (!reviewForm.comments.trim()) {
        reviewForm.setError('comments', 'A comment is required when returning an incident to the department.');
        return;
    }
    showReturnConfirm.value = true;
}

function confirmSkip() {
    if (!skipForm.reason.trim()) {
        skipForm.setError('reason', 'Explain why no investigation is needed.');
        return;
    }
    showSkipConfirm.value = true;
}

function markReviewed() {
    reviewForm.post(`/incidents/${props.incident.id}/review`, {
        preserveScroll: true,
        onSuccess: () => reviewForm.reset('comments'),
        onFinish: () => (showReviewConfirm.value = false),
    });
}

function returnForRevision() {
    reviewForm.post(`/incidents/${props.incident.id}/return-to-department`, {
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

function skipInvestigation() {
    skipForm.post(`/incidents/${props.incident.id}/skip-investigation`, {
        preserveScroll: true,
        onFinish: () => (showSkipConfirm.value = false),
    });
}
</script>

<template>
    <div
        v-if="can.review && incident.status === 'for_review'"
        class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md"
    >
        <div>
            <h2 class="font-title-lg text-title-lg text-primary font-bold">CQI Triage</h2>
            <p class="font-body-sm text-body-sm text-outline">Confirm the department's classification or change it. Changing it alerts the people the new level requires.</p>
        </div>

        <fieldset class="flex flex-col gap-1.5">
            <legend class="font-label-md text-label-md text-on-surface font-semibold">Severity *</legend>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-2 mt-1">
                <label
                    v-for="option in severities"
                    :key="option.value"
                    class="p-3 rounded-lg cursor-pointer flex flex-col gap-1 focus-within:ring-2 focus-within:ring-primary"
                    :class="reviewForm.severity === option.value ? 'bg-amber-50 ring-2 ring-amber-500' : 'bg-surface-container-low hover:bg-surface-container'"
                >
                    <input v-model="reviewForm.severity" type="radio" name="triage_severity" :value="option.value" class="sr-only" />
                    <span class="font-label-sm text-body-sm text-outline">{{ option.numeral }}</span>
                    <span class="font-body-md text-body-md text-on-surface font-semibold">{{ option.label }}</span>
                    <span class="font-body-sm text-body-sm text-on-surface-variant">{{ option.meaning }}</span>
                </label>
            </div>
            <span v-if="severityChanged" class="font-body-sm text-body-sm text-amber-900">This changes the severity the department set.</span>
            <span v-if="reviewForm.errors.severity" class="font-body-sm text-body-sm text-error">{{ reviewForm.errors.severity }}</span>
        </fieldset>

        <div class="flex flex-col gap-1.5">
            <label class="font-label-md text-label-md text-on-surface font-semibold" for="review_comments">Triage comments</label>
            <span class="font-body-sm text-body-sm text-outline">Optional, but required if you return it to the department.</span>
            <textarea id="review_comments" v-model="reviewForm.comments" rows="3" class="w-full p-3 rounded-lg bg-surface-container-low" />
            <span v-if="reviewForm.errors.comments" class="font-body-sm text-body-sm text-error">{{ reviewForm.errors.comments }}</span>
        </div>

        <div class="flex gap-2">
            <button
                type="button"
                :disabled="reviewForm.processing"
                class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60"
                @click="confirmMarkReviewed"
            >
                Confirm Triage
            </button>
            <button
                type="button"
                :disabled="reviewForm.processing"
                class="px-4 py-2 rounded-lg bg-surface-container text-on-surface font-label-md text-label-md disabled:opacity-60"
                @click="confirmReturnForRevision"
            >
                Return to Department
            </button>
        </div>
    </div>

    <div
        v-if="can.assign && incident.status === 'reviewed'"
        class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md"
    >
        <h2 class="font-title-lg text-title-lg text-primary font-bold">Investigation</h2>

        <div class="flex flex-col gap-1.5">
            <label class="font-label-md text-label-md text-on-surface font-semibold" for="assigned_investigator_id">Investigator *</label>
            <span v-if="incident.recommended_investigator" class="font-body-sm text-body-sm text-outline">
                The department recommended {{ incident.recommended_investigator.name }}.
            </span>
            <select id="assigned_investigator_id" v-model="assignForm.assigned_investigator_id" class="w-full md:w-1/2 p-3 rounded-lg bg-surface-container-low">
                <option :value="null" disabled>Select an investigator</option>
                <option v-for="investigator in investigators" :key="investigator.id" :value="investigator.id">
                    {{ investigator.label }}
                </option>
            </select>
            <span v-if="assignForm.errors.assigned_investigator_id" class="font-body-sm text-body-sm text-error">
                {{ assignForm.errors.assigned_investigator_id }}
            </span>
        </div>

        <div class="flex flex-col gap-1.5">
            <label class="font-label-md text-label-md text-on-surface font-semibold" for="target_closure_date">Target closure date</label>
            <span class="font-body-sm text-body-sm text-outline">Optional. Leave empty to use the standard deadline for this severity.</span>
            <input id="target_closure_date" v-model="assignForm.target_closure_date" type="date" class="w-full md:w-1/2 p-3 rounded-lg bg-surface-container-low" />
            <span v-if="assignForm.errors.target_closure_date" class="font-body-sm text-body-sm text-error">{{ assignForm.errors.target_closure_date }}</span>
        </div>

        <button
            type="button"
            :disabled="assignForm.processing"
            class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60 w-fit"
            @click="assignInvestigator"
        >
            Assign Investigator
        </button>

        <div v-if="can.skipInvestigation" class="flex flex-col gap-1.5 pt-space-sm border-t border-outline-variant">
            <label class="font-label-md text-label-md text-on-surface font-semibold" for="skip_reason">Or: no investigation needed</label>
            <span class="font-body-sm text-body-sm text-outline">Only for Low or Moderate incidents. The department goes straight to corrective actions.</span>
            <textarea id="skip_reason" v-model="skipForm.reason" rows="2" class="w-full p-3 rounded-lg bg-surface-container-low" placeholder="Why is no investigation needed?" />
            <span v-if="skipForm.errors.reason" class="font-body-sm text-body-sm text-error">{{ skipForm.errors.reason }}</span>
            <button
                type="button"
                :disabled="skipForm.processing"
                class="px-4 py-2 rounded-lg bg-surface-container text-on-surface font-label-md text-label-md disabled:opacity-60 w-fit"
                @click="confirmSkip"
            >
                No Investigation Needed
            </button>
        </div>
        <p v-else class="font-body-sm text-body-sm text-outline pt-space-sm border-t border-outline-variant">
            High and Sentinel incidents must be investigated.
        </p>
    </div>

    <ConfirmationDialog
        :show="showReviewConfirm"
        title="Confirm triage?"
        :message="severityChanged ? 'The severity will be changed and the incident moves on to investigation assignment.' : 'The department\'s severity is confirmed and the incident moves on to investigation assignment.'"
        confirm-label="Confirm Triage"
        :processing="reviewForm.processing"
        @cancel="showReviewConfirm = false"
        @confirm="markReviewed"
    />

    <ConfirmationDialog
        :show="showReturnConfirm"
        title="Return this incident to the department?"
        message="This sends the report back to the department's assessment stage for edits. They will need to complete the assessment again."
        confirm-label="Return to Department"
        :processing="reviewForm.processing"
        @cancel="showReturnConfirm = false"
        @confirm="returnForRevision"
    />

    <ConfirmationDialog
        :show="showSkipConfirm"
        title="Record that no investigation is needed?"
        message="No investigator will be assigned. The department will handle corrective actions directly. This cannot be undone from here."
        confirm-label="No Investigation Needed"
        :processing="skipForm.processing"
        @cancel="showSkipConfirm = false"
        @confirm="skipInvestigation"
    />
</template>
