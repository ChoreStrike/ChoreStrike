<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="calendar-controls">
		<div class="calendar-mode-toggle" data-testid="calendar-mode-toggle">
			<button
				class="toggle-btn"
				:class="{ active: mode === 'week' }"
				data-testid="calendar-mode-week"
				@click="$emit('update:mode', 'week')">
				{{ t('chorestrike', 'Week') }}
			</button>
			<button
				class="toggle-btn"
				:class="{ active: mode === 'month' }"
				data-testid="calendar-mode-month"
				@click="$emit('update:mode', 'month')">
				{{ t('chorestrike', 'Month') }}
			</button>
		</div>
		<div class="calendar-nav">
			<button
				data-testid="calendar-prev"
				:aria-label="t('chorestrike', 'Previous')"
				@click="$emit('navigate', -1)">
				&lt;
			</button>
			<span data-testid="calendar-range-label" class="range-label">{{ rangeLabel }}</span>
			<button
				data-testid="calendar-next"
				:aria-label="t('chorestrike', 'Next')"
				@click="$emit('navigate', 1)">
				&gt;
			</button>
		</div>
		<!-- The weekly plan left the navigation and is reached from
		     here until the live suggestion replaces it (D-090-14). -->
		<router-link :to="{ name: 'scheduling' }" class="plan-link" data-testid="calendar-open-plan">
			{{ t('chorestrike', 'Weekly plan') }}
		</router-link>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

/**
 * The controls in the calendar header: week or month, the range with its
 * arrows, and the way to the weekly plan (CS-PLAN-090 D-090-14). Stateless;
 * the view owns the mode and the range.
 */
export default {
	name: 'CalendarControls',

	props: {
		/** 'week' or 'month'. */
		mode: {
			type: String,
			required: true,
		},

		/** The shown range, already formatted. */
		rangeLabel: {
			type: String,
			required: true,
		},
	},

	emits: ['update:mode', 'navigate'],

	methods: { t },
}
</script>

<style scoped>
.calendar-controls {
	display: flex;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
}

.plan-link {
	display: inline-flex;
	align-items: center;
	min-height: 44px;
	padding: 0 0.5rem;
	color: var(--color-main-text);
	font-weight: 600;
}

.calendar-mode-toggle {
	display: flex;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	overflow: hidden;
}

.toggle-btn {
	padding: 6px 14px;
	min-height: 44px;
	border: none;
	background: var(--color-main-background);
	cursor: pointer;
}

.toggle-btn.active {
	background: var(--color-primary);
	color: var(--color-primary-text);
}

.calendar-nav {
	display: flex;
	align-items: center;
	gap: 8px;
}

.calendar-nav button {
	padding: 4px 10px;
	min-width: 44px;
	min-height: 44px;
	cursor: pointer;
}

.range-label {
	font-weight: 600;
	min-width: 180px;
	text-align: center;
}
</style>
