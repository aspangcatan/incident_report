<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { formatDate } from '@/Utils/formatDate';

defineProps({
    notifications: { type: Object, required: true },
});

function targetUrl(notification) {
    if (notification.data.safety_alert_id) return `/safety-alerts/${notification.data.safety_alert_id}`;
    if (notification.data.incident_id) return `/incidents/${notification.data.incident_id}`;
    return null;
}

function markRead(notification) {
    router.post(`/notifications/${notification.id}/read`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Notifications" />

    <AuthenticatedLayout>
        <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
            <h1 class="font-headline-sm text-headline-sm text-on-surface">Notifications</h1>

            <div v-if="notifications.data.length === 0" class="p-space-lg text-center font-body-sm text-body-sm text-outline">
                No notifications yet.
            </div>

            <div
                v-for="notification in notifications.data"
                :key="notification.id"
                class="p-3 rounded-lg flex items-start justify-between gap-3"
                :class="notification.read_at ? 'bg-surface-container-low' : 'bg-primary-container/10 border border-primary/20'"
            >
                <div class="flex flex-col">
                    <span class="font-body-md text-body-md text-on-surface">{{ notification.data.message }}</span>
                    <span class="font-code-tabular text-body-sm text-outline">{{ formatDate(notification.created_at) }}</span>
                </div>
                <div class="flex items-center gap-3">
                <Link
                    v-if="targetUrl(notification)"
                    :href="targetUrl(notification)"
                    class="font-label-sm text-body-sm text-primary font-semibold whitespace-nowrap"
                >
                    Open
                </Link>
                <button
                    v-if="!notification.read_at"
                    type="button"
                    class="font-label-sm text-body-sm text-primary font-semibold whitespace-nowrap"
                    @click="markRead(notification)"
                >
                    Mark as read
                </button>
                </div>
            </div>

            <Pagination :paginator="notifications" />
        </div>
    </AuthenticatedLayout>
</template>
