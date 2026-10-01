<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { formatDate } from '@/Utils/formatDate';
import { statusLabel } from '@/Composables/useIncidentStatus';

const props = defineProps({
    incident: { type: Object, required: true },
    can: { type: Object, required: true },
});

const confirmForm = useForm({});

function confirmPreserved() {
    confirmForm.post(`/incidents/${props.incident.id}/evidence-preserved`, { preserveScroll: true });
}

const steps = computed(() => {
    const i = props.incident;
    return [
        {
            key: 'response',
            title: 'Immediate patient safety and clinical response',
            detail: 'Takes priority over documentation. Make the patient and area safe first.',
            done: null,
        },
        {
            key: 'evidence',
            title: 'Records, equipment and evidence preserved',
            detail: i.evidence_preserved_at
                ? `Confirmed by ${i.evidence_preserved_by?.name ?? 'a former user'} on ${formatDate(i.evidence_preserved_at)}.`
                : 'Keep the records, equipment and other evidence according to policy. The department Head or Focal Person confirms it here.',
            done: !!i.evidence_preserved_at,
        },
        {
            key: 'leadership',
            title: 'Designated leadership notified',
            detail: 'Patient Safety/CQI, the department\'s leadership and the executives were alerted when the incident was rated Sentinel.',
            done: true,
        },
        {
            key: 'investigation',
            title: 'Formal investigation / RCA assigned',
            detail: i.assigned_investigator ? `Investigator: ${i.assigned_investigator.name}.` : 'Waiting for the Patient Safety/CQI Office to assign an investigator.',
            done: !!i.assigned_investigator,
        },
        {
            key: 'governance',
            title: 'Corrective actions and governance review',
            detail: i.status === 'closed'
                ? 'Corrective actions verified and closure approved by the CQI Committee.'
                : `Current stage: ${statusLabel(i.status)}. Closure needs verified corrective actions and CQI Committee approval.`,
            done: i.status === 'closed',
        },
        {
            key: 'learning',
            title: 'Learning and prevention documented',
            detail: i.lessons_published_at
                ? 'Lessons learned published for all staff.'
                : (i.lessons_learned ? 'Lessons learned recorded; published when the incident closes.' : 'Recorded when the department requests closure.'),
            done: !!i.lessons_learned,
        },
    ];
});
</script>

<template>
    <section class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md border-l-4 border-error">
        <div class="flex flex-col gap-1">
            <h2 class="font-title-lg text-title-lg text-error font-bold">Sentinel Event Pathway</h2>
            <span class="font-body-sm text-body-sm text-outline">The steps every sentinel event must go through.</span>
        </div>

        <ol class="flex flex-col gap-2">
            <li v-for="(step, index) in steps" :key="step.key" class="p-3 rounded-lg bg-surface-container-low flex items-start gap-3">
                <span
                    class="mt-0.5 w-6 h-6 shrink-0 rounded-full flex items-center justify-center font-label-sm text-body-sm font-bold"
                    :class="step.done === true ? 'bg-emerald-600 text-white' : step.done === false ? 'bg-surface-container-high text-on-surface-variant' : 'bg-error text-on-error'"
                    :aria-label="step.done === true ? 'Done' : step.done === false ? 'Not done yet' : 'Guidance'"
                >
                    <template v-if="step.done === true">✓</template>
                    <template v-else>{{ index + 1 }}</template>
                </span>
                <div class="flex flex-col gap-1 flex-1">
                    <span class="font-label-md text-label-md text-on-surface font-semibold">{{ step.title }}</span>
                    <span class="font-body-sm text-body-sm text-on-surface-variant">{{ step.detail }}</span>
                    <button
                        v-if="step.key === 'evidence' && can.confirmEvidencePreserved"
                        type="button"
                        :disabled="confirmForm.processing"
                        class="self-start mt-1 px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60"
                        @click="confirmPreserved"
                    >
                        Confirm evidence preserved
                    </button>
                </div>
            </li>
        </ol>
    </section>
</template>
