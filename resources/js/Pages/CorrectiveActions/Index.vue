<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
    actions: { type: Object, required: true },
    queue: { type: Object, required: true },
});

function truncate(text, length = 80) {
    if (!text) {
        return '—';
    }

    return text.length > length ? `${text.slice(0, length)}…` : text;
}
</script>

<template>
    <Head :title="queue.title" />

    <AuthenticatedLayout>
        <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
            <div class="flex flex-col gap-0.5">
                <h1 class="font-headline-sm text-headline-sm text-on-surface">{{ queue.title }}</h1>
                <p class="font-body-sm text-body-sm text-outline">{{ queue.description }}</p>
            </div>

            <div v-if="actions.data.length === 0" class="p-space-lg text-center font-body-sm text-body-sm text-outline">
                Nothing in {{ queue.title }} right now.
            </div>

            <div v-else class="overflow-x-auto rounded-lg bg-surface-container-low">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-surface-container text-on-surface-variant font-label-sm text-label-sm uppercase tracking-wider">
                            <th class="p-3">CAPA No.</th>
                            <th class="p-3">Description</th>
                            <th class="p-3">Incident</th>
                            <th class="p-3">Responsible</th>
                            <th class="p-3">Priority</th>
                            <th class="p-3">Due</th>
                            <th class="p-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-container">
                        <tr v-for="action in actions.data" :key="action.id" class="bg-surface-container-lowest hover:bg-surface-container-low">
                            <td class="p-3">
                                <Link :href="`/incidents/${action.incident.id}?tab=capa`" class="font-code-tabular text-body-sm text-primary font-semibold">
                                    {{ action.capa_number }}
                                </Link>
                            </td>
                            <td class="p-3 font-body-sm text-body-sm text-on-surface">{{ truncate(action.description) }}</td>
                            <td class="p-3">
                                <Link :href="`/incidents/${action.incident.id}?tab=capa`" class="font-code-tabular text-body-sm text-primary font-semibold">
                                    {{ action.incident.incident_number }}
                                </Link>
                            </td>
                            <td class="p-3 font-body-sm text-body-sm text-on-surface">{{ action.responsible ?? '—' }}</td>
                            <td class="p-3 font-body-sm text-body-sm text-on-surface">{{ action.priority }}</td>
                            <td class="p-3 font-code-tabular text-body-sm" :class="action.is_overdue ? 'text-error font-semibold' : 'text-on-surface'">
                                {{ action.due_date ?? '—' }}
                            </td>
                            <td class="p-3">
                                <span class="px-2.5 py-0.5 rounded-full bg-surface-container text-on-surface font-label-sm text-body-sm font-semibold whitespace-nowrap">
                                    {{ action.status.label }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="actions" />
        </div>
    </AuthenticatedLayout>
</template>
