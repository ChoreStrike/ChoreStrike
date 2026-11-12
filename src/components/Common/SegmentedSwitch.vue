<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="segmented-switch"
		role="group"
		:aria-label="label"
		:style="{ '--segments': options.length }">
		<button v-for="option in options"
			:key="option.value"
			type="button"
			:aria-pressed="modelValue === option.value ? 'true' : 'false'"
			:data-testid="`${testidPrefix}-${option.value}`"
			@click="$emit('update:modelValue', option.value)">
			{{ option.label }}
		</button>
	</div>
</template>

<script>
/**
 * A row of mutually exclusive choices shown as one pill (the grouping of the
 * task list, the two halves of Settings). Stateless; the owner holds the
 * value.
 */
export default {
	name: 'SegmentedSwitch',

	props: {
		modelValue: {
			type: String,
			required: true,
		},

		/** `[{ value, label }]` in display order. */
		options: {
			type: Array,
			required: true,
		},

		/** Accessible name of the group. */
		label: {
			type: String,
			required: true,
		},

		/** Each button's test id is `<prefix>-<value>`. */
		testidPrefix: {
			type: String,
			required: true,
		},
	},

	emits: ['update:modelValue'],
}
</script>

<style scoped>
.segmented-switch {
	display: grid;
	grid-template-columns: repeat(var(--segments), minmax(0, 1fr));
	max-width: 32rem;
	margin-block-end: 1rem;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-pill);
}

.segmented-switch button {
	min-height: 44px;
	margin: 0;
	border: none;
	border-radius: var(--border-radius-pill);
	background: transparent;
	color: var(--color-main-text);
	font-weight: normal;
	cursor: pointer;
}

.segmented-switch button[aria-pressed='true'] {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: 600;
}

.segmented-switch button:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}
</style>
