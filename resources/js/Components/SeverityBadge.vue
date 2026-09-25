<script setup>
import { computed } from 'vue';
import { severityLabel, severityBadgeClasses } from '@/Composables/useIncidentStatus';

const props = defineProps({
    severity: { type: String, default: null },
});

const label = computed(() => (props.severity ? severityLabel(props.severity) : 'Pending assessment'));
const classes = computed(() => (props.severity ? severityBadgeClasses(props.severity) : 'bg-surface-container text-on-surface-variant'));
</script>

<template>
    <span :class="classes" class="px-2.5 py-0.5 rounded-full font-label-sm text-body-sm font-semibold whitespace-nowrap">
        {{ label }}
    </span>
</template>
