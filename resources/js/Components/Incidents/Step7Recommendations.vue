<script setup>
defineProps({
    form: { type: Object, required: true },
});
</script>

<template>
    <div class="flex flex-col gap-space-md">
        <h2 class="font-title-lg text-title-lg text-primary font-bold">Section 7: Recommendations & Preventive Measures</h2>
        <div class="flex flex-col gap-1.5">
            <label for="recommendations" class="font-label-md text-label-md text-on-surface font-semibold">
                Recommended corrective / preventive measures
            </label>
            <textarea id="recommendations" v-model="form.recommendations" rows="6" class="w-full p-3 rounded-lg bg-surface-container-low" placeholder="What should change to prevent this from happening again?" />
        </div>
    </div>
</template>
