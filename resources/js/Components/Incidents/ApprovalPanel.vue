<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { formatDate } from '@/Utils/formatDate';

const props = defineProps({
    incident: { type: Object, required: true },
    approvals: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

const requestForm = useForm({});

function requestApproval() {
    requestForm.post(`/incidents/${props.incident.id}/request-approval`, { preserveScroll: true });
}

const showNoCorrectiveActionForm = ref(false);
const noCorrectiveActionForm = useForm({ justification: '' });

function markNoCorrectiveActionNeeded() {
    noCorrectiveActionForm.post(`/incidents/${props.incident.id}/no-corrective-action-needed`, {
        preserveScroll: true,
        onSuccess: () => {
            noCorrectiveActionForm.reset();
            showNoCorrectiveActionForm.value = false;
        },
    });
}

// Effectiveness check (Department Head, after the waiting period).
const effectivenessForm = useForm({ effective: null, notes: '' });

function recordEffectiveness() {
    effectivenessForm.post(`/incidents/${props.incident.id}/effectiveness`, { preserveScroll: true });
}

const decidingId = ref(null);
const decidingMode = ref(null); // 'approve' | 'return'
const decideForm = useForm({ comments: '' });

function startDeciding(approvalId, mode) {
    decidingId.value = approvalId;
    decidingMode.value = mode;
}

function submitDecision(approvalId) {
    const url = decidingMode.value === 'approve'
        ? `/approvals/${approvalId}/approve`
        : `/approvals/${approvalId}/return`;

    decideForm.post(url, {
        preserveScroll: true,
        onSuccess: () => {
            decideForm.reset();
            decidingId.value = null;
            decidingMode.value = null;
        },
    });
}
</script>

<template>
    <div class="flex flex-col gap-space-lg">
        <div
            v-if="incident.effectiveness_due_at && ['verified', 'for_approval', 'closed', 'corrective_action'].includes(incident.status)"
            class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md"
        >
            <div>
                <h2 class="font-title-lg text-title-lg text-primary font-bold">Effectiveness Check</h2>
                <p class="font-body-sm text-body-sm text-outline">Did the corrective actions work? Closure can only be requested after an "Effective" result.</p>
            </div>

            <div v-if="incident.effectiveness_result" class="p-3 rounded-lg bg-surface-container-low flex flex-col gap-0.5">
                <span class="font-body-md text-body-md text-on-surface">
                    Result: <strong>{{ incident.effectiveness_result === 'effective' ? 'Effective' : 'Not effective' }}</strong>
                </span>
                <span class="font-body-md text-body-md text-on-surface whitespace-pre-line">{{ incident.effectiveness_notes }}</span>
                <span class="font-body-sm text-body-sm text-outline">
                    By {{ incident.effectiveness_checked_by?.name ?? 'the Department Head' }} on {{ formatDate(incident.effectiveness_checked_at) }}
                </span>
            </div>
            <p v-else-if="incident.status === 'verified' && !can.checkEffectiveness" class="font-body-md text-body-md text-on-surface">
                Waiting period: the Department Head can check effectiveness from {{ formatDate(incident.effectiveness_due_at) }}.
            </p>

            <form v-if="can.checkEffectiveness" class="flex flex-col gap-space-md" @submit.prevent="recordEffectiveness">
                <fieldset class="flex flex-col gap-1.5">
                    <legend class="font-label-md text-label-md text-on-surface font-semibold">Were the actions effective? *</legend>
                    <div class="flex items-center gap-space-md mt-1">
                        <label class="flex items-center gap-2 font-body-md text-body-md text-on-surface">
                            <input v-model="effectivenessForm.effective" type="radio" name="effective" :value="true" /> Effective
                        </label>
                        <label class="flex items-center gap-2 font-body-md text-body-md text-on-surface">
                            <input v-model="effectivenessForm.effective" type="radio" name="effective" :value="false" /> Not effective
                        </label>
                    </div>
                    <span class="font-body-sm text-body-sm text-outline">"Not effective" sends the incident back to corrective actions so you can add new ones.</span>
                    <span v-if="effectivenessForm.errors.effective" class="font-body-sm text-body-sm text-error">{{ effectivenessForm.errors.effective }}</span>
                </fieldset>
                <div class="flex flex-col gap-1.5">
                    <label for="effectiveness_notes" class="font-label-md text-label-md text-on-surface font-semibold">Evidence *</label>
                    <span class="font-body-sm text-body-sm text-outline">Has it happened again? What did you check (records, audits, staff feedback)?</span>
                    <textarea id="effectiveness_notes" v-model="effectivenessForm.notes" rows="3" class="w-full p-3 rounded-lg bg-surface-container-low" />
                    <span v-if="effectivenessForm.errors.notes" class="font-body-sm text-body-sm text-error">{{ effectivenessForm.errors.notes }}</span>
                </div>
                <button type="submit" :disabled="effectivenessForm.processing" class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60 w-fit">
                    Record Effectiveness
                </button>
            </form>
        </div>

        <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
            <div class="flex flex-wrap items-center justify-between gap-space-sm">
                <h2 class="font-title-lg text-title-lg text-primary font-bold">Closure Approval</h2>
                <div class="flex gap-2">
                    <button
                        v-if="can.requestApproval"
                        type="button"
                        class="px-3 py-1.5 rounded-lg bg-secondary text-on-secondary font-label-md text-label-md font-semibold"
                        @click="requestApproval"
                        :disabled="requestForm.processing"
                    >
                        Request Approval
                    </button>
                    <button
                        v-if="can.markNoCorrectiveActionNeeded"
                        type="button"
                        class="px-3 py-1.5 rounded-lg bg-surface-container text-primary font-label-md text-label-md font-semibold"
                        @click="showNoCorrectiveActionForm = !showNoCorrectiveActionForm"
                    >
                        {{ showNoCorrectiveActionForm ? 'Cancel' : 'No Corrective Action Needed' }}
                    </button>
                </div>
            </div>

            <form v-if="showNoCorrectiveActionForm" class="flex flex-col gap-2 p-space-md rounded-lg bg-surface-container-low" @submit.prevent="markNoCorrectiveActionNeeded">
                <textarea
                    v-model="noCorrectiveActionForm.justification"
                    rows="2"
                    placeholder="Why does this incident need no corrective action?"
                    class="p-2 rounded-lg bg-surface-container"
                />
                <span v-if="noCorrectiveActionForm.errors.justification" class="font-body-sm text-body-sm text-error">{{ noCorrectiveActionForm.errors.justification }}</span>
                <button type="submit" :disabled="noCorrectiveActionForm.processing" class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60 w-fit">
                    Submit for Approval
                </button>
            </form>

            <div v-if="!approvals.length" class="text-center font-body-sm text-body-sm text-outline p-space-md">
                No closure approval has been requested yet.
            </div>

            <div v-for="approval in approvals" :key="approval.id" class="p-space-md rounded-xl bg-surface-container-low flex flex-col gap-3">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div class="flex flex-col gap-1 max-w-xl">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-label-md text-label-md text-on-surface font-semibold">{{ approval.stage.label }}</span>
                            <span class="px-3 py-1 rounded-full bg-secondary-container text-on-secondary-container font-label-sm text-body-sm font-semibold">
                                {{ approval.status.label }}
                            </span>
                            <span v-if="approval.due_at" class="font-code-tabular text-body-sm" :class="approval.is_overdue ? 'text-error font-bold' : 'text-outline'">
                                Due: {{ formatDate(approval.due_at) }}<template v-if="approval.is_overdue"> (Overdue)</template>
                            </span>
                        </div>
                        <p v-if="approval.request_comments" class="font-body-sm text-body-sm text-on-surface-variant">
                            No corrective action needed: {{ approval.request_comments }}
                        </p>
                        <p class="font-body-sm text-body-sm text-outline">
                            {{ approval.stage.value === 'committee' ? 'Passed on by' : 'Requested by' }} {{ approval.requested_by?.name }} on {{ formatDate(approval.created_at) }}
                        </p>
                        <p v-if="approval.approver" class="font-body-sm text-body-sm text-outline">
                            {{ approval.status.value === 'approved' ? 'Approved' : 'Returned' }} by {{ approval.approver.name }} on {{ formatDate(approval.decided_at) }}: "{{ approval.comments }}"
                        </p>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <button v-if="approval.can.approve" type="button" class="px-3 py-1.5 rounded-lg bg-primary text-on-primary font-label-md text-label-md" @click="startDeciding(approval.id, 'approve')">
                            {{ approval.stage.value === 'cqi_office' && ['level_3_high', 'level_4_critical_sentinel'].includes(incident.severity) ? 'Approve (to Committee)' : 'Approve & Close' }}
                        </button>
                        <button v-if="approval.can.return" type="button" class="px-3 py-1.5 rounded-lg bg-surface-container text-primary font-label-md text-label-md" @click="startDeciding(approval.id, 'return')">
                            Return for Revision
                        </button>
                    </div>
                </div>

                <div v-if="decidingId === approval.id" class="flex flex-col gap-2 p-space-sm rounded-lg bg-surface-container">
                    <textarea
                        v-model="decideForm.comments"
                        rows="2"
                        :placeholder="decidingMode === 'approve' ? 'Approval comments' : 'Reason for returning'"
                        class="p-2 rounded-lg bg-surface-container-low"
                    />
                    <span v-if="decideForm.errors.comments" class="font-body-sm text-body-sm text-error">{{ decideForm.errors.comments }}</span>
                    <div class="flex gap-2">
                        <button type="button" class="px-3 py-1.5 rounded-lg bg-primary text-on-primary font-label-sm text-body-sm" @click="submitDecision(approval.id)">Submit</button>
                        <button type="button" class="px-3 py-1.5 rounded-lg bg-surface-container-lowest font-label-sm text-body-sm" @click="decidingId = null; decidingMode = null">Cancel</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
