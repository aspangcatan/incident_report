<script setup>
import { computed, ref } from 'vue';
import { severityOrder, severityRamp } from '@/Utils/chartPalette';
import { severityLabel } from '@/Composables/useIncidentStatus';

const props = defineProps({
    // [{ month, label, short, counts: { unassessed, level_1_low, ... }, total }]
    months: { type: Array, required: true },
});

// Bottom-up stacking order: least to most severe, then "not yet assessed" on top.
const stack = [...severityOrder, 'unassessed'];
const legend = stack.map((key) => ({ key, label: key === 'unassessed' ? 'Not yet assessed' : severityLabel(key), color: severityRamp[key] }));

const max = computed(() => Math.max(1, ...props.months.map((m) => m.total)));
const hovered = ref(null);
const hoveredIndex = computed(() => props.months.indexOf(hovered.value));

// Beside the hovered month: to its right in the first half, to its left in the second.
const tooltipStyle = computed(() => {
    const n = props.months.length;
    const i = hoveredIndex.value;
    return i < n / 2
        ? { left: `calc(${((i + 1) / n) * 100}% + 8px)` }
        : { right: `calc(${((n - i) / n) * 100}% + 8px)` };
});
const showTable = ref(false);

function segments(month) {
    return stack.filter((key) => month.counts[key] > 0).map((key) => ({
        key,
        count: month.counts[key],
        percent: (month.counts[key] / max.value) * 100,
        color: severityRamp[key],
    }));
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1" aria-label="Legend">
            <span v-for="item in legend" :key="item.key" class="flex items-center gap-1.5 font-body-sm text-body-sm text-on-surface-variant">
                <span class="w-3 h-3 rounded-sm inline-block" :style="{ backgroundColor: item.color }" />
                {{ item.label }}
            </span>
            <button type="button" class="ml-auto font-label-sm text-body-sm text-primary font-semibold" @click="showTable = !showTable">
                {{ showTable ? 'Show chart' : 'Show as table' }}
            </button>
        </div>

        <div v-if="!showTable" class="relative">
            <div class="flex items-end gap-2 h-56 border-b border-outline-variant">
                <div
                    v-for="month in months"
                    :key="month.month"
                    class="flex-1 h-full flex flex-col justify-end items-center cursor-default"
                    @mouseenter="hovered = month"
                    @mouseleave="hovered = null"
                >
                    <span v-if="month.total" class="font-code-tabular text-[11px] text-on-surface-variant mb-1">{{ month.total }}</span>
                    <!-- flex-col-reverse: first segment (Low) sits on the baseline; 2px surface gap between segments -->
                    <div class="w-full max-w-[44px] flex flex-col-reverse gap-[2px]" :style="{ height: (month.total / max) * 100 + '%' }">
                        <div
                            v-for="(segment, index) in segments(month)"
                            :key="segment.key"
                            :class="index === segments(month).length - 1 ? 'rounded-t-[4px]' : ''"
                            :style="{ backgroundColor: segment.color, flexGrow: segment.count, flexBasis: 0 }"
                        />
                    </div>
                </div>
            </div>
            <div class="flex gap-2 mt-1">
                <span v-for="month in months" :key="month.month" class="flex-1 text-center font-code-tabular text-[11px] text-outline">{{ month.short }}</span>
            </div>

            <div
                v-if="hovered"
                role="tooltip"
                :style="tooltipStyle"
                class="absolute top-0 min-w-[190px] p-3 rounded-lg bg-surface-container-lowest shadow-lg border border-outline-variant flex flex-col gap-1 pointer-events-none"
            >
                <span class="font-label-md text-label-md text-on-surface font-semibold">{{ hovered.label }} · {{ hovered.total }} incident{{ hovered.total === 1 ? '' : 's' }}</span>
                <span v-for="item in legend" :key="item.key" class="flex items-center gap-2 font-body-sm text-body-sm text-on-surface-variant">
                    <span class="w-2.5 h-2.5 rounded-sm inline-block" :style="{ backgroundColor: item.color }" />
                    <span class="flex-1">{{ item.label }}</span>
                    <span class="font-code-tabular text-on-surface">{{ hovered.counts[item.key] }}</span>
                </span>
            </div>
        </div>

        <table v-else class="w-full text-left">
            <thead>
                <tr class="font-label-sm text-body-sm uppercase text-outline">
                    <th class="py-1">Month</th>
                    <th v-for="item in legend" :key="item.key" class="py-1 text-right">{{ item.label }}</th>
                    <th class="py-1 text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="month in months" :key="month.month" class="font-body-sm text-body-sm text-on-surface border-t border-surface-container">
                    <td class="py-1">{{ month.label }}</td>
                    <td v-for="item in legend" :key="item.key" class="py-1 text-right font-code-tabular">{{ month.counts[item.key] }}</td>
                    <td class="py-1 text-right font-code-tabular font-semibold">{{ month.total }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
