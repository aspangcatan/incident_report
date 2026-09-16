<script setup>
import { computed } from 'vue';
import { statusLabel, statusBadgeClasses } from '@/Composables/useIncidentStatus';

const props = defineProps({
    status: { type: String, required: true },
});

const label = computed(() => statusLabel(props.status));
const classes = computed(() => statusBadgeClasses(props.status));
</script>

<template>
    <span :class="classes" class="px-2.5 py-0.5 rounded-full font-label-sm text-body-sm font-semibold whitespace-nowrap">
        {{ label }}
    </span>
</template>
