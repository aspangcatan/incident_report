<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLogo from '@/Components/AppLogo.vue';
import FlashBanner from '@/Components/FlashBanner.vue';

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

onMounted(() => document.addEventListener('click', handleOutsideClick));
onBeforeUnmount(() => document.removeEventListener('click', handleOutsideClick));

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
            { label: 'Escalation Engine', icon: 'sitemap', href: '#' },
            { label: 'Departments & Units', icon: 'building', href: '#' },
            { label: 'Audit Trail & Custody', icon: 'file-contract', href: '#' },
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

const activeLinkClasses = 'bg-primary-container text-on-primary font-semibold shadow-[0_1px_3px_rgba(0,35,111,0.1)]';
const inactiveLinkClasses = 'text-on-surface-variant hover:bg-surface-container hover:text-on-surface';

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
        <header class="fixed top-0 left-0 right-0 z-50 bg-surface-container-lowest/95 backdrop-blur-xl shadow-[0_1px_8px_rgba(0,0,0,0.04)]">
            <div class="h-16 w-full px-margin flex items-center justify-between gap-space-md">
                <div class="flex items-center gap-space-md min-w-[280px]">
                    <AppLogo class="h-8 w-8" />
                    <div class="flex flex-col">
                        <span class="font-label-sm text-label-sm uppercase tracking-wider text-primary font-bold">
                            Philippine National Health System
                        </span>
                        <span class="font-title-sm text-title-sm text-on-surface font-semibold tracking-tight">
                            HOPSS e-Incident
                        </span>
                    </div>
                </div>

                <div class="flex-1 max-w-xl hidden xl:flex items-center gap-space-sm">
                    <div class="relative w-full flex items-center bg-surface-container-low rounded-lg px-space-md py-1.5 focus-within:ring-2 focus-within:ring-primary">
                        <FontAwesomeIcon icon="magnifying-glass" class="text-outline text-title-md mr-2" />
                        <input
                            type="text"
                            placeholder="Search incident ID, patient, reporter, clinical unit..."
                            class="w-full bg-transparent border-none outline-none font-body-sm text-body-sm text-on-surface placeholder:text-outline"
                        />
                    </div>
                </div>

                <div class="flex items-center gap-space-md">
                    <Link href="/notifications" class="relative p-2 rounded-lg text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface transition-colors">
                        <FontAwesomeIcon icon="bell" class="text-title-lg" />
                        <span
                            v-if="unreadCount > 0"
                            class="absolute top-1 right-1 min-w-[16px] h-4 px-1 bg-error text-on-error rounded-full font-label-sm text-[10px] leading-4 text-center font-bold"
                        >
                            {{ unreadCount > 9 ? '9+' : unreadCount }}
                        </span>
                    </Link>

                    <div ref="userMenuRoot" class="relative">
                        <button
                            type="button"
                            class="flex items-center gap-space-sm pl-2"
                            @click="showUserMenu = !showUserMenu"
                        >
                            <div class="w-8 h-8 rounded-full bg-primary-container text-on-primary flex items-center justify-center font-label-sm text-body-sm font-bold">
                                {{ initials }}
                            </div>
                            <div class="hidden md:flex flex-col text-left">
                                <span class="font-title-sm text-title-sm text-on-surface leading-tight">{{ user?.name }}</span>
                                <span class="font-label-sm text-body-sm text-outline leading-tight">{{ user?.designation }}</span>
                            </div>
                            <FontAwesomeIcon icon="chevron-down" class="text-outline text-body-sm" />
                        </button>

                        <div
                            v-if="showUserMenu"
                            class="absolute right-0 mt-2 w-48 rounded-lg bg-surface-container-lowest shadow-lg border border-outline-variant/30 py-1 z-50"
                        >
                            <Link
                                href="/logout"
                                method="post"
                                as="button"
                                class="w-full text-left flex items-center gap-2 px-3 py-2 font-body-sm text-body-sm text-on-surface hover:bg-surface-container-low"
                            >
                                <FontAwesomeIcon icon="right-from-bracket" />
                                Log out
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <aside class="fixed left-0 top-0 bottom-0 h-screen w-72 bg-surface-container-lowest z-40 flex flex-col pt-20 pb-4 overflow-y-auto shadow-[0_1px_8px_rgba(0,0,0,0.03)]">
            <div class="px-space-md mb-3">
                <Link
                    href="/incidents/create"
                    class="flex items-center justify-center gap-space-xs w-full py-2.5 px-space-md rounded-lg bg-secondary text-on-secondary hover:bg-on-secondary-container font-label-md text-label-md transition-all shadow-[0_1px_3px_rgba(0,106,97,0.2)]"
                >
                    <FontAwesomeIcon icon="circle-plus" class="text-title-md" />
                    <span>Report an Incident</span>
                </Link>
            </div>

            <nav class="flex-1 px-space-sm flex flex-col gap-0.5">
                <Link
                    href="/"
                    class="flex items-center justify-between px-3 py-2 rounded-lg transition-colors"
                    :class="isActive('/') ? activeLinkClasses : inactiveLinkClasses"
                >
                    <div class="flex items-center gap-2.5">
                        <FontAwesomeIcon icon="gauge-high" class="text-title-md" />
                        <span class="font-body-md text-body-md">Dashboard</span>
                    </div>
                </Link>

                <template v-for="group in navGroups" :key="group.label">
                    <div class="pt-3 pb-1 px-3">
                        <span class="font-label-sm text-body-sm text-outline uppercase tracking-wider font-semibold">
                            {{ group.label }}
                        </span>
                    </div>
                    <Link
                        v-for="item in group.items"
                        :key="item.label"
                        :href="item.href"
                        class="flex items-center justify-between px-3 py-1.5 rounded-lg transition-colors"
                        :class="isActive(item.href) ? activeLinkClasses : inactiveLinkClasses"
                    >
                        <div class="flex items-center gap-2.5">
                            <FontAwesomeIcon :icon="item.icon" class="text-title-md w-5" />
                            <span class="font-body-md text-body-md">{{ item.label }}</span>
                        </div>
                        <span
                            v-if="badgeCount(item)"
                            class="font-code-tabular text-body-sm px-2 py-0.5 rounded-full font-semibold"
                            :class="item.badgeClass ?? 'bg-surface-container text-on-surface'"
                        >
                            {{ badgeCount(item) }}
                        </span>
                    </Link>
                </template>
            </nav>
        </aside>

        <div class="pl-72">
            <FlashBanner />
            <main class="w-full pt-16 pb-12 px-margin min-h-screen">
                <div class="flex flex-col w-full gap-space-lg py-space-lg">
                    <Link
                        v-if="pendingSafetyAlert && !page.url.startsWith(`/safety-alerts/${pendingSafetyAlert.id}`)"
                        :href="`/safety-alerts/${pendingSafetyAlert.id}`"
                        role="alert"
                        class="rounded-lg p-space-md flex items-center gap-3 border-l-4"
                        :class="pendingSafetyAlert.urgency === 'critical' ? 'bg-error-container text-on-error-container border-error' : 'bg-amber-50 text-amber-900 border-amber-500'"
                    >
                        <FontAwesomeIcon icon="bullhorn" />
                        <span class="font-title-sm text-title-sm font-semibold">Safety alert:</span>
                        <span class="font-body-md text-body-md flex-1">{{ pendingSafetyAlert.title }}</span>
                        <span class="font-label-md text-label-md font-semibold underline whitespace-nowrap">Read and acknowledge</span>
                    </Link>
                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>
