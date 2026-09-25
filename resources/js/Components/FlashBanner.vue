<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

// Shows the server's flash message ("Assessment saved.", errors, …) after
// each visit; hides itself after a few seconds or when dismissed.
const page = usePage();
const message = ref(null);
let timer = null;

watch(
    () => page.props.flash,
    (flash) => {
        clearTimeout(timer);
        message.value = flash?.error
            ? { type: 'error', text: flash.error }
            : flash?.success
              ? { type: 'success', text: flash.success }
              : null;

        if (message.value) {
            timer = setTimeout(() => (message.value = null), 5000);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div
        v-if="message"
        :role="message.type === 'error' ? 'alert' : 'status'"
        class="fixed top-20 right-6 z-50 max-w-md flex items-start gap-3 px-4 py-3 rounded-lg shadow-md font-body-md text-body-md"
        :class="message.type === 'error' ? 'bg-error-container text-on-error-container' : 'bg-secondary-container text-on-secondary-container'"
    >
        <span class="flex-1">{{ message.text }}</span>
        <button type="button" aria-label="Dismiss" class="font-bold leading-none" @click="message = null">×</button>
    </div>
</template>
