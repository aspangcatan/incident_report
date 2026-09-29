<script setup>
import { Link } from '@inertiajs/vue3';
import SeverityBadge from '@/Components/SeverityBadge.vue';
import { formatDate } from '@/Utils/formatDate';

defineProps({
    incidents: { type: Array, required: true },
    windowDays: { type: Number, required: true },
});
</script>

<template>
    <div class="flex flex-col gap-2">
        <span class="font-label-md text-label-md text-on-surface font-semibold">Incidents in this pattern (last {{ windowDays }} days)</span>
        <p v-if="!incidents.length" class="font-body-sm text-body-sm text-outline">None in the last {{ windowDays }} days.</p>
        <ul class="flex flex-col gap-1">
            <li v-for="incident in incidents" :key="incident.id" class="flex flex-wrap items-center gap-2 p-2 rounded-lg bg-surface-container-low">
                <Link :href="`/incidents/${incident.id}`" class="font-code-tabular text-body-sm text-primary font-semibold">{{ incident.incident_number }}</Link>
                <SeverityBadge :severity="incident.severity" />
                <span class="font-body-sm text-body-sm text-on-surface flex-1 truncate">{{ incident.summary }}</span>
                <span class="font-body-sm text-body-sm text-outline">{{ incident.status }} · {{ formatDate(incident.reported_at) }}</span>
            </li>
        </ul>
    </div>
</template>
