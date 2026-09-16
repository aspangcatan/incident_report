<script setup>
import { computed } from 'vue';
import { severityLabel, severityBadgeClasses } from '@/Composables/useIncidentStatus';

const props = defineProps({
    severity: { type: String, required: true },
});

const label = computed(() => severityLabel(props.severity));
const classes = computed(() => severityBadgeClasses(props.severity));
</script>

<template>
    <span :class="classes" class="px-2.5 py-0.5 rounded-full font-label-sm text-body-sm font-semibold whitespace-nowrap">
        {{ label }}
    </span>
</template>
