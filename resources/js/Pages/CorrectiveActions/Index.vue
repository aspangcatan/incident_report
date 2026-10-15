<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
    actions: { type: Object, required: true },
    queue: { type: Object, required: true },
});

// Same icons as the sidebar.
const icons = { open: 'list-check', 'for-verification': 'shield-halved', overdue: 'triangle-exclamation', completed: 'box-archive' };

const priorityClasses = {
    Critical: 'bg-error-container text-on-error-container',
    High: 'bg-amber-100 text-amber-900',
    Medium: 'bg-primary-fixed text-primary',
    Low: 'bg-surface-container text-on-surface-variant',
};

function truncate(text, length = 90) {
    if (!text) {
        return '—';
    }

    return text.length > length ? `${text.slice(0, length)}…` : text;
}
</script>

<template>
    <Head :title="queue.title" />

    <AuthenticatedLayout :refresh="['actions']">
        <PageHeader :title="queue.title" :description="queue.description" :icon="icons[queue.key] ?? 'list-check'" eyebrow="CAPA operations" />

        <section class="rounded-2xl bg-surface-container-lowest shadow-sm ring-1 ring-outline-variant/40 overflow-hidden">
            <EmptyState v-if="actions.data.length === 0" :title="`Nothing in ${queue.title} right now`" text="You are all caught up." />

            <div v-else class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-outline-variant/40 text-outline font-label-sm text-label-sm uppercase tracking-wider">
                            <th class="px-4 py-3">CAPA No.</th>
                            <th class="px-4 py-3">Action</th>
                            <th class="px-4 py-3">Incident</th>
                            <th class="px-4 py-3">Responsible</th>
                            <th class="px-4 py-3">Priority</th>
                            <th class="px-4 py-3">Due</th>
                            <th class="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/30">
                        <tr
                            v-for="action in actions.data"
                            :key="action.id"
                            class="hover:bg-surface-container-low cursor-pointer transition-colors"
                            @click="router.visit(`/incidents/${action.incident.id}?tab=capa`)"
                        >
                            <td class="px-4 py-3 whitespace-nowrap">
                                <Link :href="`/incidents/${action.incident.id}?tab=capa`" class="font-code-tabular text-body-md text-primary font-semibold hover:underline" @click.stop>
                                    {{ action.capa_number }}
                                </Link>
                            </td>
                            <td class="px-4 py-3 font-body-md text-body-md text-on-surface max-w-[22rem]">{{ truncate(action.description) }}</td>
                            <td class="px-4 py-3 font-code-tabular text-body-md text-on-surface-variant whitespace-nowrap">{{ action.incident.incident_number }}</td>
                            <td class="px-4 py-3 font-body-md text-body-md text-on-surface">{{ action.responsible ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="px-2.5 py-0.5 rounded-full font-label-sm text-body-sm font-semibold whitespace-nowrap" :class="priorityClasses[action.priority] ?? priorityClasses.Low">
                                    {{ action.priority }}
                                </span>
                            </td>
                            <td class="px-4 py-3 font-code-tabular text-body-md whitespace-nowrap" :class="action.is_overdue ? 'text-error font-semibold' : 'text-on-surface'">
                                <FontAwesomeIcon v-if="action.is_overdue" icon="triangle-exclamation" class="mr-1" />{{ action.due_date ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="px-2.5 py-0.5 rounded-full bg-surface-container text-on-surface font-label-sm text-body-sm font-semibold whitespace-nowrap">
                                    {{ action.status.label }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <Pagination :paginator="actions" />
    </AuthenticatedLayout>
</template>
