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
 * the badge without re-declaring the mapping.
 */
export const MEDAL_LABELS = {
	team_anchor: 'Team Anchor',
	flow_keeper: 'Flow Keeper',
	room_guardian: 'Room Guardian',
	team_player: 'Team Player',
	streak_keeper: 'Streak Keeper',
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
			team_anchor: '🛟',
			flow_keeper: '🌊',
			room_guardian: '🛡️',
			team_player: '🤝',
			streak_keeper: '🔥',
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
.medal-team_anchor {
	background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
	color: white;
}

.medal-flow_keeper {
	background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
	color: white;
}

.medal-room_guardian {
	background: linear-gradient(135deg, #fc4a1a 0%, #f7b733 100%);
	color: white;
}

.medal-team_player {
	background: linear-gradient(135deg, #2193b0 0%, #6dd5ed 100%);
	color: white;
}

.medal-streak_keeper {
	background: linear-gradient(135deg, #eb3349 0%, #f45c43 100%);
	color: white;
}
</style>
