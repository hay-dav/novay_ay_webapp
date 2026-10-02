<script setup>
import { computed } from 'vue';
import { splitLinkedText } from '@/utils/linkedText';

const props = defineProps({
    text: { type: String, default: '' },
    as: { type: String, default: 'span' },
});
const parts = computed(() => splitLinkedText(props.text));
</script>

<template>
  <component :is="as">
    <template v-for="(part, index) in parts" :key="index"><a v-if="part.href" :href="part.href" class="content-text-link" target="_blank" rel="noopener noreferrer">{{ part.text }}</a><template v-else>{{ part.text }}</template></template>
  </component>
</template>
