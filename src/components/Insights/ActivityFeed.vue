<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="activity-feed" data-testid="activity-feed">
		<ul v-if="items.length > 0" class="activity-list">
			<li
				v-for="item in items"
				:key="item.id"
				class="activity-item"
				data-testid="activity-item">
				<span class="activity-icon" :class="{ combo: item.wasCombo }">
					{{ item.wasCombo ? '🔥' : '✓' }}
				</span>
				<span class="activity-text">
					<strong class="activity-actor">{{ item.displayName || t('chorestrike', 'Unknown member') }}</strong>
					{{ t('chorestrike', 'completed') }}
					<strong class="activity-task">{{ item.taskTitle || t('chorestrike', 'Unknown task') }}</strong>
					<span v-if="item.roomName" class="activity-room">
						{{ t('chorestrike', 'in') }} {{ item.roomName }}
					</span>
				</span>
				<span class="activity-meta">
					<span class="activity-time" :title="absoluteDate(item.completedAt)">
						{{ relativeDate(item.completedAt) }}
					</span>
					<span v-if="item.coinsEarned" class="activity-coins">+{{ item.coinsEarned }} 🪙</span>
				</span>
			</li>
		</ul>

		<div v-else class="empty-state" data-testid="activity-empty">
			<p>{{ t('chorestrike', 'No activity in this period yet.') }}</p>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'ActivityFeed',

	props: {
		items: {
			type: Array,
			required: true,
		},
	},

	methods: {
		t,

		relativeDate(isoDate) {
			if (!isoDate) return ''
			const date = new Date(isoDate)
			const diffMs = Date.now() - date.getTime()
			const diffMinutes = Math.floor(diffMs / 60000)

			if (diffMinutes < 1) return t('chorestrike', 'just now')
			if (diffMinutes < 60) return t('chorestrike', '{minutes} min ago', { minutes: diffMinutes })
			if (diffMinutes < 24 * 60) return t('chorestrike', '{hours} h ago', { hours: Math.floor(diffMinutes / 60) })
			if (diffMinutes < 7 * 24 * 60) return t('chorestrike', '{days} d ago', { days: Math.floor(diffMinutes / (24 * 60)) })
			return date.toLocaleDateString()
		},

		absoluteDate(isoDate) {
			if (!isoDate) return ''
			return new Date(isoDate).toLocaleString()
		},
	},
}
</script>

<style scoped>
.activity-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.activity-item {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	padding: 0.75rem 0.5rem;
	border-bottom: 1px solid var(--color-border);
}

.activity-item:last-child {
	border-bottom: none;
}

.activity-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	border-radius: 50%;
	background: var(--color-success-hover, #d5f4e6);
	color: var(--color-success, #27ae60);
	flex-shrink: 0;
}

.activity-icon.combo {
	background: var(--color-warning-hover, #fef9e7);
	color: var(--color-warning, #f39c12);
}

.activity-text {
	flex: 1;
	color: var(--color-main-text);
}

.activity-room {
	color: var(--color-text-maxcontrast);
}

.activity-meta {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 0.15rem;
	flex-shrink: 0;
}

.activity-time {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.activity-coins {
	font-size: 0.8rem;
	font-weight: 600;
	color: var(--color-warning, #f39c12);
}

.empty-state {
	text-align: center;
	padding: 2rem;
	color: var(--color-text-maxcontrast);
}
</style>
