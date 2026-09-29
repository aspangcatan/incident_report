<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
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

    <AuthenticatedLayout>
        <div class="flex flex-col gap-1">
            <h1 class="font-headline-sm text-headline-sm text-on-surface">Recurrence Reviews</h1>
            <p class="font-body-sm text-body-sm text-outline">
                When the same type of incident keeps happening in a department, the CQI Office opens a review from the
                Analytics page. The department records a system-level fix; the CQI Office closes it.
            </p>
        </div>

        <div v-if="!reviews.data.length" class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm font-body-md text-body-md text-outline text-center">
            No recurrence reviews yet.
        </div>

        <div v-else class="rounded-xl bg-surface-container-lowest shadow-sm overflow-x-auto">
            <table class="w-full text-left">
                <thead class="bg-surface-container-low">
                    <tr class="font-label-sm text-body-sm uppercase text-outline">
                        <th class="p-3">Pattern</th>
                        <th class="p-3">Department</th>
                        <th class="p-3">Assigned to</th>
                        <th class="p-3">Due</th>
                        <th class="p-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-surface-container">
                    <tr v-for="review in reviews.data" :key="review.id" class="hover:bg-surface-container-low">
                        <td class="p-3">
                            <Link :href="`/recurrence-reviews/${review.id}`" class="font-body-md text-body-md text-primary font-semibold">
                                {{ review.incident_type }}
                            </Link>
                            <span class="block font-body-sm text-body-sm text-outline">{{ review.incident_count }} incidents when opened</span>
                        </td>
                        <td class="p-3 font-body-sm text-body-sm text-on-surface">{{ review.department }}</td>
                        <td class="p-3 font-body-sm text-body-sm text-on-surface">{{ review.assignee ?? '—' }}</td>
                        <td class="p-3 font-code-tabular text-body-sm" :class="review.is_overdue ? 'text-error font-semibold' : 'text-on-surface'">
                            {{ review.due_date }}<template v-if="review.is_overdue"> (overdue)</template>
                        </td>
                        <td class="p-3">
                            <span :class="statusClasses[review.status.value]" class="px-2.5 py-0.5 rounded-full font-label-sm text-body-sm font-semibold whitespace-nowrap">{{ review.status.label }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="reviews" />
    </AuthenticatedLayout>
</template>
