<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLogo from '@/Components/AppLogo.vue';
import FlashBanner from '@/Components/FlashBanner.vue';
import RealtimeToasts from '@/Components/RealtimeToasts.vue';
import { useAutoRefresh } from '@/Composables/useAutoRefresh';
import { useRealtime } from '@/Composables/useRealtime';
import { isUrgentNotification } from '@/Utils/notificationLinks';
import { isVoiceMuted, prepareNotificationVoice, setVoiceMuted, speak, spokenText } from '@/Utils/notificationVoice';

const props = defineProps({
    // The page's own props to reload with the badges every 30 seconds, e.g. ['incidents'].
    refresh: { type: Array, default: () => [] },
});

const { refresh: refreshNow } = useAutoRefresh(() => props.refresh);

// Instant notifications (realtime/server.js): read aloud, a toast, and badges and lists updated at once.
const toasts = ref(null);
prepareNotificationVoice();
const voiceMuted = ref(isVoiceMuted());

function toggleVoice() {
    voiceMuted.value = !voiceMuted.value;
    setVoiceMuted(voiceMuted.value);
    if (!voiceMuted.value) speak('Notifications will be read aloud.'); // let them hear the voice
}

useRealtime((event, data) => {
    if (event !== 'notification') return;
    const urgent = isUrgentNotification(data.type);
    speak(spokenText(data), urgent);
    toasts.value?.show(data);
    refreshNow();
});

