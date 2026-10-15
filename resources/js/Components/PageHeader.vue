<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
    icon: { type: String, default: null },
    eyebrow: { type: String, default: null },
});
</script>

<template>
    <!-- Top of a signed-in page: icon, title, one-line description and the page's main actions. -->
    <header class="relative overflow-hidden rounded-2xl bg-surface-container-lowest p-5 md:p-6 shadow-sm ring-1 ring-outline-variant/40">
        <div class="absolute inset-y-0 right-0 w-1/2 bg-gradient-to-l from-primary-fixed/60 to-transparent pointer-events-none" aria-hidden="true" />
        <div class="relative flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-start gap-4 min-w-0">
                <span v-if="icon" class="h-12 w-12 shrink-0 rounded-xl bg-primary text-on-primary flex items-center justify-center shadow-sm">
                    <FontAwesomeIcon :icon="icon" class="text-title-lg" />
                </span>
                <div class="flex flex-col gap-1 min-w-0">
                    <span v-if="eyebrow" class="font-label-sm text-label-sm uppercase tracking-wider text-secondary font-bold">{{ eyebrow }}</span>
                    <h1 class="font-headline-md text-headline-lg-mobile md:text-headline-md text-primary font-bold tracking-tight">{{ title }}</h1>
                    <p v-if="description" class="font-body-md text-body-md text-on-surface-variant max-w-3xl">{{ description }}</p>
                    <slot name="meta" />
                </div>
            </div>
            <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
                <slot name="actions" />
            </div>
        </div>
        <div v-if="$slots.default" class="relative mt-4">
            <slot />
        </div>
    </header>
</template>
