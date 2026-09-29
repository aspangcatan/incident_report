<script setup>
import { computed } from 'vue';
import { categoricalPalette } from '@/Utils/chartPalette';

const props = defineProps({
    // [{ id, name, count, previous }], largest first
    items: { type: Array, required: true },
    empty: { type: String, default: 'No incidents in this period.' },
});

const max = computed(() => Math.max(1, ...props.items.map((i) => i.count)));

function change(item) {
    const diff = item.count - item.previous;
    if (diff === 0) return 'same as previous period';
    return `${diff > 0 ? '▲' : '▼'} ${Math.abs(diff)} vs previous period (${item.previous})`;
}
</script>

<template>
    <p v-if="!items.length" class="font-body-sm text-body-sm text-outline text-center p-space-md">{{ empty }}</p>
    <ul v-else class="flex flex-col gap-3">
        <li v-for="item in items" :key="item.id" class="flex flex-col gap-1" :title="`${item.name}: ${item.count} (previous period ${item.previous})`">
            <div class="flex items-baseline justify-between gap-2">
                <span class="font-body-md text-body-md text-on-surface truncate">{{ item.name }}</span>
                <span class="font-code-tabular text-body-md text-on-surface font-semibold">{{ item.count }}</span>
            </div>
            <div class="h-2 rounded-full bg-surface-container overflow-hidden">
                <div class="h-full rounded-r-[4px]" :style="{ width: (item.count / max) * 100 + '%', backgroundColor: categoricalPalette[0] }" />
            </div>
            <span class="font-body-sm text-body-sm text-outline">{{ change(item) }}</span>
        </li>
    </ul>
</template>
