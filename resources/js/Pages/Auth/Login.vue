<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppLogo from '@/Components/AppLogo.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';

// Photos: Unsplash License (free to use, no attribution required).
// Hero: Luis Melendez, unsplash.com/photos/Pd4lRfKo16U · Modal: JESHOOTS.COM, unsplash.com/photos/l0j0DHVWcIE
const HERO_IMAGE = '/images/landing/hero-team-corridor.jpg';
const LOGIN_IMAGE = '/images/landing/login-nurse.jpg';

defineProps({
    canResetPassword: { type: Boolean, default: false },
});

const page = usePage();

const form = useForm({
    username: '',
    password: '',
});

// Opens on "Sign in"; reopens by itself when the last attempt failed.
const showLogin = ref(Boolean(page.props.flash?.error));
const usernameInput = ref(null);

watch(() => form.hasErrors, (hasErrors) => {
    if (hasErrors) showLogin.value = true;
});

watch(showLogin, async (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
    if (open) {
        await nextTick();
        usernameInput.value?.focus();
    }
}, { immediate: true });

onBeforeUnmount(() => {
    document.body.style.overflow = '';
});

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}

const features = [
    { icon: 'pen-to-square', title: 'Report in minutes', text: 'Staff, patients and visitors file an incident from any device, with injury details, witnesses and attachments.' },
    { icon: 'triangle-exclamation', title: 'Five-level risk triage', text: 'The department classifies the risk, the CQI Office confirms it, and the right people are alerted at once.' },
    { icon: 'magnifying-glass', title: 'Investigation & CAPA', text: 'Root cause analysis, corrective and preventive actions, owners and due dates, verified by the department.' },
    { icon: 'lightbulb', title: 'Learn and prevent', text: 'Effectiveness checks, lessons learned and recurrence reviews turn every incident into safer care.' },
];

const steps = ['Report', 'Assess', 'Triage', 'Investigate', 'Corrective action', 'Verify & close'];
</script>

