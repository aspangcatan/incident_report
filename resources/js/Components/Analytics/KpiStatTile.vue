<script setup>
defineProps({
    label: { type: String, required: true },
    value: { type: String, required: true },
    delta: { type: String, default: null },
    deltaIsGood: { type: Boolean, default: null },
});
</script>

<template>
    <div class="p-space-md rounded-xl bg-surface-container-lowest shadow-sm flex flex-col gap-1">
        <span class="font-label-sm text-body-sm text-outline">{{ label }}</span>
        <span class="font-headline-sm text-headline-sm text-on-surface font-semibold">{{ value }}</span>
        <span
            v-if="delta"
            class="font-body-sm text-body-sm font-semibold"
            :class="deltaIsGood === null ? 'text-outline' : (deltaIsGood ? 'text-[#006300]' : 'text-error')"
        >
            {{ delta }}
        </span>
    </div>
</template>
