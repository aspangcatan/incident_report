<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Pagination from '@/Components/Pagination.vue';
import { formatDate } from '@/Utils/formatDate';
import { isUrgentNotification, notificationUrl } from '@/Utils/notificationLinks';

defineProps({
    notifications: { type: Object, required: true },
});

const targetUrl = (notification) => notificationUrl(notification.data);

// Icon from what the notification is about: escalations and reminders stand out.
function iconFor(notification) {
    const type = notification.type ?? '';
    if (type.includes('Escalation')) return 'triangle-exclamation';
    if (type.includes('Reminder')) return 'clock';
    if (type.includes('SafetyAlert')) return 'bullhorn';
    if (type.includes('Severity') || type.includes('HighRisk')) return 'circle-exclamation';
    if (type.includes('Recurrence')) return 'arrows-rotate';
    if (type.includes('Closure') || type.includes('SignOff')) return 'stamp';
    if (type.includes('Effectiveness')) return 'clipboard-check';
    return 'bell';
}

const urgent = (notification) => isUrgentNotification(notification.type ?? '');

function markRead(notification) {
    router.post(`/notifications/${notification.id}/read`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Notifications" />

    <AuthenticatedLayout :refresh="['notifications']">
        <PageHeader title="Notifications" description="Assignments, reminders, escalations and alerts sent to you. New ones arrive here automatically." icon="bell" />

        <section class="rounded-2xl bg-surface-container-lowest shadow-sm ring-1 ring-outline-variant/40 overflow-hidden">
            <EmptyState v-if="notifications.data.length === 0" icon="bell" title="No notifications yet" text="When something needs your attention, it will show up here." />

            <ul v-else class="divide-y divide-outline-variant/30">
                <li
                    v-for="notification in notifications.data"
                    :key="notification.id"
                    class="flex items-start gap-4 px-4 sm:px-5 py-4"
                    :class="notification.read_at ? '' : 'bg-primary-fixed/30'"
                >
                    <span
                        class="h-10 w-10 shrink-0 rounded-full flex items-center justify-center"
                        :class="urgent(notification) ? 'bg-error-container text-error' : 'bg-primary-fixed text-primary'"
                    >
                        <FontAwesomeIcon :icon="iconFor(notification)" />
                    </span>
                    <div class="flex-1 min-w-0 flex flex-col gap-0.5">
                        <span class="font-body-md text-body-md text-on-surface" :class="notification.read_at ? '' : 'font-semibold'">{{ notification.data.message }}</span>
                        <span class="font-body-sm text-body-sm text-outline">{{ formatDate(notification.created_at) }}</span>
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-1 shrink-0">
                        <span v-if="!notification.read_at" class="h-2.5 w-2.5 rounded-full bg-primary mr-1" aria-label="Unread" />
                        <button
                            v-if="!notification.read_at"
                            type="button"
                            class="px-3 py-1.5 rounded-lg font-label-md text-label-md text-on-surface-variant hover:bg-surface-container whitespace-nowrap"
                            @click="markRead(notification)"
                        >
                            Mark as read
                        </button>
                        <Link
                            v-if="targetUrl(notification)"
                            :href="targetUrl(notification)"
                            class="px-3 py-1.5 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold whitespace-nowrap hover:bg-primary-container"
                        >
                            Open
                        </Link>
                    </div>
                </li>
            </ul>
        </section>

        <Pagination :paginator="notifications" />
    </AuthenticatedLayout>
</template>
