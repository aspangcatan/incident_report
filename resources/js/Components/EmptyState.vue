<script setup>
defineProps({
    icon: { type: String, default: 'circle-check' },
    title: { type: String, required: true },
    text: { type: String, default: null },
});
</script>

<template>
    <div class="flex flex-col items-center text-center gap-2 px-4 py-12">
        <span class="h-14 w-14 rounded-full bg-surface-container text-primary flex items-center justify-center mb-1">
            <FontAwesomeIcon :icon="icon" class="text-headline-sm" />
        </span>
        <p class="font-title-md text-title-md text-on-surface">{{ title }}</p>
        <p v-if="text" class="font-body-md text-body-md text-outline max-w-md">{{ text }}</p>
        <slot />
    </div>
</template>
