<script setup>
import { computed } from 'vue';
import { statusLabel, statusBadgeClasses } from '@/Composables/useIncidentStatus';

const props = defineProps({
    status: { type: String, required: true },
    // A draft sent back to the reporter shows as "Returned" instead of "Draft".
    returned: { type: Boolean, default: false },
});

const label = computed(() => (props.returned ? 'Returned' : statusLabel(props.status)));
const classes = computed(() => (props.returned ? 'bg-amber-100 text-amber-900' : statusBadgeClasses(props.status)));
</script>

<template>
    <span :class="classes" class="px-2.5 py-0.5 rounded-full font-label-sm text-body-sm font-semibold whitespace-nowrap">
        {{ label }}
    </span>
</template>
