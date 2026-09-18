<script setup>
import { dayShiftColor as dayColor, nightShiftColor as nightColor } from '@/Utils/chartPalette';

const props = defineProps({
    // [{ hour: number, count: number, shift: 'day'|'night' }] x24, hour-ascending.
    hours: { type: Array, required: true },
});

const maxCount = Math.max(1, ...props.hours.map((h) => h.count));

function barHeightPercent(count) {
    return (count / maxCount) * 100;
}

function formatHourLabel(hour) {
    if (hour === 0) return '12a';
    if (hour === 12) return '12p';
    return hour < 12 ? `${hour}a` : `${hour - 12}p`;
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex items-end gap-1 h-40">
            <div v-for="entry in hours" :key="entry.hour" class="flex-1 flex flex-col items-center justify-end h-full gap-1" :title="`${formatHourLabel(entry.hour)}: ${entry.count} incident(s), ${entry.shift} shift`">
                <div
                    class="w-full rounded-t-[4px]"
                    :style="{
                        height: Math.max(barHeightPercent(entry.count), entry.count > 0 ? 4 : 0) + '%',
                        backgroundColor: entry.shift === 'day' ? dayColor : nightColor,
                    }"
                />
            </div>
        </div>
        <div class="flex gap-1">
            <span v-for="entry in hours" :key="entry.hour" class="flex-1 text-center font-code-tabular text-[10px] text-outline">
                {{ entry.hour % 3 === 0 ? formatHourLabel(entry.hour) : '' }}
            </span>
        </div>
        <div class="flex gap-3">
            <div class="flex items-center gap-1.5">
                <span class="w-3 h-3 rounded-full inline-block" :style="{ backgroundColor: dayColor }" />
                <span class="font-body-sm text-body-sm text-on-surface-variant">Day shift (07:00-18:59)</span>
            </div>
            <div class="flex items-center gap-1.5">
                <span class="w-3 h-3 rounded-full inline-block" :style="{ backgroundColor: nightColor }" />
                <span class="font-body-sm text-body-sm text-on-surface-variant">Night shift (19:00-06:59)</span>
            </div>
        </div>
    </div>
</template>
