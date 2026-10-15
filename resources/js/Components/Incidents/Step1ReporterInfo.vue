<script setup>
import { usePage } from '@inertiajs/vue3';

defineProps({
    form: { type: Object, required: true },
});

const user = usePage().props.auth.user;
</script>

<template>
    <div class="flex flex-col gap-space-md">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-space-md">
            <div class="flex flex-col gap-1">
                <span class="font-label-md text-label-md text-on-surface-variant">Reported By</span>
                <div class="p-2.5 rounded-lg bg-surface-container-low text-on-surface font-title-sm text-title-sm font-semibold">
                    {{ user.name }}
                </div>
            </div>
            <div class="flex flex-col gap-1">
                <span class="font-label-md text-label-md text-on-surface-variant">Designation</span>
                <div class="p-2.5 rounded-lg bg-surface-container-low text-on-surface font-body-md text-body-md">
                    {{ user.designation ?? 'Not set' }}
                </div>
            </div>
        </div>

        <label class="choice-tile p-4">
            <input v-model="form.legal_attestation" type="checkbox" />
            <span class="font-body-md text-body-md font-normal text-on-surface">
                <span class="font-semibold text-primary">Electronic Acknowledgment:</span>
                I certify that the observations and statements in this report are true, factual, and based on
                firsthand verification. This attestation is required before final submission.
            </span>
        </label>
        <span v-if="form.errors.legal_attestation" class="form-error">
            {{ form.errors.legal_attestation }}
        </span>
    </div>
</template>
