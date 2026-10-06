<script setup>
import { computed, nextTick, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SeverityBadge from '@/Components/SeverityBadge.vue';
import { SEVERITIES, findSeverity } from '@/Utils/severities';

const props = defineProps({
    types: { type: Array, required: true },
    categories: { type: Array, required: true },
});

const blank = { name: '', category: '', default_severity: null, is_active: true };
const form = useForm({ ...blank });
const editing = ref(null); // the type being edited, or null when adding
const formOpen = ref(false);

const chosenSeverity = computed(() => findSeverity(form.default_severity));

function showForm() {
    formOpen.value = true;
    nextTick(() => document.getElementById('type_name')?.focus());
}

function openAdd() {
    editing.value = null;
    form.defaults({ ...blank });
    form.reset();
    form.clearErrors();
    showForm();
}

function openEdit(type) {
    editing.value = type;
    form.defaults({
        name: type.name,
        category: type.category,
        default_severity: type.default_severity,
        is_active: type.is_active,
    });
    form.reset();
    form.clearErrors();
    showForm();
}

function closeForm() {
    formOpen.value = false;
    editing.value = null;
}

function save() {
    const options = { preserveScroll: true, onSuccess: closeForm };
    if (editing.value) {
        form.put(`/admin/incident-types/${editing.value.id}`, options);
    } else {
        form.post('/admin/incident-types', options);
    }
}

function destroy(type) {
    if (!window.confirm(`Delete "${type.name}"? This cannot be undone.`)) return;
    router.delete(`/admin/incident-types/${type.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Incident Types" />

    <AuthenticatedLayout>
        <div class="flex flex-wrap items-end justify-between gap-space-md">
            <div class="flex flex-col gap-1">
                <h1 class="font-headline-sm text-headline-sm text-on-surface">Incident Types</h1>
                <p class="font-body-sm text-body-sm text-outline">
                    The list reporters choose from. A type already used by a report can't be deleted — switch it off to hide it from new reports.
                </p>
            </div>
            <button
                v-if="!formOpen"
                type="button"
                class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold"
                @click="openAdd"
            >
                Add incident type
            </button>
        </div>

        <form
            v-if="formOpen"
            class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md max-w-2xl"
            @submit.prevent="save"
        >
            <h2 class="font-title-lg text-title-lg text-primary font-bold">
                {{ editing ? `Edit "${editing.name}"` : 'Add incident type' }}
            </h2>

            <div class="flex flex-col gap-1.5">
                <label for="type_name" class="font-label-md text-label-md text-on-surface font-semibold">Name</label>
                <input id="type_name" v-model="form.name" type="text" maxlength="255" class="w-full p-3 rounded-lg bg-surface-container-low" />
                <span class="font-body-sm text-body-sm text-outline">Shown to reporters in the incident form.</span>
                <span v-if="form.errors.name" class="font-body-sm text-body-sm text-error">{{ form.errors.name }}</span>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="type_category" class="font-label-md text-label-md text-on-surface font-semibold">Category</label>
                <select id="type_category" v-model="form.category" class="w-full p-3 rounded-lg bg-surface-container-low">
                    <option value="" disabled>Choose a category</option>
                    <option v-for="category in categories" :key="category.value" :value="category.value">{{ category.label }}</option>
                </select>
                <span class="font-body-sm text-body-sm text-outline">Groups similar types together.</span>
                <span v-if="form.errors.category" class="font-body-sm text-body-sm text-error">{{ form.errors.category }}</span>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="type_severity" class="font-label-md text-label-md text-on-surface font-semibold">Default severity</label>
                <select id="type_severity" v-model="form.default_severity" class="w-full p-3 rounded-lg bg-surface-container-low">
                    <option :value="null">No default</option>
                    <option v-for="severity in SEVERITIES" :key="severity.value" :value="severity.value">
                        {{ severity.numeral }} – {{ severity.label }}
                    </option>
                </select>
                <span class="font-body-sm text-body-sm text-outline">
                    {{ chosenSeverity ? chosenSeverity.meaning : 'Pre-selected level for this type. Leave as No default if it varies.' }}
                </span>
                <span v-if="form.errors.default_severity" class="font-body-sm text-body-sm text-error">{{ form.errors.default_severity }}</span>
            </div>

            <div class="flex flex-col gap-1.5">
                <label class="flex items-center gap-2 font-label-md text-label-md text-on-surface font-semibold">
                    <input v-model="form.is_active" type="checkbox" />
                    Active
                </label>
                <span class="font-body-sm text-body-sm text-outline">Off hides this type from new reports. Past reports keep it.</span>
                <span v-if="form.errors.is_active" class="font-body-sm text-body-sm text-error">{{ form.errors.is_active }}</span>
            </div>

            <div class="flex gap-space-sm">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60"
                >
                    Save
                </button>
                <button type="button" class="px-4 py-2 rounded-lg bg-surface-container font-label-md text-label-md text-on-surface" @click="closeForm">
                    Cancel
                </button>
            </div>
        </form>

        <div class="rounded-xl bg-surface-container-lowest shadow-sm overflow-x-auto">
            <table class="w-full text-left">
                <thead class="bg-surface-container-low font-label-md text-label-md text-on-surface-variant">
                    <tr>
                        <th class="px-space-md py-3">Name</th>
                        <th class="px-space-md py-3">Category</th>
                        <th class="px-space-md py-3">Default severity</th>
                        <th class="px-space-md py-3">Status</th>
                        <th class="px-space-md py-3">Used by</th>
                        <th class="px-space-md py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="font-body-md text-body-md text-on-surface">
                    <tr v-if="!types.length">
                        <td colspan="6" class="px-space-md py-4 text-outline">No incident types yet.</td>
                    </tr>
                    <tr v-for="type in types" :key="type.id" class="border-t border-outline-variant">
                        <td class="px-space-md py-3 font-semibold">{{ type.name }}</td>
                        <td class="px-space-md py-3">{{ type.category_label }}</td>
                        <td class="px-space-md py-3">
                            <SeverityBadge v-if="type.default_severity" :severity="type.default_severity" />
                            <span v-else class="text-outline">No default</span>
                        </td>
                        <td class="px-space-md py-3">
                            <span
                                :class="type.is_active ? 'bg-emerald-100 text-emerald-900' : 'bg-surface-container text-on-surface-variant'"
                                class="px-2.5 py-0.5 rounded-full font-label-sm text-body-sm font-semibold"
                            >
                                {{ type.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-space-md py-3 whitespace-nowrap">{{ type.usage_count }} {{ type.usage_count === 1 ? 'incident' : 'incidents' }}</td>
                        <td class="px-space-md py-3">
                            <div class="flex items-center justify-end gap-space-sm">
                                <button type="button" class="px-3 py-1.5 rounded-lg bg-surface-container font-label-md text-label-md text-on-surface" @click="openEdit(type)">
                                    Edit
                                </button>
                                <button
                                    v-if="type.can_delete"
                                    type="button"
                                    class="px-3 py-1.5 rounded-lg bg-error text-on-error font-label-md text-label-md"
                                    @click="destroy(type)"
                                >
                                    Delete
                                </button>
                                <span v-else class="font-body-sm text-body-sm text-outline max-w-[14rem]">In use by reports or recurrence reviews — can't be deleted. Switch it off to hide it instead.</span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
