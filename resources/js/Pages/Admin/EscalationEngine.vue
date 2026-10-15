<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ConfirmationDialog from '@/Components/ConfirmationDialog.vue';
import { formatDate } from '@/Utils/formatDate';

const props = defineProps({
    rules: { type: Array, required: true },
    overdue: { type: Array, required: true },
    runs: { type: Array, required: true },
    schedulerStale: { type: Boolean, required: true },
});

const lastRun = computed(() => props.runs[0] ?? null);
const ruleNames = computed(() => Object.fromEntries(props.rules.map((rule) => [rule.key, rule.name])));

const showConfirm = ref(false);
const running = ref(false);

function runNow() {
    router.post('/admin/escalation-engine/run', {}, {
        preserveScroll: true,
        onStart: () => { running.value = true; },
        onFinish: () => { running.value = false; showConfirm.value = false; },
    });
}

function overdueBy(dueAt) {
    const hours = Math.floor((Date.now() - new Date(dueAt).getTime()) / 3600000);
    if (hours < 1) return 'Less than an hour';
    if (hours < 48) return `${hours} ${hours === 1 ? 'hour' : 'hours'}`;
    return `${Math.floor(hours / 24)} days`;
}

// "3 investigation overdue, 1 corrective action due soon" - only the rules that sent something.
function runBreakdown(counts) {
    return Object.entries(counts)
        .filter(([, count]) => count > 0)
        .map(([key, count]) => `${count} × ${ruleNames.value[key] ?? key}`)
        .join(', ');
}
</script>

<template>
    <Head title="Escalation Engine" />

    <AuthenticatedLayout :refresh="['overdue', 'runs', 'schedulerStale']">
        <div class="flex flex-wrap items-start justify-between gap-space-md">
            <div class="flex flex-col gap-1 min-w-0">
                <h1 class="font-headline-sm text-headline-sm text-on-surface">Escalation Engine</h1>
                <p class="font-body-sm text-body-sm text-outline">
                    Once a day the system sends deadline reminders and escalates work that is past its time limit. Each item is reminded or escalated only once.
                    Time limits are set on <Link href="/admin/workflow-durations" class="text-primary font-semibold">Workflow Durations</Link>.
                </p>
            </div>
            <button
                type="button"
                class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold whitespace-nowrap"
                @click="showConfirm = true"
            >
                <FontAwesomeIcon icon="play" /> Run now
            </button>
        </div>

        <div v-if="schedulerStale" role="alert" class="rounded-xl bg-amber-50 p-space-md font-body-sm text-body-sm text-amber-900 flex flex-col gap-1">
            <span class="font-semibold">The daily run is not happening.</span>
            <span>
                {{ lastRun ? `The last run was ${formatDate(lastRun.started_at)}.` : 'It has never run.' }}
                Reminders and escalations are only sent when it runs. Ask IT to schedule
                <code class="font-code-tabular">php artisan schedule:run</code> every minute on the server (Windows Task Scheduler), or use Run now.
            </span>
        </div>
        <div v-else-if="lastRun" class="rounded-xl bg-surface-container-low p-space-md font-body-sm text-body-sm text-on-surface">
            Last run {{ formatDate(lastRun.started_at) }} by {{ lastRun.triggered_by ?? 'the daily schedule' }}:
            {{ lastRun.total === 0 ? 'nothing new to send.' : `${lastRun.total} reminded or escalated.` }}
        </div>

        <section class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
            <div class="flex flex-col gap-0.5">
                <h2 class="font-title-lg text-title-lg text-primary font-bold">Overdue now</h2>
                <p class="font-body-sm text-body-sm text-outline">Work past its time limit right now, most overdue first.</p>
            </div>
            <p v-if="overdue.length === 0" class="p-space-md text-center font-body-sm text-body-sm text-outline">Nothing is overdue.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="font-label-md text-label-md text-outline">
                            <th class="p-2">Stage</th>
                            <th class="p-2">Reference</th>
                            <th class="p-2">Department</th>
                            <th class="p-2">Was due</th>
                            <th class="p-2">Overdue by</th>
                            <th class="p-2">Escalated</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-container">
                        <tr v-for="item in overdue" :key="`${item.stage}-${item.reference}`" class="font-body-sm text-body-sm text-on-surface">
                            <td class="p-2 font-semibold">{{ item.stage }}</td>
                            <td class="p-2">
                                <Link :href="`/incidents/${item.incident_id}`" class="font-code-tabular text-primary font-semibold">{{ item.reference }}</Link>
                            </td>
                            <td class="p-2">{{ item.department ?? '—' }}</td>
                            <td class="p-2 whitespace-nowrap">{{ formatDate(item.due_at) }}</td>
                            <td class="p-2 whitespace-nowrap text-error font-semibold">{{ overdueBy(item.due_at) }}</td>
                            <td class="p-2 whitespace-nowrap">
                                <span v-if="item.escalated_at">{{ formatDate(item.escalated_at) }}</span>
                                <span v-else class="text-outline">Not yet, at the next run</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
            <h2 class="font-title-lg text-title-lg text-primary font-bold">Rules</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="font-label-md text-label-md text-outline">
                            <th class="p-2">Rule</th>
                            <th class="p-2">Time limit</th>
                            <th class="p-2">Who is notified</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-container">
                        <tr v-for="rule in rules" :key="rule.key" class="font-body-sm text-body-sm text-on-surface align-top">
                            <td class="p-2">
                                <span class="block font-semibold">{{ rule.name }}</span>
                                <span class="block text-outline">{{ rule.when }}</span>
                            </td>
                            <td class="p-2 font-code-tabular">{{ rule.limit }}</td>
                            <td class="p-2">{{ rule.notifies }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
            <h2 class="font-title-lg text-title-lg text-primary font-bold">Recent runs</h2>
            <p v-if="runs.length === 0" class="p-space-md text-center font-body-sm text-body-sm text-outline">No runs yet.</p>
            <ul v-else class="divide-y divide-surface-container">
                <li v-for="run in runs" :key="run.id" class="py-2 flex flex-col gap-0.5 font-body-sm text-body-sm text-on-surface">
                    <span>
                        <span class="font-semibold">{{ formatDate(run.started_at) }}</span>
                        <span class="text-outline"> · {{ run.triggered_by ? `Run now by ${run.triggered_by}` : 'Daily schedule' }}</span>
                        <span v-if="!run.finished_at" class="text-error"> · did not finish</span>
                    </span>
                    <span>{{ run.total === 0 ? 'Nothing new to send.' : runBreakdown(run.counts) }}</span>
                    <span v-if="run.warning" class="text-amber-900">{{ run.warning }}</span>
                </li>
            </ul>
        </section>

        <ConfirmationDialog
            :show="showConfirm"
            title="Run the escalation check now?"
            message="This sends any reminders and escalations that are due right now, the same as the daily run. Items already reminded or escalated are not sent again."
            confirm-label="Run now"
            :processing="running"
            @cancel="showConfirm = false"
            @confirm="runNow"
        />
    </AuthenticatedLayout>
</template>
