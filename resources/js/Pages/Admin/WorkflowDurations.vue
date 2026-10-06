<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    levels: { type: Array, required: true },
    values: { type: Object, required: true },
});

const stages = [
    { key: 'review_sla_hours', label: 'Review', hint: 'Hours from the department assessment to the CQI Office review.' },
    { key: 'investigation_sla_hours', label: 'Investigation', hint: 'Hours from assigning the investigator to the target closure date.' },
    { key: 'approval_sla_hours', label: 'Closure approval', hint: 'Hours from the closure request to the approval decision.' },
];

const form = useForm({
    review_sla_hours: { ...props.values.review_sla_hours },
    investigation_sla_hours: { ...props.values.investigation_sla_hours },
    approval_sla_hours: { ...props.values.approval_sla_hours },
    assessment_sla_hours: props.values.assessment_sla_hours,
    effectiveness_wait_days: props.values.effectiveness_wait_days,
});

function save() {
    form.put('/admin/workflow-durations', { preserveScroll: true });
}
</script>

<template>
    <Head title="Workflow Durations" />

    <AuthenticatedLayout>
        <div class="flex flex-col gap-1">
            <h1 class="font-headline-sm text-headline-sm text-on-surface">Workflow Durations</h1>
            <p class="font-body-sm text-body-sm text-outline">
                How long each step may take before it is overdue and escalated. The five severity levels are fixed; only the time limits can be changed.
            </p>
        </div>

        <div class="rounded-xl bg-amber-50 p-space-md font-body-sm text-body-sm text-amber-900">
            Changes apply to deadlines set after you save. Deadlines already set on open investigations, approvals and effectiveness checks stay as they are.
            Review and department assessment times are checked every day against these values, so they also apply to incidents already waiting.
        </div>

        <form class="flex flex-col gap-space-lg" @submit.prevent="save">
            <section class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
                <div class="flex flex-col gap-1">
                    <h2 class="font-title-lg text-title-lg text-primary font-bold">Time limits by severity (hours)</h2>
                    <p class="font-body-sm text-body-sm text-outline">Whole numbers from 1 to 8760 (one year). 24 hours = 1 day.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr>
                                <th class="p-2 font-label-md text-label-md text-on-surface font-semibold">Severity</th>
                                <th v-for="stage in stages" :key="stage.key" class="p-2 align-top">
                                    <span class="block font-label-md text-label-md text-on-surface font-semibold">{{ stage.label }}</span>
                                    <span class="block font-body-sm text-body-sm text-outline font-normal">{{ stage.hint }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="level in levels" :key="level.value" class="border-t border-surface-container">
                                <td class="p-2 whitespace-nowrap">
                                    <span class="block font-label-sm text-body-sm text-outline">{{ level.numeral }}</span>
                                    <span class="block font-body-md text-body-md text-on-surface font-semibold">{{ level.label }}</span>
                                </td>
                                <td v-for="stage in stages" :key="stage.key" class="p-2 align-top">
                                    <label :for="`${stage.key}_${level.value}`" class="sr-only">{{ stage.label }} hours for {{ level.label }}</label>
                                    <input
                                        :id="`${stage.key}_${level.value}`"
                                        v-model.number="form[stage.key][level.value]"
                                        type="number"
                                        min="1"
                                        max="8760"
                                        step="1"
                                        class="w-28 p-2 rounded-lg bg-surface-container-low"
                                    />
                                    <span v-if="form.errors[`${stage.key}.${level.value}`]" class="block font-body-sm text-body-sm text-error">
                                        {{ form.errors[`${stage.key}.${level.value}`] }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm grid grid-cols-1 md:grid-cols-2 gap-space-md">
                <div class="flex flex-col gap-1.5">
                    <label for="assessment_sla_hours" class="font-label-md text-label-md text-on-surface font-semibold">Department assessment (hours)</label>
                    <span class="font-body-sm text-body-sm text-outline">Hours from submitting the report to the Department Head completing the assessment. Same for every severity, because the severity is only set then. 1 to 8760.</span>
                    <input id="assessment_sla_hours" v-model.number="form.assessment_sla_hours" type="number" min="1" max="8760" step="1" class="w-28 p-2 rounded-lg bg-surface-container-low" />
                    <span v-if="form.errors.assessment_sla_hours" class="font-body-sm text-body-sm text-error">{{ form.errors.assessment_sla_hours }}</span>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label for="effectiveness_wait_days" class="font-label-md text-label-md text-on-surface font-semibold">Effectiveness check wait (days)</label>
                    <span class="font-body-sm text-body-sm text-outline">Days after every corrective action is verified before the CQI Committee checks that the actions worked. 0 to 365.</span>
                    <input id="effectiveness_wait_days" v-model.number="form.effectiveness_wait_days" type="number" min="0" max="365" step="1" class="w-28 p-2 rounded-lg bg-surface-container-low" />
                    <span v-if="form.errors.effectiveness_wait_days" class="font-body-sm text-body-sm text-error">{{ form.errors.effectiveness_wait_days }}</span>
                </div>
            </section>

            <button
                type="submit"
                :disabled="form.processing"
                class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60 w-fit"
            >
                Save
            </button>
        </form>
    </AuthenticatedLayout>
</template>