const page = usePage();
const user = computed(() => page.props.auth?.user);
const initials = computed(() => {
    const name = user.value?.name ?? '';
    return name
        .split(' ')
        .map((part) => part[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
});

const unreadCount = computed(() => page.props.unreadNotificationsCount ?? 0);

const showUserMenu = ref(false);
const userMenuRoot = ref(null);

function handleOutsideClick(event) {
    if (userMenuRoot.value && !userMenuRoot.value.contains(event.target)) {
        showUserMenu.value = false;
    }
}

// Below the lg breakpoint the sidebar slides in over the page; any visit closes it.
const sidebarOpen = ref(false);
const removeNavigateListener = router.on('navigate', () => {
    sidebarOpen.value = false;
});

function onKeydown(event) {
    if (event.key === 'Escape') {
        sidebarOpen.value = false;
        showUserMenu.value = false;
    }
}

onMounted(() => {
    document.addEventListener('click', handleOutsideClick);
    document.addEventListener('keydown', onKeydown);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', handleOutsideClick);
    document.removeEventListener('keydown', onKeydown);
    removeNavigateListener();
});

// Header search: incident number or location, across every incident this user can see.
const search = ref(new URL(page.url, window.location.origin).searchParams.get('search') ?? '');

function submitSearch() {
    const scope = page.props.auth?.can?.viewAllIncidents ? 'all' : 'my-reports';
    router.get('/incidents', { scope, search: search.value.trim() || undefined });
}

const allNavGroups = [
    {
        label: 'Incident Management',
        items: [
            { label: 'All Incidents', icon: 'kit-medical', href: '/incidents?scope=all', queue: 'all', can: 'viewAllIncidents' },
            { label: 'My Reports', icon: 'user', href: '/incidents?scope=my-reports', queue: 'my-reports' },
            { label: 'Draft Reports', icon: 'pen-to-square', href: '/incidents?scope=drafts', queue: 'drafts' },
            { label: 'Awaiting Assessment', icon: 'clipboard-list', href: '/incidents?scope=awaiting-assessment', queue: 'awaiting-assessment' },
            { label: 'Pending Review', icon: 'hourglass-half', href: '/incidents?scope=pending-review', queue: 'pending-review', can: 'viewAllIncidents' },
            { label: 'Under Investigation', icon: 'magnifying-glass', href: '/incidents?scope=under-investigation', queue: 'under-investigation', can: 'viewAllIncidents' },
            { label: 'Corrective Actions', icon: 'square-check', href: '/incidents?scope=corrective-actions', queue: 'corrective-actions', can: 'viewAllIncidents' },
            { label: 'Effectiveness Checks', icon: 'clipboard-check', href: '/incidents?scope=effectiveness-checks', queue: 'effectiveness-checks', can: 'effectivenessChecks' },
            { label: 'Ready for Closure', icon: 'flag-checkered', href: '/incidents?scope=ready-for-closure', queue: 'ready-for-closure', can: 'readyForClosure' },
            { label: 'Closure Approvals', icon: 'stamp', href: '/incidents?scope=closure-approvals', queue: 'closure-approvals', can: 'closureApprovals' },
            { label: 'Resolved / Closed', icon: 'circle-check', href: '/incidents?scope=resolved', queue: 'resolved', can: 'viewAllIncidents' },
        ],
    },
    {
        label: 'Investigation Workspace',
        can: 'investigationWorkspace',
        items: [
            { label: 'Investigation Queue', icon: 'notes-medical', href: '/incidents?scope=investigation-queue', queue: 'investigation-queue' },
            { label: 'Assigned to Me', icon: 'clipboard-user', href: '/incidents?scope=assigned-to-me', queue: 'assigned-to-me' },
            { label: 'History & Findings', icon: 'clock-rotate-left', href: '/incidents?scope=investigation-history', queue: 'investigation-history' },
        ],
    },
    {
        label: 'CAPA Operations',
        can: 'capaOperations',
        items: [
            { label: 'Open Actions', icon: 'list-check', href: '/corrective-actions?queue=open', queue: 'open' },
            { label: 'For Verification', icon: 'shield-halved', href: '/corrective-actions?queue=for-verification', queue: 'for-verification' },
            { label: 'Overdue Actions', icon: 'triangle-exclamation', href: '/corrective-actions?queue=overdue', queue: 'overdue', badgeClass: 'bg-error text-on-error' },
            { label: 'Completed Archive', icon: 'box-archive', href: '/corrective-actions?queue=completed', queue: 'completed' },
        ],
    },
    {
        label: 'Learning & Safety',
        items: [
            { label: 'Safety Alerts', icon: 'bullhorn', href: '/safety-alerts', queue: 'safety-alerts', badgeClass: 'bg-error text-on-error' },
            { label: 'Lessons Learned', icon: 'lightbulb', href: '/lessons-learned' },
            { label: 'Recurrence Reviews', icon: 'arrows-rotate', href: '/recurrence-reviews', queue: 'recurrence-reviews', can: 'viewAnalytics' },
        ],
    },
    {
        label: 'Analytics & Learning',
        can: 'viewAnalytics',
        items: [
            { label: 'Executive Overview', icon: 'chart-line', href: '/analytics' },
            { label: 'Trends', icon: 'arrow-trend-up', href: '/analytics/trends' },
        ],
    },
    {
        label: 'Administration & Audit',
        can: 'administration',
        items: [
            { label: 'Leadership Coverage', icon: 'building', href: '/admin/leadership' },
            { label: 'Incident Types', icon: 'tags', href: '/admin/incident-types', can: 'manageIncidentTypes' },
            { label: 'Workflow Durations', icon: 'clock', href: '/admin/workflow-durations', can: 'manageWorkflowDurations' },
            { label: 'Severity Alerts', icon: 'bell', href: '/admin/severity-alerts', can: 'manageSeverityAlerts' },
            { label: 'Escalation Engine', icon: 'sitemap', href: '/admin/escalation-engine' },
        ],
    },
];

const permissions = computed(() => page.props.auth?.can ?? {});
const allowed = (entry) => !entry.can || permissions.value[entry.can] === true;

const navGroups = computed(() =>
    allNavGroups
        .filter(allowed)
        .map((group) => ({ ...group, items: group.items.filter(allowed) }))
        .filter((group) => group.items.length > 0),
);

const queueCounts = computed(() => page.props.queueCounts ?? {});
const pendingSafetyAlert = computed(() => page.props.pendingSafetyAlert ?? null);
const badgeCount = (item) => (item.queue ? queueCounts.value[item.queue] : null);

const activeLinkClasses = 'bg-white text-primary font-semibold shadow-sm';
const inactiveLinkClasses = 'text-white/75 hover:bg-white/10 hover:text-white';

function queryParam(url) {
    const params = new URL(url, window.location.origin);
    return params.searchParams.get('scope') ?? params.searchParams.get('queue');
}

function isActive(href) {
    // Unwired placeholders ('#') would otherwise resolve to the current page.
    if (!href || href === '#') {
        return false;
    }

    const target = new URL(href, window.location.origin);
    const current = new URL(page.url, window.location.origin);

    if (target.pathname !== current.pathname) {
        return false;
    }

    let currentParam = queryParam(page.url);
    if (target.pathname === '/incidents' && currentParam === null) {
        currentParam = 'my-reports';
    }

    return queryParam(href) === currentParam;
}
</script>

<template>
    <div class="min-h-screen bg-background font-body-md text-on-surface">
        <!-- Sidebar: fixed on lg+, slides in below that -->
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-40 bg-on-background/50 lg:hidden"
            aria-hidden="true"
            @click="sidebarOpen = false"
        />
        <aside
            class="fixed inset-y-0 left-0 z-50 w-72 bg-primary text-white flex flex-col transition-transform duration-200 lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0 shadow-2xl' : '-translate-x-full'"
            aria-label="Main navigation"
        >
            <div class="h-16 shrink-0 px-4 flex items-center gap-3 border-b border-white/10">
                <span class="h-10 w-10 shrink-0 rounded-full bg-white p-0.5 shadow-sm"><AppLogo class="h-full w-full" /></span>
                <span class="flex flex-col leading-tight min-w-0">
                    <span class="font-label-sm text-[0.625rem] uppercase tracking-wider text-secondary-fixed-dim font-bold">Cebu South Medical Center</span>
                    <span class="font-headline-sm text-[0.8125rem] font-bold">CSMC Incident/Accident Reporting System</span>
                </span>
            </div>

            <div class="pt-3 md:pt-1">
                <form class="md:hidden px-4 pb-2" role="search" @submit.prevent="submitSearch">
                    <label class="flex items-center rounded-lg bg-white/10 px-3 py-2 ring-1 ring-white/20 focus-within:ring-2 focus-within:ring-white">
                        <span class="sr-only">Search incidents</span>
                        <FontAwesomeIcon icon="magnifying-glass" class="text-white/60 mr-2" />
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Incident number or location…"
                            class="w-full bg-transparent border-none outline-none p-0 font-body-md text-body-md text-white placeholder:text-white/50 focus:ring-0"
                        />
                    </label>
                </form>
            </div>

            <nav class="flex-1 overflow-y-auto px-3 pb-6 flex flex-col gap-0.5 sidebar-scroll">
                <Link
                    href="/"
                    class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-colors"
                    :class="isActive('/') ? activeLinkClasses : inactiveLinkClasses"
                >
                    <FontAwesomeIcon icon="gauge-high" class="text-title-md w-5" />
                    <span class="font-body-md text-body-md">Dashboard</span>
                </Link>

                <template v-for="group in navGroups" :key="group.label">
                    <div class="pt-4 pb-1 px-3">
                        <span class="font-label-sm text-[0.6875rem] text-white/50 uppercase tracking-wider font-semibold">
                            {{ group.label }}
                        </span>
                    </div>
                    <Link
                        v-for="item in group.items"
                        :key="item.label"
                        :href="item.href"
                        class="flex items-center justify-between gap-2 px-3 py-1.5 rounded-lg transition-colors"
                        :class="isActive(item.href) ? activeLinkClasses : inactiveLinkClasses"
                    >
                        <span class="flex items-center gap-2.5 min-w-0">
                            <FontAwesomeIcon :icon="item.icon" class="text-title-md w-5 shrink-0" />
                            <span class="font-body-md text-body-md truncate">{{ item.label }}</span>
                        </span>
                        <span
                            v-if="badgeCount(item)"
                            class="font-code-tabular text-body-sm min-w-[1.5rem] text-center px-1.5 py-0.5 rounded-full font-semibold"
                            :class="item.badgeClass ?? (isActive(item.href) ? 'bg-primary text-on-primary' : 'bg-white/15 text-white')"
                        >
                            {{ badgeCount(item) }}
                        </span>
                    </Link>
                </template>
            </nav>
        </aside>

        <!-- Top bar -->
        <header class="fixed top-0 right-0 left-0 lg:left-72 z-30 h-16 bg-surface-container-lowest/90 backdrop-blur-xl border-b border-outline-variant/40">
            <div class="h-full px-4 lg:px-margin flex items-center gap-3">
                <button
                    type="button"
                    class="lg:hidden h-10 w-10 -ml-1 rounded-lg text-on-surface-variant hover:bg-surface-container flex items-center justify-center"
                    aria-label="Open menu"
                    @click="sidebarOpen = true"
                >
                    <FontAwesomeIcon icon="bars" class="text-title-lg" />
                </button>
                <span class="lg:hidden flex items-center gap-2 min-w-0">
                    <AppLogo class="h-8 w-8 shrink-0" />
                    <span class="font-headline-sm text-title-sm text-primary font-bold truncate hidden sm:inline">CSMC Incident/Accident</span>
                </span>

                <form class="flex-1 max-w-xl hidden md:flex" role="search" @submit.prevent="submitSearch">
                    <label class="relative w-full flex items-center bg-surface-container-low rounded-lg px-3 py-2 ring-1 ring-outline-variant/40 focus-within:ring-2 focus-within:ring-primary focus-within:bg-surface-container-lowest">
                        <span class="sr-only">Search incidents</span>
                        <FontAwesomeIcon icon="magnifying-glass" class="text-outline mr-2" />
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Search by incident number or location…"
                            class="w-full bg-transparent border-none outline-none p-0 font-body-md text-body-md text-on-surface placeholder:text-outline focus:ring-0"
                        />
                    </label>
                </form>

                <div class="ml-auto flex items-center gap-1 sm:gap-2">
                    <button
                        v-if="page.props.realtime"
                        type="button"
                        class="h-10 w-10 rounded-lg text-on-surface-variant hover:bg-surface-container hover:text-on-surface transition-colors flex items-center justify-center"
                        :aria-label="voiceMuted ? 'Read notifications aloud' : 'Stop reading notifications aloud'"
                        :title="voiceMuted ? 'Reading notifications aloud is off' : 'Notifications are read aloud'"
                        @click="toggleVoice"
                    >
                        <FontAwesomeIcon :icon="voiceMuted ? 'volume-xmark' : 'volume-high'" class="text-title-md" />
                    </button>
                    <Link
                        href="/notifications"
                        class="relative h-10 w-10 rounded-lg text-on-surface-variant hover:bg-surface-container hover:text-on-surface transition-colors flex items-center justify-center"
                        :aria-label="unreadCount > 0 ? `Notifications, ${unreadCount} unread` : 'Notifications'"
                    >
                        <FontAwesomeIcon icon="bell" class="text-title-lg" />
                        <span
                            v-if="unreadCount > 0"
                            class="absolute top-1 right-1 min-w-[18px] h-[18px] px-1 bg-error text-on-error rounded-full text-[10px] leading-[18px] text-center font-bold ring-2 ring-surface-container-lowest"
                        >
                            {{ unreadCount > 9 ? '9+' : unreadCount }}
                        </span>
                    </Link>

                    <div ref="userMenuRoot" class="relative">
                        <button
                            type="button"
                            class="flex items-center gap-2.5 pl-1 pr-2 py-1 rounded-lg hover:bg-surface-container transition-colors"
                            :aria-expanded="showUserMenu"
                            @click="showUserMenu = !showUserMenu"
                        >
                            <span class="w-9 h-9 rounded-full bg-primary text-on-primary flex items-center justify-center font-label-md text-label-md font-bold">
                                {{ initials }}
                            </span>
                            <span class="hidden md:flex flex-col text-left">
                                <span class="font-title-sm text-title-sm text-on-surface leading-tight">{{ user?.name }}</span>
                                <span class="font-body-sm text-body-sm text-outline leading-tight">{{ user?.role_label }}</span>
                            </span>
                            <FontAwesomeIcon icon="chevron-down" class="text-outline text-body-sm" />
                        </button>

                        <div
                            v-if="showUserMenu"
                            class="absolute right-0 mt-2 w-64 rounded-xl bg-surface-container-lowest shadow-lg ring-1 ring-outline-variant/40 p-1.5 z-50"
                        >
                            <div class="px-3 py-2 border-b border-outline-variant/40 mb-1">
                                <p class="font-title-sm text-title-sm text-on-surface">{{ user?.name }}</p>
                                <p class="font-body-sm text-body-sm text-outline">{{ user?.role_label }}</p>
                                <p v-if="user?.designation" class="font-body-sm text-body-sm text-outline">{{ user.designation }}</p>
                            </div>
                            <Link
                                href="/logout"
                                method="post"
                                as="button"
                                class="w-full text-left flex items-center gap-2 px-3 py-2 rounded-lg font-body-md text-body-md text-on-surface hover:bg-surface-container-low"
                            >
                                <FontAwesomeIcon icon="right-from-bracket" />
                                Log out
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <div class="lg:pl-72">
            <FlashBanner />
            <RealtimeToasts ref="toasts" />
            <main class="w-full pt-16 pb-12 px-4 lg:px-margin min-h-screen">
                <div class="flex flex-col w-full max-w-[90rem] mx-auto gap-space-lg py-space-lg">
                    <Link
                        v-if="pendingSafetyAlert && !page.url.startsWith(`/safety-alerts/${pendingSafetyAlert.id}`)"
                        :href="`/safety-alerts/${pendingSafetyAlert.id}`"
                        role="alert"
                        class="rounded-xl p-space-md flex flex-wrap items-center gap-3 border-l-4"
                        :class="pendingSafetyAlert.urgency === 'critical' ? 'bg-error-container text-on-error-container border-error' : 'bg-amber-50 text-amber-900 border-amber-500'"
                    >
                        <FontAwesomeIcon icon="bullhorn" />
                        <span class="font-title-sm text-title-sm font-semibold">Safety alert:</span>
                        <span class="font-body-md text-body-md flex-1 min-w-[12rem]">{{ pendingSafetyAlert.title }}</span>
                        <span class="font-label-md text-label-md font-semibold underline whitespace-nowrap">Read and acknowledge</span>
                    </Link>
                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>

<style scoped>
.sidebar-scroll {
    scrollbar-width: thin;
    scrollbar-color: rgb(255 255 255 / 0.25) transparent;
}
</style>
