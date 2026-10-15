<script setup>
import InjuryDetailsFields from '@/Components/Incidents/InjuryDetailsFields.vue';

defineProps({
    form: { type: Object, required: true },
    injuryOptions: { type: Object, required: true },
});
</script>

<template>
    <div class="flex flex-col gap-space-lg">
        <InjuryDetailsFields :form="form" :injury-options="injuryOptions" />
    </div>
</template>
