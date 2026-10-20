<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<span
		v-if="label"
		class="title-badge"
		:class="[tierClass, { compact }]"
		data-testid="title-badge"
		:title="historyTooltip">
		<span class="title-mark">★</span>
		<span class="title-label">{{ label }}</span>
	</span>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'
import { TIER_STYLES, titleLabel } from '../../constants/titles.js'

/**
 * Renders a user's current role title with the tier colour from the design
 * tokens (bronze/silver/gold). Shows the title history as a native tooltip
 * when `unlocked` tiers are provided.
 */
export default {
	name: 'TitleBadge',
	props: {
		/**
		 * Title descriptor from the API ({tierIndex, labelKey, pack, ...}).
		 */
		title: {
			type: Object,
			default: null,
		},

		/**
		 * Unlocked tier descriptors for the history tooltip.
		 */
		unlocked: {
			type: Array,
			default: () => [],
		},

		/**
		 * Compact variant for dense rows (leaderboard).
		 */
		compact: {
			type: Boolean,
			default: false,
		},
	},

	setup(props) {
		const label = computed(() => titleLabel(t, props.title))

		const tierClass = computed(() => {
			const idx = props.title?.tierIndex
			if (typeof idx !== 'number' || !TIER_STYLES[idx]) {
				return null
			}
			return TIER_STYLES[idx].class
		})

		const historyTooltip = computed(() => {
			if (!props.unlocked || props.unlocked.length === 0) {
				return null
			}
			const labels = props.unlocked
				.map((tier) => titleLabel(t, tier))
				.filter(Boolean)
			if (labels.length === 0) {
				return null
			}
			return t('chorestrike', 'Unlocked titles: {titles}', { titles: labels.join(', ') })
		})

		return { label, tierClass, historyTooltip }
	},
}
</script>

<style scoped>
.title-badge {
	display: inline-flex;
	align-items: center;
	gap: 0.3rem;
	padding: 0.25rem 0.6rem;
	border-radius: var(--cs-radius-pill, 999px);
	font-weight: 600;
	font-size: 0.8rem;
	border: 1px solid transparent;
	background: var(--color-background-dark);
	color: var(--color-main-text);
	white-space: nowrap;
}

.title-badge.compact {
	font-size: 0.72rem;
	padding: 0.1rem 0.4rem;
}

.title-mark {
	line-height: 1;
	font-size: 0.85em;
}

.tier-bronze {
	background: var(--cs-tier-bronze-bg);
	border-color: var(--cs-tier-bronze-border);
	color: var(--cs-tier-bronze);
}

.tier-silver {
	background: var(--cs-tier-silver-bg);
	border-color: var(--cs-tier-silver-border);
	color: var(--cs-tier-silver);
}

.tier-gold {
	background: var(--cs-tier-gold-bg);
	border-color: var(--cs-tier-gold-border);
	color: var(--cs-tier-gold);
}
</style>
