<script setup>
import { ref } from 'vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    reference: { type: String, required: true },
});

const copied = ref(false);

async function copyReference() {
    try {
        await navigator.clipboard.writeText(props.reference);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    } catch {
        // Clipboard blocked (e.g. plain http): the number is still on screen to write down.
    }
}
</script>

<template>
    <Head title="Report Submitted" />

    <PublicLayout>
        <template #actions>
            <Link
                href="/login"
                class="inline-flex items-center gap-2 px-3 sm:px-4 py-2 rounded-lg bg-surface-container-lowest text-primary font-label-md text-label-md font-semibold ring-1 ring-outline-variant hover:bg-surface-container-low"
            >
                <FontAwesomeIcon icon="right-to-bracket" /> <span class="sm:hidden">Sign in</span><span class="hidden sm:inline">Staff sign in</span>
            </Link>
        </template>

        <section class="bg-gradient-to-b from-surface-container-low to-background">
            <div class="max-w-xl mx-auto px-4 sm:px-6 py-12 md:py-20">
                <div class="rounded-2xl bg-surface-container-lowest p-6 sm:p-8 shadow-sm ring-1 ring-outline-variant/40 flex flex-col items-center text-center gap-5">
                    <span class="h-16 w-16 rounded-full bg-secondary-container text-on-secondary-container flex items-center justify-center text-[1.75rem]">
                        <FontAwesomeIcon icon="circle-check" />
                    </span>
                    <div class="flex flex-col gap-1.5">
                        <h1 class="font-headline-lg text-headline-lg-mobile md:text-headline-lg text-primary font-bold">Thank you for your report</h1>
                        <p class="font-body-lg text-body-lg text-on-surface-variant">
                            It has been received by the hospital's Patient Safety/CQI Office.
                        </p>
                    </div>

                    <div class="w-full rounded-xl bg-surface-container-low p-5 flex flex-col items-center gap-2">
                        <span class="font-label-sm text-label-sm uppercase tracking-wider text-outline">Your reference number</span>
                        <span class="font-code-tabular text-headline-md text-primary font-bold tracking-wide">{{ reference }}</span>
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg font-label-md text-label-md text-primary hover:bg-surface-container"
                            @click="copyReference"
                        >
                            <FontAwesomeIcon :icon="copied ? 'circle-check' : 'clipboard-list'" /> {{ copied ? 'Copied' : 'Copy number' }}
                        </button>
                    </div>

                    <p class="font-body-md text-body-md text-on-surface-variant">
                        Keep this number in case you contact the hospital about your report. We may reach out to you using the contact details you gave.
                    </p>

                    <Link
                        href="/report"
                        class="inline-flex items-center gap-2 px-5 py-3 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold shadow-sm hover:bg-primary-container transition-colors"
                    >
                        <FontAwesomeIcon icon="plus" /> Submit another report
                    </Link>
                </div>
            </div>
        </section>
    </PublicLayout>
</template>
