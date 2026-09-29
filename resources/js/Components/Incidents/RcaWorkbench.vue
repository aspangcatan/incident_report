<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import RcaFindingFields from '@/Components/Incidents/RcaFindingFields.vue';
import { formatDate } from '@/Utils/formatDate';
import {
    BARRIER_STATUSES, FISHBONE_CATEGORIES, RCA_TOOLS, ROOT_CAUSE_TYPES, emptyFinding, withoutStrayCauseType,
} from '@/Components/Incidents/rca';

const props = defineProps({
    investigation: { type: Object, required: true },
    can: { type: Object, required: true },
});

const activeTool = ref('simple');
const tool = computed(() => RCA_TOOLS.find((t) => t.value === activeTool.value));

const findingsOf = (value) => (props.investigation.findings ?? []).filter((f) => (f.tool ?? 'simple') === value);
const countOf = (value) => findingsOf(value).length;

const rows = computed(() => {
    const list = findingsOf(activeTool.value);
    if (activeTool.value === 'timeline') {
        return [...list].sort((a, b) => String(a.occurred_at).localeCompare(String(b.occurred_at)));
    }
    return list;
});

const addForm = useForm(emptyFinding('simple'));
const editingId = ref(null);
const editForm = useForm(emptyFinding('simple'));

function switchTool(value) {
    activeTool.value = value;
    editingId.value = null;
    addForm.defaults(emptyFinding(value));
    addForm.reset();
    addForm.clearErrors();
}

function add() {
    addForm.tool = activeTool.value;
    addForm.transform(withoutStrayCauseType).post(`/investigations/${props.investigation.id}/findings`, {
        preserveScroll: true,
        onSuccess: () => addForm.reset(),
    });
}

function startEditing(finding) {
    editingId.value = finding.id;
    editForm.clearErrors();
    Object.assign(editForm, {
        ...emptyFinding(finding.tool),
        question: finding.question ?? '',
        finding: finding.finding,
        group_name: finding.group_name ?? '',
        occurred_at: finding.occurred_at ? finding.occurred_at.slice(0, 16) : '',
        is_flagged: finding.is_flagged,
        is_root_cause: finding.is_root_cause,
        category: finding.category ?? '',
    });
}

function saveEdit(findingId) {
    editForm.transform(withoutStrayCauseType).patch(`/investigations/${props.investigation.id}/findings/${findingId}`, {
        preserveScroll: true,
        onSuccess: () => (editingId.value = null),
    });
}

function remove(findingId) {
    editForm.delete(`/investigations/${props.investigation.id}/findings/${findingId}`, { preserveScroll: true });
}

const barrierClasses = {
    worked: 'bg-emerald-100 text-emerald-900',
    failed: 'bg-error-container text-on-error-container',
    missing: 'bg-amber-100 text-amber-900',
    not_used: 'bg-amber-100 text-amber-900',
};
</script>

