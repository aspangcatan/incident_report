<script setup>
import { Head } from '@inertiajs/vue3';
import Pagination from '@/Components/Pagination.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SeverityBadge from '@/Components/SeverityBadge.vue';
import { formatDate } from '@/Utils/formatDate';

defineProps({
    lessons: { type: Object, required: true },
});
</script>

<template>
    <Head title="Lessons Learned" />

    <AuthenticatedLayout>
        <div class="flex flex-col gap-1">
            <h1 class="font-headline-sm text-headline-sm text-on-surface">Lessons Learned</h1>
            <p class="font-body-sm text-body-sm text-outline">What the hospital learned from closed incidents. Names are never shown.</p>
        </div>

        <div v-if="!lessons.data.length" class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm font-body-md text-body-md text-outline text-center">
            No lessons have been published yet. They appear here when an incident is closed.
        </div>

        <article
            v-for="lesson in lessons.data"
            :key="lesson.id"
            class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-2"
        >
            <div class="flex flex-wrap items-center gap-2">
                <SeverityBadge :severity="lesson.severity" />
                <span class="font-label-md text-label-md text-on-surface font-semibold">{{ lesson.types.join(', ') || 'Incident' }}</span>
                <span class="font-body-sm text-body-sm text-outline">· {{ lesson.department ?? 'Department not recorded' }} · {{ formatDate(lesson.published_at) }}</span>
            </div>
            <p class="font-body-md text-body-md text-on-surface whitespace-pre-line">{{ lesson.lesson }}</p>
        </article>

        <Pagination :paginator="lessons" />
    </AuthenticatedLayout>
</template>
