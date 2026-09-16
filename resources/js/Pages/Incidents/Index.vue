<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    incidents: Object,
    scope: String,
});
</script>

<template>
    <Head title="Incident Reports" />

    <AuthenticatedLayout>
        <section class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm">
            <h1 class="font-headline-lg text-headline-lg text-on-surface tracking-tight">Incident Reports</h1>
            <p class="mt-1 font-body-md text-body-md text-on-surface-variant">
                Showing: {{ scope }}
            </p>
        </section>

        <section class="rounded-xl bg-surface-container-lowest p-space-md shadow-sm">
            <table class="w-full text-left font-body-sm text-body-sm">
                <thead>
                    <tr class="text-on-surface-variant uppercase tracking-wider font-label-sm text-label-sm">
                        <th class="p-2">Incident #</th>
                        <th class="p-2">Location</th>
                        <th class="p-2">Status</th>
                        <th class="p-2">Reporter</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="incident in incidents.data" :key="incident.id" class="border-t border-outline-variant">
                        <td class="p-2">
                            <Link :href="`/incidents/${incident.id}`">{{ incident.incident_number ?? `Draft #${incident.id}` }}</Link>
                        </td>
                        <td class="p-2">{{ incident.location }}</td>
                        <td class="p-2">{{ incident.status }}</td>
                        <td class="p-2">{{ incident.reporter?.name }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </AuthenticatedLayout>
</template>
