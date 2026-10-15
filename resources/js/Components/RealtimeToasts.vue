<script>
import { ref } from 'vue';

// Module level, so a toast survives the layout remounting on the next page visit.
const toasts = ref([]);
const timers = new Map();
</script>

<script setup>
import { Link } from '@inertiajs/vue3';
import { isUrgentNotification, notificationUrl } from '@/Utils/notificationLinks';

// Notifications that just arrived over the WebSocket. Urgent ones (severity alerts,
// escalations) stay until dismissed; the rest fade after a few seconds.
const AUTO_HIDE_MS = 8000;
const MAX_SHOWN = 4;

function dismiss(id) {
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
    window.clearTimeout(timers.get(id));
    timers.delete(id);
}

function show(notification) {
    const toast = {
        id: notification.id ?? `${Date.now()}-${Math.random()}`,
        message: notification.data?.message ?? 'You have a new notification.',
        url: notificationUrl(notification.data),
        urgent: isUrgentNotification(notification.type),
    };
    toasts.value = [toast, ...toasts.value].slice(0, MAX_SHOWN);
    if (!toast.urgent) timers.set(toast.id, window.setTimeout(() => dismiss(toast.id), AUTO_HIDE_MS));
}

defineExpose({ show });
</script>

<template>
    <div class="fixed top-20 right-4 z-50 w-[min(24rem,calc(100vw-2rem))] flex flex-col gap-2" aria-live="polite">
        <TransitionGroup
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 translate-x-4"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="opacity-0"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                :role="toast.urgent ? 'alert' : 'status'"
                class="rounded-xl bg-surface-container-lowest shadow-lg ring-1 ring-outline-variant/40 border-l-4 p-3 flex items-start gap-3"
                :class="toast.urgent ? 'border-error' : 'border-primary'"
            >
                <span
                    class="h-9 w-9 shrink-0 rounded-full flex items-center justify-center"
                    :class="toast.urgent ? 'bg-error-container text-error' : 'bg-primary-fixed text-primary'"
                >
                    <FontAwesomeIcon :icon="toast.urgent ? 'triangle-exclamation' : 'bell'" />
                </span>
                <div class="flex-1 min-w-0 flex flex-col gap-1.5">
                    <p class="font-body-md text-body-md text-on-surface">{{ toast.message }}</p>
                    <Link
                        v-if="toast.url"
                        :href="toast.url"
                        class="self-start font-label-md text-label-md font-semibold"
                        :class="toast.urgent ? 'text-error' : 'text-primary'"
                        @click="dismiss(toast.id)"
                    >
                        Open <FontAwesomeIcon icon="arrow-right" />
                    </Link>
                </div>
                <button type="button" class="h-7 w-7 shrink-0 rounded-full text-outline hover:bg-surface-container flex items-center justify-center" aria-label="Dismiss" @click="dismiss(toast.id)">
                    <FontAwesomeIcon icon="xmark" />
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
