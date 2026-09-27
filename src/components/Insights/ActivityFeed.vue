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
				:class="{ undone: item.undoneAt }"
				data-testid="activity-item">
				<span class="activity-icon">
					{{ item.undoneAt ? '↩' : '✓' }}
				</span>
				<span class="activity-text">
					<strong class="activity-actor">{{ item.displayName || t('chorestrike', 'Unknown member') }}</strong>
					{{ item.undoneAt ? t('chorestrike', 'undid a completion of') : t('chorestrike', 'completed') }}
					<strong class="activity-task">{{ item.taskTitle || t('chorestrike', 'Unknown task') }}</strong>
					<span v-if="item.roomName" class="activity-room">
						{{ t('chorestrike', 'in') }} {{ item.roomName }}
					</span>
				</span>
				<span class="activity-meta">
					<span class="activity-time" :title="absoluteDate(item.completedAt)">
						{{ relativeDate(item.completedAt) }}
					</span>
					<!-- WP3: the frozen total effort — never the mutable live
					     definition, never a Coin mint. -->
					<span v-if="item.totalEnergy != null" class="activity-energy" :data-testid="`activity-energy-${item.id}`">
						{{ item.totalEnergy }} ⚡
					</span>
					<!-- WP3 §3.6: authorized older-completion correction
					     (completer or owner, still-valid target) — opens the
					     attribution dialog reusing the participation form. -->
					<button
						v-if="item.canCorrect && !item.undoneAt"
						class="activity-correct"
						:data-testid="`activity-correct-${item.id}`"
						:disabled="busyId === item.id || blockedIds.includes(item.id)"
						@click="$emit('correct', item)">
						{{ t('chorestrike', 'Correct split') }}
					</button>
					<button
						v-if="item.canCorrect && !item.undoneAt"
						class="activity-correct"
						:data-testid="`activity-undo-${item.id}`"
						:data-version="item.version"
						:disabled="busyId === item.id || blockedIds.includes(item.id)"
						@click="$emit('undo', item)">
						{{ t('chorestrike', 'Undo') }}
					</button>
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
		busyId: {
			type: Number,
			default: null,
		},
		blockedIds: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['correct', 'undo'],

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

.activity-correct {
	min-height: 44px;
	padding: 0.3rem 0.75rem;
	background: transparent;
	color: var(--color-text-maxcontrast);
	border: 1px solid var(--color-border);
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.8rem;
	font-weight: 600;
}

.activity-correct:hover:not(:disabled) {
	color: var(--color-main-text);
	border-color: var(--color-main-text);
}

.activity-correct:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.empty-state {
	text-align: center;
	padding: 2rem;
	color: var(--color-text-maxcontrast);
}

@media (max-width: 600px) {
	.activity-item {
		flex-wrap: wrap;
	}

	.activity-text {
		min-width: calc(100% - 44px);
	}

	.activity-meta {
		margin-inline-start: 44px;
		align-items: center;
		flex-direction: row;
		flex-wrap: wrap;
	}
}
</style>
