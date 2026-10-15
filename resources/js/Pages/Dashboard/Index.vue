<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    stages: { type: Object, required: true },
    kpis: { type: Object, required: true },
});

const page = usePage();
const user = computed(() => page.props.auth?.user);
const firstName = computed(() => (user.value?.name ?? '').split(' ')[0]);

const greeting = computed(() => {
    const hour = new Date().getHours();
    return hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
});
const today = new Intl.DateTimeFormat('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }).format(new Date());

// Work waiting on this user, from the same counts as the sidebar badges (only queues they can open have a count).
const queueLinks = [
    { queue: 'awaiting-assessment', label: 'Awaiting assessment', icon: 'clipboard-list', href: '/incidents?scope=awaiting-assessment' },
    { queue: 'pending-review', label: 'Pending CQI triage', icon: 'hourglass-half', href: '/incidents?scope=pending-review' },
    { queue: 'investigation-queue', label: 'Investigation queue', icon: 'notes-medical', href: '/incidents?scope=investigation-queue' },
    { queue: 'assigned-to-me', label: 'Assigned to me', icon: 'clipboard-user', href: '/incidents?scope=assigned-to-me' },
    { queue: 'open', label: 'Open corrective actions', icon: 'list-check', href: '/corrective-actions?queue=open' },
    { queue: 'for-verification', label: 'CAPA for verification', icon: 'shield-halved', href: '/corrective-actions?queue=for-verification' },
    { queue: 'overdue', label: 'Overdue corrective actions', icon: 'triangle-exclamation', href: '/corrective-actions?queue=overdue', urgent: true },
    { queue: 'effectiveness-checks', label: 'Effectiveness checks', icon: 'clipboard-check', href: '/incidents?scope=effectiveness-checks' },
    { queue: 'ready-for-closure', label: 'Ready for closure', icon: 'flag-checkered', href: '/incidents?scope=ready-for-closure' },
    { queue: 'closure-approvals', label: 'Closure approvals', icon: 'stamp', href: '/incidents?scope=closure-approvals' },
    { queue: 'drafts', label: 'Reports returned to you', icon: 'pen-to-square', href: '/incidents?scope=drafts' },
    { queue: 'safety-alerts', label: 'Safety alerts to read', icon: 'bullhorn', href: '/safety-alerts', urgent: true },
    { queue: 'recurrence-reviews', label: 'Recurrence reviews', icon: 'arrows-rotate', href: '/recurrence-reviews' },
];

const attention = computed(() => {
    const counts = page.props.queueCounts ?? {};
    return queueLinks.filter((link) => counts[link.queue] > 0).map((link) => ({ ...link, count: counts[link.queue] }));
});

// Counts are live and limited to the incidents you can see; drafts are not counted.
const lifecycleStages = computed(() => [
    { label: 'Reported', icon: 'clipboard-user', count: props.stages.reported },
    { label: 'For Review', icon: 'hourglass-half', count: props.stages.forReview },
    { label: 'Investigation', icon: 'magnifying-glass', count: props.stages.investigation },
    { label: 'CAPA Action', icon: 'list-check', count: props.stages.capa },
    { label: 'Verification & Approval', icon: 'shield-halved', count: props.stages.verification },
    { label: 'Closed', icon: 'circle-check', count: props.stages.closed },
]);

const tones = {
    navy: 'bg-primary-fixed text-primary',
    teal: 'bg-secondary-container text-on-secondary-container',
    amber: 'bg-amber-100 text-amber-800',
    red: 'bg-error-container text-error',
};

const kpiCards = computed(() => [
    { label: 'Total incidents this year', value: props.kpis.totalThisYear, icon: 'chart-line', tone: 'navy' },
    { label: 'Pending review', value: props.kpis.pendingReview, icon: 'hourglass-half', tone: 'amber' },
    { label: 'Active investigations', value: props.kpis.activeInvestigations, icon: 'magnifying-glass', tone: 'navy' },
    { label: 'Overdue CAPA', value: props.kpis.overdueCapa, icon: 'triangle-exclamation', tone: props.kpis.overdueCapa > 0 ? 'red' : 'teal' },
    { label: 'Sentinel events this year', value: props.kpis.sentinelThisYear, icon: 'circle-exclamation', tone: props.kpis.sentinelThisYear > 0 ? 'red' : 'teal' },
    { label: 'Closed & verified', value: props.kpis.closedAndVerified, icon: 'square-check', tone: 'teal' },
]);
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout :refresh="['stages', 'kpis']">
        <!-- Welcome -->
        <section class="relative overflow-hidden rounded-2xl bg-primary text-on-primary shadow-sm">
            <img src="/images/landing/hero-team-corridor.jpg" alt="" class="absolute inset-y-0 right-0 h-full w-1/2 object-cover hidden md:block" />
            <div class="absolute inset-0 bg-gradient-to-r from-primary via-primary md:via-55% to-primary/30" />
            <div class="relative p-6 md:p-8 flex flex-col gap-4 max-w-2xl">
                <span class="font-label-sm text-label-sm uppercase tracking-wider text-secondary-fixed-dim">{{ today }}</span>
                <div class="flex flex-col gap-1">
                    <h1 class="font-headline-xl text-headline-xl-mobile md:text-headline-xl font-bold tracking-tight">{{ greeting }}, {{ firstName }}</h1>
                    <p class="font-body-lg text-body-lg text-white/80">
                        Signed in as <span class="font-semibold text-white">{{ user?.role_label }}</span>.
                        {{ attention.length ? 'Here is what needs you today.' : 'Nothing is waiting on you right now.' }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2 pt-1">
                    <Link
                        href="/incidents/create"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-secondary-fixed-dim text-on-secondary-fixed hover:bg-secondary-fixed font-label-md text-label-md font-semibold shadow-sm transition-colors"
                    >
                        <FontAwesomeIcon icon="circle-plus" /> Report an incident
                    </Link>
                    <Link
                        href="/incidents?scope=my-reports"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-white/10 ring-1 ring-white/25 hover:bg-white/20 font-label-md text-label-md font-semibold transition-colors"
                    >
                        <FontAwesomeIcon icon="user" /> My reports
                    </Link>
                </div>
            </div>
        </section>

        <!-- Needs your attention -->
        <section v-if="attention.length" class="flex flex-col gap-3">
            <h2 class="font-title-lg text-title-lg text-on-surface font-semibold flex items-center gap-2">
                <FontAwesomeIcon icon="bell" class="text-primary" /> Needs your attention
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                <Link
                    v-for="item in attention"
                    :key="item.queue"
                    :href="item.href"
                    class="group flex items-center gap-3 p-4 rounded-xl bg-surface-container-lowest shadow-sm ring-1 transition-shadow hover:shadow-md"
                    :class="item.urgent ? 'ring-error/40' : 'ring-outline-variant/40'"
                >
                    <span
                        class="h-11 w-11 shrink-0 rounded-lg flex items-center justify-center"
                        :class="item.urgent ? 'bg-error-container text-error' : 'bg-primary-fixed text-primary'"
                    >
                        <FontAwesomeIcon :icon="item.icon" class="text-title-md" />
                    </span>
                    <span class="flex-1 min-w-0">
                        <span class="block font-code-tabular text-headline-sm font-bold" :class="item.urgent ? 'text-error' : 'text-on-surface'">{{ item.count }}</span>
                        <span class="block font-body-md text-body-md text-on-surface-variant truncate">{{ item.label }}</span>
                    </span>
                    <FontAwesomeIcon icon="chevron-right" class="text-outline group-hover:text-primary transition-colors" />
                </Link>
            </div>
        </section>

        <!-- Pipeline -->
        <section class="rounded-2xl bg-surface-container-lowest p-5 md:p-6 shadow-sm ring-1 ring-outline-variant/40 flex flex-col gap-5">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 class="font-title-lg text-title-lg text-on-surface font-semibold">Incident pipeline</h2>
                    <p class="font-body-sm text-body-sm text-outline">Incidents you can see, by stage. Updates every 30 seconds.</p>
                </div>
                <Link v-if="page.props.auth?.can?.viewAllIncidents" href="/incidents?scope=all" class="font-label-md text-label-md text-primary font-semibold">
                    View all incidents <FontAwesomeIcon icon="arrow-right" />
                </Link>
            </div>
            <ol class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
                <li
                    v-for="(stage, index) in lifecycleStages"
                    :key="stage.label"
                    class="relative flex flex-col gap-2 p-4 rounded-xl"
                    :class="index === lifecycleStages.length - 1 ? 'bg-secondary-container/50' : 'bg-surface-container-low'"
                >
                    <div class="flex items-center justify-between">
                        <span
                            class="h-9 w-9 rounded-lg flex items-center justify-center"
                            :class="index === lifecycleStages.length - 1 ? 'bg-secondary text-on-secondary' : 'bg-primary text-on-primary'"
                        >
                            <FontAwesomeIcon :icon="stage.icon" />
                        </span>
                        <span class="font-label-sm text-label-sm text-outline">Step {{ index + 1 }}</span>
                    </div>
                    <span class="font-code-tabular text-headline-lg text-on-surface font-bold">{{ stage.count }}</span>
                    <span class="font-label-md text-label-md text-on-surface-variant font-semibold">{{ stage.label }}</span>
                </li>
            </ol>
        </section>

        <!-- KPIs -->
        <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <div
                v-for="card in kpiCards"
                :key="card.label"
                class="flex items-center gap-4 p-5 rounded-xl bg-surface-container-lowest shadow-sm ring-1 ring-outline-variant/40"
            >
                <span class="h-12 w-12 shrink-0 rounded-xl flex items-center justify-center" :class="tones[card.tone]">
                    <FontAwesomeIcon :icon="card.icon" class="text-title-lg" />
                </span>
                <span class="flex flex-col min-w-0">
                    <span class="font-code-tabular text-headline-lg text-on-surface font-bold leading-none">{{ card.value }}</span>
                    <span class="font-body-md text-body-md text-on-surface-variant mt-1">{{ card.label }}</span>
                </span>
            </div>
        </section>
    </AuthenticatedLayout>
</template>
