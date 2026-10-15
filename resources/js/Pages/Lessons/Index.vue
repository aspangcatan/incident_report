<script setup>
import { Head } from '@inertiajs/vue3';
import Pagination from '@/Components/Pagination.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import SeverityBadge from '@/Components/SeverityBadge.vue';
import { formatDate } from '@/Utils/formatDate';

defineProps({
    lessons: { type: Object, required: true },
});
</script>

<template>
    <Head title="Lessons Learned" />

    <AuthenticatedLayout>
        <PageHeader
            title="Lessons Learned"
            description="What the hospital learned from closed incidents, so the same thing does not happen again. Names are never shown."
            icon="lightbulb"
            eyebrow="Learning & safety"
        />

        <section v-if="!lessons.data.length" class="rounded-2xl bg-surface-container-lowest shadow-sm ring-1 ring-outline-variant/40">
            <EmptyState icon="lightbulb" title="No lessons published yet" text="A lesson appears here when an incident is closed." />
        </section>

        <div v-else class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <article
                v-for="lesson in lessons.data"
                :key="lesson.id"
                class="rounded-2xl bg-surface-container-lowest p-5 shadow-sm ring-1 ring-outline-variant/40 flex flex-col gap-3"
            >
                <div class="flex items-start gap-3">
                    <span class="h-10 w-10 shrink-0 rounded-lg bg-amber-100 text-amber-800 flex items-center justify-center">
                        <FontAwesomeIcon icon="lightbulb" />
                    </span>
                    <div class="flex flex-col gap-1 min-w-0">
                        <span class="font-title-sm text-title-sm text-on-surface font-semibold">{{ lesson.types.join(', ') || 'Incident' }}</span>
                        <span class="font-body-sm text-body-sm text-outline">{{ lesson.department ?? 'Department not recorded' }} · {{ formatDate(lesson.published_at) }}</span>
                    </div>
                    <span class="ml-auto shrink-0"><SeverityBadge :severity="lesson.severity" /></span>
                </div>
                <p class="font-body-md text-body-md text-on-surface whitespace-pre-line leading-relaxed">{{ lesson.lesson }}</p>
            </article>
        </div>

        <Pagination :paginator="lessons" />
    </AuthenticatedLayout>
</template>
