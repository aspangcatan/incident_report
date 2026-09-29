<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDate } from '@/Utils/formatDate';

const props = defineProps({
    alert: { type: Object, required: true },
    acknowledged: { type: Boolean, default: false },
    canAcknowledge: { type: Boolean, default: false },
    tracking: { type: Object, default: null },
});

const urgencyClasses = {
    information: 'bg-blue-100 text-blue-900',
    warning: 'bg-amber-100 text-amber-900',
    critical: 'bg-error-container text-on-error-container',
};

const ackForm = useForm({});

function acknowledge() {
    ackForm.post(`/safety-alerts/${props.alert.id}/acknowledge`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="alert.title" />

    <AuthenticatedLayout>
        <article class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
            <div class="flex flex-col gap-2">
                <div class="flex flex-wrap items-center gap-2">
                    <span :class="urgencyClasses[alert.urgency.value]" class="px-2.5 py-0.5 rounded-full font-label-sm text-body-sm font-semibold">{{ alert.urgency.label }}</span>
                    <span class="font-body-sm text-body-sm text-outline">
                        Issued {{ formatDate(alert.created_at) }}<template v-if="alert.creator"> by {{ alert.creator }}</template> · To: {{ alert.audience }}
                    </span>
                </div>
                <h1 class="font-headline-sm text-headline-sm text-on-surface">{{ alert.title }}</h1>
            </div>

            <p class="font-body-md text-body-md text-on-surface whitespace-pre-line">{{ alert.message }}</p>

            <p v-if="alert.incident" class="font-body-sm text-body-sm text-outline">
                Related incident:
                <Link v-if="alert.incident.can_view" :href="`/incidents/${alert.incident.id}`" class="text-primary font-semibold">{{ alert.incident.incident_number }}</Link>
                <span v-else>{{ alert.incident.incident_number }}</span>
            </p>

            <div class="pt-space-sm border-t border-outline-variant">
                <button
                    v-if="canAcknowledge"
                    type="button"
                    :disabled="ackForm.processing"
                    class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60"
                    @click="acknowledge"
                >
                    I have read this
                </button>
                <span v-else-if="acknowledged" class="font-body-md text-body-md text-on-surface">You acknowledged this alert.</span>
            </div>
        </article>

        <section v-if="tracking" class="grid grid-cols-1 lg:grid-cols-2 gap-space-md">
            <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-2">
                <h2 class="font-title-lg text-title-lg text-primary font-bold">Acknowledged ({{ tracking.acknowledged.length }})</h2>
                <p v-if="!tracking.acknowledged.length" class="font-body-sm text-body-sm text-outline">Nobody yet.</p>
                <ul class="flex flex-col gap-1 max-h-96 overflow-y-auto">
                    <li v-for="(person, index) in tracking.acknowledged" :key="index" class="flex justify-between gap-2 font-body-md text-body-md text-on-surface">
                        <span>{{ person.name }}</span>
                        <span class="font-body-sm text-body-sm text-outline whitespace-nowrap">{{ formatDate(person.at) }}</span>
                    </li>
                </ul>
            </div>
            <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-2">
                <h2 class="font-title-lg text-title-lg text-primary font-bold">Not yet acknowledged ({{ tracking.pending.length }})</h2>
                <p v-if="!tracking.pending.length" class="font-body-sm text-body-sm text-outline">Everyone has read it.</p>
                <ul class="flex flex-col gap-1 max-h-96 overflow-y-auto">
                    <li v-for="(name, index) in tracking.pending" :key="index" class="font-body-md text-body-md text-on-surface">{{ name }}</li>
                </ul>
            </div>
        </section>
    </AuthenticatedLayout>
</template>
