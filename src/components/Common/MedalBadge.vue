<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<span class="medal-badge" :class="[`medal-${type}`, `medal-${size}`]" :title="label">
		<span class="medal-icon">{{ icon }}</span>
		<span v-if="showLabel" class="medal-label">{{ label }}</span>
	</span>
</template>

<script>
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'

/**
 * Medal type → translation key (source string). Exported so surfaces that
 * only need the label (e.g. the Leaderboard medal cards) stay in sync with
 * the badge without re-declaring the mapping. The retired activity/combo
 * medal types dropped with the WP4 label cutover (CS-PLAN-070) — only the
 * retained rhythm tiers and the weekly milestone exist.
 */
export const MEDAL_LABELS = {
	rhythm_3: 'Rhythm 3',
	rhythm_6: 'Rhythm 6',
	rhythm_12: 'Rhythm 12',
	weekly_milestone: 'Weekly Milestone',
}

/**
 * Medal badge component for displaying a single medal
 */
export default {
	name: 'MedalBadge',
	props: {
		/**
		 * Medal type identifier
		 */
		type: {
			type: String,
			required: true,
			validator: (value) => Object.keys(MEDAL_LABELS).includes(value),
		},
		/**
		 * Display size variant
		 */
		size: {
			type: String,
			default: 'medium',
			validator: (value) => ['small', 'medium', 'large'].includes(value),
		},
		/**
		 * Whether to show the label text
		 */
		showLabel: {
			type: Boolean,
			default: false,
		},
	},
	setup(props) {
		const icons = {
			rhythm_3: '🔥',
			rhythm_6: '🔥',
			rhythm_12: '🔥',
			weekly_milestone: '🏅',
		}

		const label = computed(() => {
			const key = MEDAL_LABELS[props.type]
			return key ? t('chorestrike', key) : props.type
		})
		const icon = computed(() => icons[props.type] || '🏅')

		return {
			label,
			icon,
		}
	},
}
</script>

<style scoped>
.medal-badge {
	display: inline-flex;
	align-items: center;
	gap: 0.25rem;
	padding: 0.25rem 0.5rem;
	border-radius: 12px;
	font-weight: 600;
	transition: transform 0.15s ease;
}

.medal-badge:hover {
	transform: scale(1.05);
}

/* Size variants */
.medal-badge.small {
	font-size: 0.75rem;
	padding: 0.15rem 0.35rem;
}

.medal-badge.medium {
	font-size: 0.85rem;
}

.medal-badge.large {
	font-size: 1rem;
	padding: 0.35rem 0.75rem;
}

.medal-icon {
	line-height: 1;
}

.medal-label {
	white-space: nowrap;
}

/* Medal type colors */
.medal-rhythm_3,
.medal-rhythm_6,
.medal-rhythm_12 {
	background: linear-gradient(135deg, #ad4a42 0%, #e18d56 100%);
	color: white;
}

.medal-weekly_milestone {
	background: linear-gradient(135deg, #376989 0%, #6c9f9d 100%);
	color: white;
}
</style>
