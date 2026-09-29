<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    leaders: { type: Array, required: true },
    departments: { type: Array, required: true },
});

const forms = Object.fromEntries(props.leaders.map((leader) => [leader.id, useForm({ department_ids: [...leader.department_ids] })]));
const filter = ref('');
const shownDepartments = computed(() => {
    const term = filter.value.trim().toLowerCase();
    return term ? props.departments.filter((d) => d.name.toLowerCase().includes(term)) : props.departments;
});

function save(leader) {
    forms[leader.id].put(`/admin/leadership/${leader.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Leadership Coverage" />

    <AuthenticatedLayout>
        <div class="flex flex-col gap-1">
            <h1 class="font-headline-sm text-headline-sm text-on-surface">Leadership Coverage</h1>
            <p class="font-body-sm text-body-sm text-outline">
                Choose the departments each Medical/Nursing/Ancillary Leader oversees. They can read incidents from
                those departments and get alerts when one is High or Sentinel.
            </p>
        </div>

        <div v-if="!leaders.length" class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm font-body-md text-body-md text-outline">
            No users have the Leadership role yet. Give a user the <code>leadership</code> level (syscode IR) in tdh_user first.
        </div>

        <template v-else>
            <div class="flex flex-col gap-1.5 max-w-md">
                <label for="department_filter" class="font-label-md text-label-md text-on-surface font-semibold">Filter departments</label>
                <input id="department_filter" v-model="filter" type="search" placeholder="Type part of a department name" class="w-full p-3 rounded-lg bg-surface-container-low" />
            </div>

            <form
                v-for="leader in leaders"
                :key="leader.id"
                class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md"
                @submit.prevent="save(leader)"
            >
                <div class="flex items-center justify-between gap-2">
                    <h2 class="font-title-lg text-title-lg text-primary font-bold">{{ leader.name }}</h2>
                    <span class="font-body-sm text-body-sm text-outline">{{ forms[leader.id].department_ids.length }} selected</span>
                </div>

                <fieldset class="flex flex-col gap-1.5">
                    <legend class="font-label-md text-label-md text-on-surface font-semibold">Departments overseen</legend>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-x-space-md gap-y-2 max-h-80 overflow-y-auto mt-1">
                        <label v-for="department in shownDepartments" :key="department.id" class="flex items-start gap-2 font-body-md text-body-md text-on-surface">
                            <input v-model="forms[leader.id].department_ids" type="checkbox" :value="department.id" class="mt-1" />
                            <span>{{ department.name }}</span>
                        </label>
                    </div>
                    <span v-if="forms[leader.id].errors.department_ids" class="font-body-sm text-body-sm text-error">{{ forms[leader.id].errors.department_ids }}</span>
                </fieldset>

                <button
                    type="submit"
                    :disabled="forms[leader.id].processing"
                    class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60 w-fit"
                >
                    Save
                </button>
            </form>
        </template>
    </AuthenticatedLayout>
</template>
