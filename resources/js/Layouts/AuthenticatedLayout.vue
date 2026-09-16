<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLogo from '@/Components/AppLogo.vue';

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

const showUserMenu = ref(false);
const userMenuRoot = ref(null);

function handleOutsideClick(event) {
    if (userMenuRoot.value && !userMenuRoot.value.contains(event.target)) {
        showUserMenu.value = false;
    }
}

onMounted(() => document.addEventListener('click', handleOutsideClick));
onBeforeUnmount(() => document.removeEventListener('click', handleOutsideClick));

const navGroups = [
    {
        label: 'Incident Management',
        items: [
            { label: 'All Incidents', icon: 'kit-medical', href: '#', count: null },
            { label: 'My Reports', icon: 'user', href: '#', count: null },
            { label: 'Draft Reports', icon: 'pen-to-square', href: '#', count: null },
            { label: 'Pending Review', icon: 'hourglass-half', href: '#', count: null },
            { label: 'Under Investigation', icon: 'magnifying-glass', href: '#', count: null },
            { label: 'Corrective Actions', icon: 'square-check', href: '#', count: null },
            { label: 'Resolved / Closed', icon: 'circle-check', href: '#', count: null },
        ],
    },
    {
        label: 'Investigation Workspace',
        items: [
            { label: 'Investigation Queue', icon: 'notes-medical', href: '#', count: null },
            { label: 'Assigned to Me', icon: 'clipboard-user', href: '#', count: null },
            { label: 'History & Findings', icon: 'clock-rotate-left', href: '#', count: null },
        ],
    },
    {
        label: 'CAPA Operations',
        items: [
            { label: 'Open Actions', icon: 'list-check', href: '#', count: null },
            { label: 'For Verification', icon: 'shield-halved', href: '#', count: null },
            { label: 'Overdue Actions', icon: 'triangle-exclamation', href: '#', count: null },
            { label: 'Completed Archive', icon: 'box-archive', href: '#', count: null },
        ],
    },
    {
        label: 'Analytics & Learning',
        items: [
            { label: 'Executive Overview', icon: 'chart-line', href: '#', count: null },
            { label: 'Trends & Sentinels', icon: 'arrow-trend-up', href: '#', count: null },
            { label: 'Unit & Severity Heatmap', icon: 'table-cells', href: '#', count: null },
            { label: 'Resolution Times', icon: 'stopwatch', href: '#', count: null },
        ],
    },
    {
        label: 'Administration & Audit',
        items: [
            { label: 'Escalation Engine', icon: 'sitemap', href: '#', count: null },
            { label: 'Departments & Units', icon: 'building', href: '#', count: null },
            { label: 'Audit Trail & Custody', icon: 'file-contract', href: '#', count: null },
        ],
    },
];
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
                    <button type="button" class="relative p-2 rounded-lg text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface transition-colors">
                        <FontAwesomeIcon icon="bell" class="text-title-lg" />
                    </button>

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
                <a
                    href="#"
                    class="flex items-center justify-center gap-space-xs w-full py-2.5 px-space-md rounded-lg bg-secondary text-on-secondary hover:bg-on-secondary-container font-label-md text-label-md transition-all shadow-[0_1px_3px_rgba(0,106,97,0.2)]"
                >
                    <FontAwesomeIcon icon="circle-plus" class="text-title-md" />
                    <span>Report an Incident</span>
                </a>
            </div>

            <nav class="flex-1 px-space-sm flex flex-col gap-0.5">
                <Link
                    href="/"
                    class="flex items-center justify-between px-3 py-2 rounded-lg transition-colors bg-primary-container text-on-primary font-semibold shadow-[0_1px_3px_rgba(0,35,111,0.1)]"
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
                    <a
                        v-for="item in group.items"
                        :key="item.label"
                        :href="item.href"
                        class="flex items-center justify-between px-3 py-1.5 rounded-lg text-on-surface-variant hover:bg-surface-container hover:text-on-surface transition-colors"
                    >
                        <div class="flex items-center gap-2.5">
                            <FontAwesomeIcon :icon="item.icon" class="text-title-md w-5" />
                            <span class="font-body-md text-body-md">{{ item.label }}</span>
                        </div>
                        <span
                            v-if="item.count !== null"
                            class="font-code-tabular text-body-sm px-2 py-0.5 rounded-full bg-surface-container text-on-surface font-semibold"
                        >
                            {{ item.count }}
                        </span>
                    </a>
                </template>
            </nav>
        </aside>

        <div class="pl-72">
            <main class="w-full pt-16 pb-12 px-margin min-h-screen">
                <div class="flex flex-col w-full gap-space-lg py-space-lg">
                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>