<template>
    <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
        <div>
            <h3 class="font-title-lg text-title-lg text-on-surface">Root Cause Analysis</h3>
            <p class="font-body-sm text-body-sm text-outline">Use any of these tools, or just plain findings. Tick "Mark as root cause" on what caused the incident.</p>
        </div>

        <div class="flex flex-wrap gap-2" role="tablist">
            <button
                v-for="option in RCA_TOOLS"
                :key="option.value"
                type="button"
                role="tab"
                :aria-selected="activeTool === option.value"
                class="px-3 py-1.5 rounded-lg font-label-md text-label-md"
                :class="activeTool === option.value ? 'bg-primary text-on-primary font-semibold' : 'bg-surface-container text-on-surface-variant'"
                @click="switchTool(option.value)"
            >
                {{ option.label }}<span v-if="countOf(option.value)" class="ml-1 opacity-80">({{ countOf(option.value) }})</span>
            </button>
        </div>
        <p class="font-body-sm text-body-sm text-outline -mt-2">{{ tool.hint }}</p>

        <div v-if="!rows.length" class="text-center font-body-sm text-body-sm text-outline p-space-md">
            Nothing recorded with this tool yet.
        </div>

        <!-- Fishbone: causes grouped under the six bones -->
        <div v-else-if="activeTool === 'fishbone'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-space-sm">
            <div v-for="(label, bone) in FISHBONE_CATEGORIES" :key="bone" class="p-3 rounded-lg bg-surface-container-low flex flex-col gap-2">
                <span class="font-label-md text-label-md text-primary font-semibold uppercase">{{ label }}</span>
                <p v-if="!rows.some((f) => f.group_name === bone)" class="font-body-sm text-body-sm text-outline">—</p>
                <template v-for="finding in rows.filter((f) => f.group_name === bone)" :key="finding.id">
                    <RcaFindingFields v-if="editingId === finding.id" :form="editForm" tool="fishbone" :id-prefix="'edit_' + finding.id" />
                    <div v-else class="flex flex-col gap-0.5 p-2 rounded" :class="finding.is_root_cause ? 'bg-error-container/30' : ''">
                        <span class="font-body-md text-body-md text-on-surface">{{ finding.finding }}</span>
                        <span v-if="finding.is_root_cause" class="font-label-sm text-body-sm text-error font-bold uppercase">Root cause · {{ ROOT_CAUSE_TYPES[finding.category] ?? finding.category }}</span>
                    </div>
                    <div v-if="can.recordFindings" class="flex gap-3">
                        <template v-if="editingId === finding.id">
                            <button type="button" class="font-label-sm text-body-sm text-primary font-semibold" @click="saveEdit(finding.id)">Save</button>
                            <button type="button" class="font-label-sm text-body-sm" @click="editingId = null">Cancel</button>
                        </template>
                        <template v-else>
                            <button type="button" class="font-label-sm text-body-sm text-primary" @click="startEditing(finding)">Edit</button>
                            <button type="button" class="font-label-sm text-body-sm text-error" @click="remove(finding.id)">Delete</button>
                        </template>
                    </div>
                </template>
            </div>
        </div>

        <!-- Every other tool: one row per entry -->
        <div v-else class="flex flex-col gap-2">
            <div
                v-for="finding in rows"
                :key="finding.id"
                class="flex items-start gap-3 p-3 rounded-lg"
                :class="finding.is_root_cause ? 'bg-error-container/30' : 'bg-surface-container-low'"
            >
                <div
                    v-if="finding.sequence"
                    class="w-8 h-8 rounded-full flex items-center justify-center font-code-tabular text-body-sm font-bold flex-shrink-0"
                    :class="finding.is_root_cause ? 'bg-error text-on-error' : 'bg-primary text-on-primary'"
                >
                    {{ finding.sequence }}
                </div>

                <div v-if="editingId === finding.id" class="flex-1 flex flex-col gap-2">
                    <RcaFindingFields :form="editForm" :tool="activeTool" :id-prefix="'edit_' + finding.id" />
                    <div class="flex gap-2">
                        <button type="button" class="px-3 py-1.5 rounded-lg bg-primary text-on-primary font-label-sm text-body-sm" @click="saveEdit(finding.id)">Save</button>
                        <button type="button" class="px-3 py-1.5 rounded-lg bg-surface-container font-label-sm text-body-sm" @click="editingId = null">Cancel</button>
                    </div>
                </div>

                <div v-else class="flex-1 flex flex-col gap-1">
                    <template v-if="activeTool === 'five_whys'">
                        <span class="font-label-md text-label-md text-primary font-semibold">{{ finding.question }}</span>
                        <span class="font-body-md text-body-md text-on-surface"><span class="font-label-sm text-body-sm text-outline">Because: </span>{{ finding.finding }}</span>
                    </template>
                    <div v-else-if="activeTool === 'process_map'" class="grid grid-cols-1 md:grid-cols-2 gap-2">
                        <div class="flex flex-col"><span class="font-label-sm text-body-sm text-outline">Should happen</span><span class="font-body-md text-body-md text-on-surface">{{ finding.question }}</span></div>
                        <div class="flex flex-col"><span class="font-label-sm text-body-sm text-outline">Actually happened</span><span class="font-body-md text-body-md text-on-surface">{{ finding.finding }}</span></div>
                    </div>
                    <template v-else-if="activeTool === 'timeline'">
                        <span class="font-code-tabular text-body-sm text-outline">{{ formatDate(finding.occurred_at) }}</span>
                        <span class="font-body-md text-body-md text-on-surface">{{ finding.finding }}</span>
                    </template>
                    <template v-else-if="activeTool === 'barrier'">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-label-md text-label-md text-on-surface font-semibold">{{ finding.question }}</span>
                            <span :class="barrierClasses[finding.group_name]" class="px-2 py-0.5 rounded-full font-label-sm text-body-sm font-semibold">{{ BARRIER_STATUSES[finding.group_name] ?? finding.group_name }}</span>
                        </div>
                        <span class="font-body-md text-body-md text-on-surface">{{ finding.finding }}</span>
                    </template>
                    <template v-else>
                        <span v-if="finding.question" class="font-label-sm text-body-sm text-primary font-semibold">{{ finding.question }}</span>
                        <p class="font-body-md text-body-md text-on-surface">{{ finding.finding }}</p>
                    </template>

                    <div class="flex flex-wrap gap-2">
                        <span v-if="finding.is_flagged" class="px-2 py-0.5 rounded-md bg-amber-100 text-amber-900 font-label-sm text-body-sm font-bold w-fit">
                            {{ activeTool === 'process_map' ? 'Deviation' : 'Went wrong here' }}
                        </span>
                        <span v-if="finding.is_root_cause" class="px-2 py-0.5 rounded-md bg-error text-on-error font-label-sm text-body-sm uppercase font-bold w-fit">
                            Root cause · {{ ROOT_CAUSE_TYPES[finding.category] ?? finding.category }}
                        </span>
                    </div>
                </div>

                <div v-if="can.recordFindings && editingId !== finding.id" class="flex flex-col gap-1 flex-shrink-0">
                    <button type="button" class="font-label-sm text-body-sm text-primary" @click="startEditing(finding)">Edit</button>
                    <button type="button" class="font-label-sm text-body-sm text-error" @click="remove(finding.id)">Delete</button>
                </div>
            </div>
        </div>

        <form v-if="can.recordFindings" class="flex flex-col gap-2 p-space-md rounded-lg bg-surface-container-low" @submit.prevent="add">
            <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">Add to {{ tool.label }}</span>
            <RcaFindingFields :form="addForm" :tool="activeTool" id-prefix="add" />
            <button type="submit" :disabled="addForm.processing" class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60 w-fit">
                Add
            </button>
        </form>
    </div>
</template>
