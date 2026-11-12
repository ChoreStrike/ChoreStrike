<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<section class="settings-category" :class="{ open, danger }" :data-testid="testid">
		<h2 class="category-heading">
			<button type="button"
				class="category-toggle"
				:aria-expanded="open ? 'true' : 'false'"
				:aria-controls="`settings-panel-${id}`"
				:data-testid="`settings-category-${id}`"
				@click="$emit('toggle', id)">
				<ExpandChevron :open="open" />
				<span>{{ title }}</span>
			</button>
		</h2>
		<!-- A category mounts when it opens, so it reads its data only then. -->
		<div v-if="open" :id="`settings-panel-${id}`" class="category-panel">
			<slot />
		</div>
	</section>
</template>

<script>
import ExpandChevron from '../Common/ExpandChevron.vue'

/**
 * One collapsible category of the Settings page (FEAT-UX-054, CS-PLAN-090
 * WP-D). Stateless: the view owns which categories are open.
 */
export default {
	name: 'SettingsCategory',

	components: { ExpandChevron },

	props: {
		/** Stable key, also the `?category=` value of a link. */
		id: {
			type: String,
			required: true,
		},

		title: {
			type: String,
			required: true,
		},

		open: {
			type: Boolean,
			default: false,
		},

		/** Test id of the section, kept from the page's former sections. */
		testid: {
			type: String,
			default: null,
		},

		/** Marks the danger zone. */
		danger: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['toggle'],
}
</script>

<style scoped>
.settings-category {
	border-block-end: 1px solid var(--color-border);
}

.category-heading {
	margin: 0;
	font-size: 1rem;
}

.category-toggle {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	width: 100%;
	min-height: 52px;
	margin: 0;
	padding: 0.25rem 0.5rem;
	border: none;
	border-radius: 0;
	background: transparent;
	color: var(--color-main-text);
	font-size: 1rem;
	font-weight: 600;
	text-align: start;
	cursor: pointer;
}

.category-toggle:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: -2px;
}

.settings-category.open > .category-heading .category-toggle {
	background: var(--color-primary-element-light);
}

.settings-category.danger .category-toggle {
	color: var(--color-error-text);
}

.category-panel {
	padding: 1rem 0 1.5rem;
}
</style>