<template>
    <Head title="Sign in" />

    <PublicLayout>
        <template #actions>
            <Link href="/report" class="hidden sm:inline-flex px-3 py-2 rounded-lg font-label-md text-label-md text-on-surface-variant hover:text-primary hover:bg-surface-container">
                Report as a visitor
            </Link>
            <button
                type="button"
                class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold shadow-sm hover:bg-primary-container transition-colors"
                @click="showLogin = true"
            >
                <FontAwesomeIcon icon="right-to-bracket" /> Sign in
            </button>
        </template>

        <!-- Hero -->
        <section class="relative overflow-hidden">
            <div class="absolute inset-0 -z-10 bg-gradient-to-b from-surface-container-low to-background" />
            <div class="max-w-6xl mx-auto px-4 sm:px-6 py-12 md:py-20 grid md:grid-cols-2 gap-10 md:gap-12 items-center">
                <div class="flex flex-col gap-5">
                    <span class="inline-flex w-fit items-center gap-2 px-3 py-1 rounded-full bg-secondary-container text-on-secondary-container font-label-sm text-label-sm uppercase">
                        <FontAwesomeIcon icon="shield-halved" /> Patient Safety · CQI
                    </span>
                    <h1 class="font-headline-xl text-headline-xl-mobile md:text-[3rem] md:leading-[3.5rem] text-primary font-bold tracking-tight">
                        Every incident reported.
                        <span class="text-secondary">Every lesson learned.</span>
                    </h1>
                    <p class="font-body-lg text-body-lg text-on-surface-variant max-w-xl">
                        The hospital's electronic incident reporting and management system: from the first report through risk triage,
                        investigation and corrective action, to verified closure and shared lessons.
                    </p>
                    <div class="flex flex-wrap gap-3 pt-1">
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 px-5 py-3 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold shadow-sm hover:bg-primary-container transition-colors"
                            @click="showLogin = true"
                        >
                            Staff sign in <FontAwesomeIcon icon="arrow-right" />
                        </button>
                        <Link
                            href="/report"
                            class="inline-flex items-center gap-2 px-5 py-3 rounded-lg bg-surface-container-lowest text-primary font-label-md text-label-md font-semibold shadow-sm ring-1 ring-outline-variant hover:bg-surface-container-low"
                        >
                            Report as a patient or visitor
                        </Link>
                    </div>
                </div>

                <div class="relative">
                    <img
                        :src="HERO_IMAGE"
                        alt="A team of nurses and doctors in scrubs walking down a hospital corridor"
                        class="w-full aspect-[4/3] object-cover rounded-2xl shadow-xl ring-1 ring-black/5"
                    />
                    <!-- Illustrative cards, not live data. -->
                    <div aria-hidden="true" class="absolute -left-3 sm:-left-6 bottom-6 bg-surface-container-lowest rounded-xl shadow-lg ring-1 ring-black/5 p-3 flex items-center gap-3 max-w-[15rem]">
                        <span class="h-9 w-9 shrink-0 rounded-lg bg-error-container text-error flex items-center justify-center"><FontAwesomeIcon icon="bell" /></span>
                        <span class="flex flex-col min-w-0">
                            <span class="font-label-md text-label-md font-semibold text-on-surface">Severity alert sent</span>
                            <span class="font-body-sm text-body-sm text-outline truncate">Level III · High · Triage</span>
                        </span>
                    </div>
                    <div aria-hidden="true" class="absolute -right-2 sm:-right-5 top-6 bg-surface-container-lowest rounded-xl shadow-lg ring-1 ring-black/5 p-3 flex items-center gap-3 max-w-[14rem]">
                        <span class="h-9 w-9 shrink-0 rounded-lg bg-secondary-container text-on-secondary-container flex items-center justify-center"><FontAwesomeIcon icon="circle-check" /></span>
                        <span class="flex flex-col min-w-0">
                            <span class="font-label-md text-label-md font-semibold text-on-surface">CAPA verified</span>
                            <span class="font-body-sm text-body-sm text-outline truncate">Ready for closure</span>
                        </span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Workflow -->
        <section class="max-w-6xl mx-auto px-4 sm:px-6 pb-4">
            <ol class="flex flex-wrap justify-center gap-2 md:gap-3">
                <li v-for="(step, index) in steps" :key="step" class="flex items-center gap-2 md:gap-3">
                    <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-surface-container-lowest ring-1 ring-outline-variant/60 font-label-md text-label-md text-on-surface">
                        <span class="h-5 w-5 rounded-full bg-primary text-on-primary text-[0.6875rem] font-bold flex items-center justify-center">{{ index + 1 }}</span>
                        {{ step }}
                    </span>
                    <FontAwesomeIcon v-if="index < steps.length - 1" icon="chevron-right" class="text-outline-variant hidden sm:inline" />
                </li>
            </ol>
        </section>

        <!-- Features -->
        <section class="max-w-6xl mx-auto px-4 sm:px-6 py-12 md:py-16 flex flex-col gap-8">
            <div class="text-center flex flex-col gap-2 max-w-2xl mx-auto">
                <h2 class="font-headline-lg text-headline-lg-mobile md:text-headline-lg text-primary font-bold">One system for the whole safety cycle</h2>
                <p class="font-body-lg text-body-lg text-on-surface-variant">Built around the hospital's own roles, risk levels and time limits, so nothing waits unnoticed.</p>
            </div>
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <article v-for="feature in features" :key="feature.title" class="rounded-xl bg-surface-container-lowest p-5 shadow-sm ring-1 ring-outline-variant/40 flex flex-col gap-3">
                    <span class="h-10 w-10 rounded-lg bg-primary-fixed text-primary flex items-center justify-center"><FontAwesomeIcon :icon="feature.icon" /></span>
                    <h3 class="font-title-lg text-title-lg text-on-surface font-semibold">{{ feature.title }}</h3>
                    <p class="font-body-md text-body-md text-on-surface-variant">{{ feature.text }}</p>
                </article>
            </div>
        </section>

        <!-- Sign-in modal -->
        <template #overlay>
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                leave-active-class="transition duration-150 ease-in"
                leave-to-class="opacity-0"
            >
                <div v-if="showLogin" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-on-background/60 backdrop-blur-sm">
                    <div
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="login-title"
                        class="relative w-full max-w-3xl max-h-[calc(100vh-2rem)] overflow-y-auto bg-surface-container-lowest rounded-2xl shadow-2xl grid md:grid-cols-2"
                    >
                        <div class="relative hidden md:flex flex-col justify-end p-8 text-on-primary min-h-[28rem]">
                            <img :src="LOGIN_IMAGE" alt="" class="absolute inset-0 h-full w-full object-cover" />
                            <div class="absolute inset-0 bg-gradient-to-t from-primary via-primary/85 to-primary-container/40" />
                            <div class="relative flex flex-col gap-3">
                                <span class="inline-flex w-fit items-center gap-2 px-2.5 py-1 rounded-full bg-white/15 font-label-sm text-label-sm uppercase">
                                    <FontAwesomeIcon icon="shield-halved" /> CSMC Patient Safety
                                </span>
                                <h2 class="font-headline-md text-headline-md font-bold">Safer care starts with a report.</h2>
                                <p class="font-body-md text-body-md text-white/85">
                                    Your reports are reviewed by the Patient Safety/CQI Office and handled confidentially, to fix systems, not to blame people.
                                </p>
                            </div>
                        </div>
    
                        <div class="relative p-6 sm:p-8 flex flex-col gap-5">
                            <button
                                type="button"
                                aria-label="Close sign in"
                                class="absolute top-3 right-3 h-9 w-9 rounded-full text-outline hover:bg-surface-container hover:text-on-surface flex items-center justify-center"
                                @click="showLogin = false"
                            >
                                <FontAwesomeIcon icon="xmark" />
                            </button>
    
                            <div class="flex flex-col gap-3">
                                <AppLogo class="h-14 w-14" />
                                <div>
                                    <h2 id="login-title" class="font-headline-sm text-headline-sm text-on-surface font-bold">Sign in</h2>
                                    <p class="font-body-sm text-body-sm text-outline">Use your hospital username and password.</p>
                                </div>
                            </div>
    
                            <p v-if="page.props.flash?.error" class="rounded-lg bg-error-container text-on-error-container p-3 font-body-sm text-body-sm" role="alert">
                                {{ page.props.flash.error }}
                            </p>
    
                            <form class="flex flex-col gap-4" @submit.prevent="submit">
                                <div class="flex flex-col gap-1.5">
                                    <label for="username" class="font-label-md text-label-md text-on-surface font-semibold">Username</label>
                                    <input
                                        id="username"
                                        ref="usernameInput"
                                        v-model="form.username"
                                        type="text"
                                        autocomplete="username"
                                        placeholder="Enter your username"
                                        class="w-full p-3 rounded-lg bg-surface-container-low text-on-surface font-body-md text-body-md ring-1 ring-outline-variant/60 focus:outline-none focus:bg-surface-container-lowest focus:ring-2 focus:ring-primary"
                                    />
                                    <span v-if="form.errors.username" class="font-body-sm text-body-sm text-error">{{ form.errors.username }}</span>
                                </div>
    
                                <div class="flex flex-col gap-1.5">
                                    <label for="password" class="font-label-md text-label-md text-on-surface font-semibold">Password</label>
                                    <input
                                        id="password"
                                        v-model="form.password"
                                        type="password"
                                        autocomplete="current-password"
                                        placeholder="Enter your password"
                                        class="w-full p-3 rounded-lg bg-surface-container-low text-on-surface font-body-md text-body-md ring-1 ring-outline-variant/60 focus:outline-none focus:bg-surface-container-lowest focus:ring-2 focus:ring-primary"
                                    />
                                    <span v-if="form.errors.password" class="font-body-sm text-body-sm text-error">{{ form.errors.password }}</span>
                                </div>
    
                                <button
                                    type="submit"
                                    :disabled="form.processing"
                                    class="mt-1 w-full inline-flex items-center justify-center gap-2 py-3 rounded-lg bg-primary text-on-primary hover:bg-primary-container font-label-md text-label-md font-semibold transition-colors shadow-sm disabled:opacity-60"
                                >
                                    <FontAwesomeIcon icon="right-to-bracket" /> {{ form.processing ? 'Signing in…' : 'Sign in' }}
                                </button>
                            </form>
    
                            <p class="font-body-sm text-body-sm text-outline text-center">
                                Not hospital staff?
                                <Link href="/report" class="text-primary font-semibold">Report an incident</Link>
                            </p>
                        </div>
                    </div>
                </div>
            </Transition>
        </template>
    </PublicLayout>
</template>
