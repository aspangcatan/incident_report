<script setup>
import { categoricalPalette as palette } from '@/Utils/chartPalette';

const props = defineProps({
    // [{ category: string, incidentCount: number }], pre-sorted descending by the caller.
    segments: { type: Array, required: true },
});

const total = props.segments.reduce((sum, s) => sum + s.incidentCount, 0);

function widthPercent(count) {
    return total > 0 ? (count / total) * 100 : 0;
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div v-if="!segments.length" class="text-center font-body-sm text-body-sm text-outline p-space-md">
            No contributing-factor data recorded in this window.
        </div>
        <template v-else>
            <div class="flex w-full h-6 rounded-full overflow-hidden bg-surface-container">
                <div
                    v-for="(segment, index) in segments"
                    :key="segment.category"
                    class="h-full flex items-center justify-center"
                    :style="{
                        width: widthPercent(segment.incidentCount) + '%',
                        backgroundColor: palette[index % palette.length],
                        marginRight: index < segments.length - 1 ? '2px' : '0',
                    }"
                    :title="`${segment.category}: ${segment.incidentCount}`"
                >
                    <span v-if="widthPercent(segment.incidentCount) >= 12" class="font-label-sm text-body-sm text-white font-semibold px-1 truncate">
                        {{ Math.round(widthPercent(segment.incidentCount)) }}%
                    </span>
                </div>
            </div>
            <div class="flex flex-wrap gap-3">
                <div v-for="(segment, index) in segments" :key="segment.category" class="flex items-center gap-1.5">
                    <span class="w-3 h-3 rounded-full inline-block" :style="{ backgroundColor: palette[index % palette.length] }" />
                    <span class="font-body-sm text-body-sm text-on-surface-variant">{{ segment.category }} ({{ segment.incidentCount }})</span>
                </div>
            </div>
        </template>
    </div>
</template>
