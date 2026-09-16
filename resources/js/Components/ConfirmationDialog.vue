<script setup>
defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, required: true },
    message: { type: String, required: true },
    confirmLabel: { type: String, default: 'Confirm' },
    processing: { type: Boolean, default: false },
});

const emit = defineEmits(['confirm', 'cancel']);
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-[100] flex items-center justify-center px-space-md">
        <div class="absolute inset-0 bg-inverse-surface/60 backdrop-blur-sm" @click="emit('cancel')" />
        <div class="relative w-full max-w-md rounded-xl bg-surface-container-lowest shadow-xl p-space-lg">
            <h3 class="font-title-lg text-title-lg text-on-surface font-semibold">{{ title }}</h3>
            <p class="mt-2 font-body-sm text-body-sm text-on-surface-variant">{{ message }}</p>
            <div class="mt-space-lg flex justify-end gap-2">
                <button
                    type="button"
                    class="px-4 py-2 rounded-lg bg-surface-container text-on-surface font-label-md text-label-md"
                    @click="emit('cancel')"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    :disabled="processing"
                    class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60"
                    @click="emit('confirm')"
                >
                    {{ confirmLabel }}
                </button>
            </div>
        </div>
    </div>
</template>
