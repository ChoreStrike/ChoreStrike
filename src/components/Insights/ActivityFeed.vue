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
					<template v-for="item in day.items" :key="keyOf(item)">
						<!-- What happened besides completions: this visibility
						     replaces approval (simplified core). -->
						<li v-if="item.type" class="activity-item activity-event" :data-testid="`activity-event-${item.type}`">
							<span class="activity-time">{{ timeOf(item.at) }}</span>
							<span class="activity-actor">{{ item.displayName }}</span>
							<span class="activity-task">{{ eventText(item) }}</span>
							<span v-if="item.type === 'postponed'" class="activity-count">
								{{ t('chorestrike', '{count} of {limit}', { count: item.postponeCount, limit: item.postponeLimit }) }}
							</span>
						</li>
						<li
							v-else
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
								<span class="activity-actor">{{ entryPeople(item) }}</span>
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
									<span v-if="item.recordedForOthers" :data-testid="`activity-recorder-${item.id}`">
										{{ t('chorestrike', 'recorded by {name}', { name: item.displayName }) }}
									</span>
								</p>
								<!-- Corrections of a still-valid completion (ADR-021
								     decision 9): the people are the owner's to change;
								     an entry is deleted by the owner, or by who recorded
								     it while it is the latest one of its task. -->
								<div v-if="(item.canCorrect || item.canAttribute) && !item.undoneAt" class="activity-actions">
									<button
										v-if="item.canAttribute"
										type="button"
										class="activity-correct"
										:data-testid="`activity-correct-${item.id}`"
										:disabled="busyId === item.id || blockedIds.includes(item.id)"
										@click="$emit('correct', item)">
										{{ t('chorestrike', 'Correct split') }}
									</button>
									<button
										v-if="item.canCorrect"
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
					</template>
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
import { addDaysToKey, dateKeyInZone, formatDayKey, formatLongDayKey, todayKeyInZone } from '../../utils/dateKeys.js'
import { formatCoinMinor } from '../../utils/minorUnits.js'
import { entryPeople } from '../../utils/taskDisplay.js'

/**
 * The household's completions, one line each and grouped by day
 * (CS-PLAN-090 WP-E), with the postponements, date changes, deletions and
 * absences of the same days between them (CS-PLAN-091 WP-B3). Days and
 * times are the household's, not the browser's. Presentation only: the
 * view owns the corrections.
 */
export default {
	name: 'ActivityFeed',

	props: {
		/** Completions of `GET /house/activity`, newest first. */
		items: {
			type: Array,
			required: true,
		},

		/** Events of `GET /house/activity`: what happened besides completions. */
		events: {
			type: Array,
			default: () => [],
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
		 * Completions and events by household day, newest first.
		 * @return {Array<{key: string, label: string, items: object[]}>}
		 */
		days() {
			const today = todayKeyInZone(this.timezone)
			const yesterday = addDaysToKey(today, -1)
			const at = (line) => line.completedAt ?? line.at
			const lines = [...this.items, ...this.events].sort((a, b) => new Date(at(b)) - new Date(at(a)))
			const days = []
			for (const line of lines) {
				const key = dateKeyInZone(at(line), this.timezone)
				let day = days.find((entry) => entry.key === key)
				if (!day) {
					day = { key, label: this.dayLabel(key, today, yesterday), items: [] }
					days.push(day)
				}
				day.items.push(line)
			}
			return days
		},
	},

	methods: {
		entryPeople,
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
		 * A stable key per line: a completion by its id, an event by what
		 * it is about.
		 * @param {object} line Completion or event.
		 * @return {string}
		 */
		keyOf(line) {
			return line.type
				? ['e', line.type, line.at, line.roomTaskId ?? line.from, line.completionId ?? line.postponeCount ?? ''].join('-')
				: `c-${line.id}`
		},

		/**
		 * One line for an event.
		 * @param {object} event Event of the feed.
		 * @return {string}
		 */
		eventText(event) {
			const task = event.taskTitle || t('chorestrike', 'Unknown task')
			switch (event.type) {
				case 'postponed':
					return t('chorestrike', '{task} postponed', { task })
				case 'date_changed':
					return t('chorestrike', '{task}: date changed', { task })
				case 'deleted':
					return t('chorestrike', '{task}: entry deleted', { task })
				default:
					return this.absenceText(event)
			}
		},

		/**
		 * One line for an absence: whose it is (the line's person is who
		 * entered or removed it), as whole sentences for the translators.
		 * @param {object} event Absence event of the feed.
		 * @return {string}
		 */
		absenceText(event) {
			const period = { from: formatDayKey(event.from), to: formatDayKey(event.to) }
			const removed = event.type === 'absence_removed'
			if (event.scope === 'house') {
				return removed
					? t('chorestrike', 'Household absence {from} – {to} removed', period)
					: t('chorestrike', 'Household absence {from} – {to}', period)
			}
			// A member who left has no name any more.
			const name = event.subjectDisplayName || t('chorestrike', 'Former member')
			return removed
				? t('chorestrike', 'Absence of {name} {from} – {to} removed', { name, ...period })
				: t('chorestrike', '{name} away {from} – {to}', { name, ...period })
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

/* An event is a quiet line: no action, no coins. */
.activity-event {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	min-height: 36px;
	padding: 0 0.5rem;
}

.activity-event .activity-task,
.activity-count {
	color: var(--color-text-maxcontrast);
}

/* What happened stands behind the task's name: a long name wraps instead
   of cutting it off. */
.activity-event .activity-task {
	overflow: visible;
	overflow-wrap: anywhere;
	white-space: normal;
}

.activity-count {
	flex: none;
	font-size: 0.8rem;
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
