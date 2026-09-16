<script setup>
defineProps({
    form: { type: Object, required: true },
});

function addAction(form) {
    form.actions_taken.push({ description: '', responsible_name: '', performed_at: '', status: 'completed' });
}

function removeAction(form, index) {
    form.actions_taken.splice(index, 1);
}
</script>

<template>
    <div class="flex flex-col gap-space-md">
        <div class="flex items-center justify-between">
            <h2 class="font-title-lg text-title-lg text-primary font-bold">Section 6: Immediate Actions Taken</h2>
            <button type="button" class="font-label-sm text-label-sm font-bold text-primary" @click="addAction(form)">+ Add Action</button>
        </div>

        <div v-if="form.actions_taken.length === 0" class="p-space-md rounded-lg bg-surface-container-low text-center font-body-sm text-body-sm text-outline">
            No immediate actions recorded yet.
        </div>

        <div v-for="(action, index) in form.actions_taken" :key="index" class="p-space-md rounded-lg bg-surface-container-low flex flex-col gap-space-sm">
            <div class="flex justify-between items-center">
                <span class="font-label-sm text-label-sm uppercase text-outline">Action {{ index + 1 }}</span>
                <button type="button" class="text-error font-label-sm text-body-sm" @click="removeAction(form, index)">Remove</button>
            </div>
            <textarea v-model="action.description" placeholder="Intervention taken" rows="2" class="p-2.5 rounded-lg bg-surface-container-lowest" />
            <div class="grid grid-cols-1 md:grid-cols-3 gap-space-sm">
                <input v-model="action.responsible_name" type="text" placeholder="Responsible officer" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                <input v-model="action.performed_at" type="datetime-local" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                <select v-model="action.status" class="p-2.5 rounded-lg bg-surface-container-lowest">
                    <option value="pending">Pending</option>
                    <option value="completed">Completed</option>
                </select>
            </div>
        </div>
    </div>
</template>
