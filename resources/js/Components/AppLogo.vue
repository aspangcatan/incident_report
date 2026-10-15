<script setup>
defineProps({
    class: { type: String, default: 'h-8 w-8' },
});
</script>

<template>
    <!-- Cebu South Medical Center seal; source: resources/images/csmc_logo.png -->
    <img src="/images/csmc-logo.png" alt="Cebu South Medical Center" :class="[$props.class, 'object-contain']" />
</template>
