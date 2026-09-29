<script setup>
import InjuryDetailsFields from '@/Components/Incidents/InjuryDetailsFields.vue';

defineProps({
    form: { type: Object, required: true },
    injuryOptions: { type: Object, required: true },
});
</script>

<template>
    <div class="flex flex-col gap-space-lg">
        <h2 class="font-title-lg text-title-lg text-primary font-bold">Section 3: Accident / Injury Details</h2>
        <InjuryDetailsFields :form="form" :injury-options="injuryOptions" />
    </div>
</template>
