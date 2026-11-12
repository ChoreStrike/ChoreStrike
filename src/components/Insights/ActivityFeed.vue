<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="activity-feed" data-testid="activity-feed">
		<template v-if="days.length > 0">
			<section v-for="day in days" :key="day.key" class="activity-day">
				<h3 class="day-heading" data-testid="activity-day">
					{{ day.label }}
				</h3>
				<ul class="activity-list">
					<li
						v-for="item in day.items"
						:key="item.id"
						class="activity-item"
						:class="{ undone: item.undoneAt, open: openId === item.id }"
						data-testid="activity-item">
						<!-- One line per entry: time, person, task, coins. A tap
						     opens the details and the corrections below it. -->
						<button
							type="button"
							class="activity-row"
							:aria-expanded="openId === item.id ? 'true' : 'false'"
							:data-testid="`activity-row-${item.id}`"
							@click="openId = openId === item.id ? null : item.id">
							<span class="activity-time">{{ timeOf(item.completedAt) }}</span>
							<span class="activity-actor">{{ item.displayName || t('chorestrike', 'Unknown member') }}</span>
							<span class="activity-task">
								<span v-if="item.undoneAt" class="activity-undone-mark" :title="t('chorestrike', 'Undone')">↩</span>
								{{ item.taskTitle || t('chorestrike', 'Unknown task') }}
							</span>
							<!-- What the completion paid, frozen then; withheld with
							     the work of a task the viewer cannot see. -->
							<span v-if="coinsOf(item) !== null" class="activity-coins" :data-testid="`activity-coins-${item.id}`">
								+{{ coinsOf(item) }}
							</span>
						</button>
						<div v-if="openId === item.id" class="activity-details" :data-testid="`activity-details-${item.id}`">
							<p class="activity-facts">
								<span v-if="item.undoneAt">{{ t('chorestrike', 'Undone') }}</span>
								<span v-if="item.roomName" class="activity-room">{{ item.roomName }}</span>
								<!-- The frozen total effort — never the mutable
								     live definition. -->
								<span v-if="item.totalEnergy != null" :data-testid="`activity-energy-${item.id}`">
									{{ item.totalEnergy }} ⚡
								</span>
								<span v-for="(share, index) in item.allocation ?? []" :key="index" class="activity-share">
									{{ share.displayName }} {{ share.sharePct }}%
								</span>
							</p>
							<!-- Corrections of a still-valid completion, for its
							     completer or an owner (WP3 §3.6). -->
							<div v-if="item.canCorrect && !item.undoneAt" class="activity-actions">
								<button
									type="button"
									class="activity-correct"
									:data-testid="`activity-correct-${item.id}`"
									:disabled="busyId === item.id || blockedIds.includes(item.id)"
									@click="$emit('correct', item)">
									{{ t('chorestrike', 'Correct split') }}
								</button>
								<button
									type="button"
									class="activity-correct"
									:data-testid="`activity-undo-${item.id}`"
									:data-version="item.version"
									:disabled="busyId === item.id || blockedIds.includes(item.id)"
									@click="$emit('undo', item)">
									{{ t('chorestrike', 'Undo') }}
								</button>
							</div>
						</div>
					</li>
				</ul>
			</section>
		</template>

		<div v-else class="empty-state" data-testid="activity-empty">
			<p>{{ t('chorestrike', 'No activity in this period yet.') }}</p>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { addDaysToKey, dateKeyInZone, formatLongDayKey, todayKeyInZone } from '../../utils/dateKeys.js'
import { formatCoinMinor } from '../../utils/minorUnits.js'

/**
 * The household's completions, one line each and grouped by day
 * (CS-PLAN-090 WP-E). Days and times are the household's, not the
 * browser's. Presentation only: the view owns the corrections.
 */
export default {
	name: 'ActivityFeed',

	props: {
		/** Completions of `GET /house/activity`, newest first. */
		items: {
			type: Array,
			required: true,
		},

		/** IANA timezone of the household. */
		timezone: {
			type: String,
			default: 'UTC',
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

	data() {
		return { openId: null }
	},

	computed: {
		/**
		 * The items by household day, in their order.
		 * @return {Array<{key: string, label: string, items: object[]}>}
		 */
		days() {
			const today = todayKeyInZone(this.timezone)
			const yesterday = addDaysToKey(today, -1)
			const days = []
			for (const item of this.items) {
				const key = dateKeyInZone(item.completedAt, this.timezone)
				let day = days.find((entry) => entry.key === key)
				if (!day) {
					day = { key, label: this.dayLabel(key, today, yesterday), items: [] }
					days.push(day)
				}
				day.items.push(item)
			}
			return days
		},
	},

	methods: {
		t,

		dayLabel(key, today, yesterday) {
			if (key === today) {
				return t('chorestrike', 'Today')
			}
			return key === yesterday ? t('chorestrike', 'Yesterday') : formatLongDayKey(key)
		},

		/**
		 * The time of day in the household's timezone ("08:10").
		 * @param {?string} isoDate Completion instant.
		 * @return {string} Empty without a date.
		 */
		timeOf(isoDate) {
			if (!isoDate) {
				return ''
			}
			return new Date(isoDate).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', timeZone: this.timezone })
		},

		/**
		 * The coins a completion paid to everyone, or null when it paid
		 * none: undone, or work the viewer may not see.
		 * @param {object} item Completion.
		 * @return {?string}
		 */
		coinsOf(item) {
			const coinMinor = item.undoneAt
				? 0
				: (item.allocation ?? []).reduce((sum, share) => sum + (share.coinMinor ?? 0), 0)
			return coinMinor > 0 ? formatCoinMinor(coinMinor) : null
		},
	},
}
</script>

<style scoped>
.day-heading {
	margin: 0;
	padding: 0.5rem 0.5rem 0.15rem;
	font-size: 0.8rem;
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.activity-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.activity-item {
	border-bottom: 1px solid var(--color-border);
}

.activity-row {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	width: 100%;
	min-height: 44px;
	margin: 0;
	padding: 0 0.5rem;
	border: none;
	border-radius: 0;
	background: transparent;
	color: var(--color-main-text);
	font-weight: normal;
	text-align: start;
	cursor: pointer;
}

.activity-row:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: -2px;
}

.activity-item.open .activity-row {
	background: var(--color-primary-element-light);
}

.activity-time {
	flex: none;
	min-width: 2.75rem;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.activity-actor {
	flex: none;
	max-width: 6rem;
	overflow: hidden;
	font-weight: 600;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.activity-task {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.activity-item.undone .activity-task {
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
}

.activity-coins {
	flex: none;
	font-weight: 600;
	color: var(--color-success-text);
}

.activity-details {
	padding: 0.5rem 0.5rem 0.75rem;
}

.activity-facts {
	display: flex;
	flex-wrap: wrap;
	gap: 0.25rem 0.75rem;
	margin: 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.activity-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
	margin-block-start: 0.5rem;
}

.activity-correct {
	min-height: 44px;
	padding: 0.3rem 0.75rem;
	background: transparent;
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.8rem;
	font-weight: 600;
}

.activity-correct:hover:not(:disabled) {
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
</style>
