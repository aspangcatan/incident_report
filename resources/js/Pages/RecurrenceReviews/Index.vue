<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Pagination from '@/Components/Pagination.vue';

defineProps({
    reviews: { type: Object, required: true },
});

const statusClasses = {
    open: 'bg-amber-100 text-amber-900',
    submitted: 'bg-blue-100 text-blue-900',
    closed: 'bg-emerald-100 text-emerald-900',
};
</script>

<template>
    <Head title="Recurrence Reviews" />

    <AuthenticatedLayout :refresh="['reviews']">
        <PageHeader
            title="Recurrence Reviews"
            description="When the same type of incident keeps happening in a department, the CQI Office opens a review from the Analytics page. The department records a system-level fix; the CQI Office closes it."
            icon="arrows-rotate"
            eyebrow="Learning & safety"
        />

        <section class="rounded-2xl bg-surface-container-lowest shadow-sm ring-1 ring-outline-variant/40 overflow-hidden">
            <EmptyState v-if="!reviews.data.length" icon="arrows-rotate" title="No recurrence reviews yet" text="Reviews opened from the Analytics page will appear here." />

            <div v-else class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-outline-variant/40 text-outline font-label-sm text-label-sm uppercase tracking-wider">
                            <th class="px-4 py-3">Pattern</th>
                            <th class="px-4 py-3">Department</th>
                            <th class="px-4 py-3">Assigned to</th>
                            <th class="px-4 py-3">Due</th>
                            <th class="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/30">
                        <tr
                            v-for="review in reviews.data"
                            :key="review.id"
                            class="hover:bg-surface-container-low cursor-pointer transition-colors"
                            @click="router.visit(`/recurrence-reviews/${review.id}`)"
                        >
                            <td class="px-4 py-3">
                                <Link :href="`/recurrence-reviews/${review.id}`" class="font-body-md text-body-md text-primary font-semibold hover:underline" @click.stop>
                                    {{ review.incident_type }}
                                </Link>
                                <span class="block font-body-sm text-body-sm text-outline">{{ review.incident_count }} incidents when opened</span>
                            </td>
                            <td class="px-4 py-3 font-body-md text-body-md text-on-surface">{{ review.department }}</td>
                            <td class="px-4 py-3 font-body-md text-body-md text-on-surface">{{ review.assignee ?? '—' }}</td>
                            <td class="px-4 py-3 font-code-tabular text-body-md whitespace-nowrap" :class="review.is_overdue ? 'text-error font-semibold' : 'text-on-surface'">
                                <FontAwesomeIcon v-if="review.is_overdue" icon="triangle-exclamation" class="mr-1" />{{ review.due_date }}<template v-if="review.is_overdue"> (overdue)</template>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="statusClasses[review.status.value]" class="px-2.5 py-0.5 rounded-full font-label-sm text-body-sm font-semibold whitespace-nowrap">{{ review.status.label }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <Pagination :paginator="reviews" />
    </AuthenticatedLayout>
</template>
