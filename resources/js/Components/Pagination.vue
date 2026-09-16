<script setup>
import { router } from '@inertiajs/vue3';

const props = defineProps({
    paginator: { type: Object, required: true },
});

function visit(url) {
    if (url) {
        router.get(url, {}, { preserveState: true, preserveScroll: true });
    }
}
</script>

<template>
    <div
        v-if="paginator.last_page > 1"
        class="flex items-center justify-between px-space-sm py-2 font-body-sm text-body-sm text-outline"
    >
        <span>
            Showing {{ paginator.from }}–{{ paginator.to }} of {{ paginator.total }}
        </span>
        <div class="flex items-center gap-1">
            <button
                type="button"
                :disabled="!paginator.prev_page_url"
                class="px-3 py-1.5 rounded-lg bg-surface-container text-on-surface font-label-sm disabled:opacity-40"
                @click="visit(paginator.prev_page_url)"
            >
                Previous
            </button>
            <span class="px-2 font-code-tabular">
                Page {{ paginator.current_page }} of {{ paginator.last_page }}
            </span>
            <button
                type="button"
                :disabled="!paginator.next_page_url"
                class="px-3 py-1.5 rounded-lg bg-surface-container text-on-surface font-label-sm disabled:opacity-40"
                @click="visit(paginator.next_page_url)"
            >
                Next
            </button>
        </div>
    </div>
</template>
