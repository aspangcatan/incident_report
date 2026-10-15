<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    levels: { type: Array, required: true },
    groups: { type: Array, required: true },
    recipients: { type: Object, required: true },
});

const form = useForm({
    recipients: Object.fromEntries(props.levels.map((level) => [level.value, [...(props.recipients[level.value] ?? [])]])),
});

const errors = computed(() => [...new Set(Object.values(form.errors))]);

function save() {
    form.put('/admin/severity-alerts', { preserveScroll: true });
}
</script>

<template>
    <Head title="Severity Alerts" />

    <AuthenticatedLayout>
        <div class="flex flex-col gap-1">
            <h1 class="font-headline-sm text-headline-sm text-on-surface">Severity Alerts</h1>
            <p class="font-body-sm text-body-sm text-outline">
                Who is alerted when an incident's severity is set at the department assessment, or changed at CQI triage. Tick the groups for each level; leave a level blank to alert nobody.
            </p>
        </div>

        <div class="rounded-xl bg-amber-50 p-space-md font-body-sm text-body-sm text-amber-900">
            The person who set the severity is never alerted about their own decision. Inactive accounts are skipped.
            Overdue escalations (investigation, corrective action, review, approval) are not affected by this page.
        </div>

        <form class="flex flex-col gap-space-lg" @submit.prevent="save">
            <section class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr>
                                <th class="p-2 font-label-md text-label-md text-on-surface font-semibold">Recipient group</th>
                                <th v-for="level in levels" :key="level.value" class="p-2 text-center whitespace-nowrap">
                                    <span class="block font-label-sm text-body-sm text-outline font-normal">{{ level.numeral }}</span>
                                    <span class="block font-body-md text-body-md text-on-surface font-semibold">{{ level.label }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="group in groups" :key="group.value" class="border-t border-surface-container">
                                <td class="p-2">
                                    <span class="block font-body-md text-body-md text-on-surface font-semibold">{{ group.label }}</span>
                                    <span class="block font-body-sm text-body-sm text-outline">{{ group.scope }}</span>
                                </td>
                                <td v-for="level in levels" :key="level.value" class="p-2 text-center align-middle">
                                    <label :for="`${level.value}_${group.value}`" class="sr-only">Alert {{ group.label }} for {{ level.label }}</label>
                                    <input
                                        :id="`${level.value}_${group.value}`"
                                        v-model="form.recipients[level.value]"
                                        type="checkbox"
                                        :value="group.value"
                                        class="h-5 w-5 rounded accent-primary"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <ul v-if="errors.length" class="font-body-sm text-body-sm text-error">
                    <li v-for="error in errors" :key="error">{{ error }}</li>
                </ul>
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
