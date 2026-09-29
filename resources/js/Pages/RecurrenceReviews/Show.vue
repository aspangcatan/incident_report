<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import RecurrenceIncidentList from '@/Components/RecurrenceIncidentList.vue';
import { formatDate } from '@/Utils/formatDate';

const props = defineProps({
    review: { type: Object, required: true },
    incidents: { type: Array, required: true },
    windowDays: { type: Number, required: true },
    can: { type: Object, required: true },
});

const statusClasses = {
    open: 'bg-amber-100 text-amber-900',
    submitted: 'bg-blue-100 text-blue-900',
    closed: 'bg-emerald-100 text-emerald-900',
};

const submitForm = useForm({ fix_description: props.review.fix_description ?? '' });
const decideForm = useForm({ decision: null, comments: '' });

function submitFix() {
    submitForm.post(`/recurrence-reviews/${props.review.id}/submit`, { preserveScroll: true });
}

function decide(decision) {
    decideForm.decision = decision;
    decideForm.post(`/recurrence-reviews/${props.review.id}/decide`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Recurrence Review: ${review.incident_type}`" />

    <AuthenticatedLayout>
        <article class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
            <div class="flex flex-col gap-2">
                <div class="flex flex-wrap items-center gap-2">
                    <span :class="statusClasses[review.status.value]" class="px-2.5 py-0.5 rounded-full font-label-sm text-body-sm font-semibold">{{ review.status.label }}</span>
                    <span class="font-body-sm text-body-sm" :class="review.is_overdue ? 'text-error font-semibold' : 'text-outline'">
                        Due {{ review.due_date }}<template v-if="review.is_overdue"> (overdue)</template>
                    </span>
                </div>
                <h1 class="font-headline-sm text-headline-sm text-on-surface">{{ review.incident_type }} — {{ review.department }}</h1>
                <p class="font-body-sm text-body-sm text-outline">
                    Opened by {{ review.creator ?? 'the CQI Office' }} on {{ formatDate(review.created_at) }} ({{ review.incident_count }} incidents at the time).
                    Assigned to {{ review.assignee ?? '—' }}.
                </p>
            </div>

            <div class="flex flex-col gap-1">
                <span class="font-label-md text-label-md text-on-surface font-semibold">What to look into</span>
                <p class="font-body-md text-body-md text-on-surface whitespace-pre-line">{{ review.cqi_notes }}</p>
            </div>

            <RecurrenceIncidentList :incidents="incidents" :window-days="windowDays" />

            <div v-if="review.decision_comments && review.status.value !== 'closed'" class="p-3 rounded-lg bg-amber-50 text-amber-900 border-l-4 border-amber-500 flex flex-col gap-0.5">
                <span class="font-label-md text-label-md font-semibold">Returned by the CQI Office</span>
                <span class="font-body-md text-body-md whitespace-pre-line">{{ review.decision_comments }}</span>
            </div>
        </article>

        <section class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
            <h2 class="font-title-lg text-title-lg text-primary font-bold">System-Level Fix</h2>

            <form v-if="can.submit" class="flex flex-col gap-space-sm" @submit.prevent="submitFix">
                <div class="flex flex-col gap-1.5">
                    <label for="rr_fix" class="font-label-md text-label-md text-on-surface font-semibold">What did the department change so this stops recurring? *</label>
                    <span class="font-body-sm text-body-sm text-outline">Think beyond the individual incidents: processes, staffing, equipment, training, policies.</span>
                    <textarea id="rr_fix" v-model="submitForm.fix_description" rows="5" class="w-full p-3 rounded-lg bg-surface-container-low" />
                    <span v-if="submitForm.errors.fix_description" class="font-body-sm text-body-sm text-error">{{ submitForm.errors.fix_description }}</span>
                </div>
                <button type="submit" :disabled="submitForm.processing" class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60 w-fit">
                    Submit to the CQI Office
                </button>
            </form>
            <template v-else>
                <p v-if="review.fix_description" class="font-body-md text-body-md text-on-surface whitespace-pre-line">{{ review.fix_description }}</p>
                <p v-else class="font-body-md text-body-md text-outline">Not submitted yet.</p>
                <span v-if="review.submitted_at" class="font-body-sm text-body-sm text-outline">Submitted {{ formatDate(review.submitted_at) }}</span>
            </template>
        </section>

        <section v-if="can.decide || review.status.value === 'closed'" class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
            <h2 class="font-title-lg text-title-lg text-primary font-bold">CQI Office Decision</h2>

            <template v-if="can.decide">
                <div class="flex flex-col gap-1.5">
                    <label for="rr_comments" class="font-label-md text-label-md text-on-surface font-semibold">Comments *</label>
                    <span class="font-body-sm text-body-sm text-outline">Why you accept the fix, or what more the department must do.</span>
                    <textarea id="rr_comments" v-model="decideForm.comments" rows="3" class="w-full p-3 rounded-lg bg-surface-container-low" />
                    <span v-if="decideForm.errors.comments" class="font-body-sm text-body-sm text-error">{{ decideForm.errors.comments }}</span>
                </div>
                <div class="flex gap-2">
                    <button type="button" :disabled="decideForm.processing" class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60" @click="decide('close')">
                        Close as Addressed
                    </button>
                    <button type="button" :disabled="decideForm.processing" class="px-4 py-2 rounded-lg bg-surface-container text-on-surface font-label-md text-label-md disabled:opacity-60" @click="decide('return')">
                        Return to Department
                    </button>
                </div>
            </template>
            <template v-else>
                <p class="font-body-md text-body-md text-on-surface whitespace-pre-line">{{ review.decision_comments }}</p>
                <span class="font-body-sm text-body-sm text-outline">Closed by {{ review.closer ?? 'the CQI Office' }} on {{ formatDate(review.closed_at) }}</span>
            </template>
        </section>
    </AuthenticatedLayout>
</template>
