<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<Icon name="ChevronRight"
		:size="16"
		class="expand-chevron"
		:class="{ 'expand-chevron--open': open }" />
</template>

<script setup>
import Icon from './Icon.vue'

/**
 * The one expand mark of the app (FEAT-UX-049): a right chevron that turns
 * 90° when open. The surrounding button keeps its label and `aria-expanded`.
 * Reorder controls use arrows, so the two never look alike.
 */
defineProps({
	open: { type: Boolean, default: false },
})
</script>

<style scoped>
.expand-chevron {
	display: inline-flex;
	transition: transform var(--cs-transition-fast);
}

.expand-chevron--open {
	transform: rotate(90deg);
}
</style>
