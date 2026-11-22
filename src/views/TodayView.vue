<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="today-view" data-testid="today-view">
		<header class="today-header">
			<h1 data-testid="today-title">
				{{ t('chorestrike', 'Today') }}
			</h1>
			<div class="filter-toggle" role="group" :aria-label="t('chorestrike', 'Task filter')">
				<button
					class="filter-button"
					:class="{ active: filter === 'mine' }"
					:aria-pressed="filter === 'mine'"
					data-testid="today-filter-mine"
					@click="filter = 'mine'">
					{{ t('chorestrike', 'My tasks') }}
				</button>
				<button
					class="filter-button"
					:class="{ active: filter === 'all' }"
					:aria-pressed="filter === 'all'"
					data-testid="today-filter-all"
					@click="filter = 'all'">
					{{ t('chorestrike', 'Everyone') }}
				</button>
			</div>
		</header>
		<div class="today-subline">
			<p class="today-date" data-testid="today-date">
				{{ dateLabel }}
			</p>
			<!-- Essential onboarding slice (WP8 §3.6): windows, rhythm,
			     neutral days and the contribution-vs-Coins line. -->
			<HelpEntry variant="context" topic="progress" />
		</div>

		<div v-if="loading" class="loading-state">
			{{ t('chorestrike', 'Loading your day…') }}
		</div>

		<div v-else-if="error" data-testid="today-error">
			<ErrorState
				:message="t('chorestrike', 'Could not load your tasks')"
				@retry="load" />
		</div>

		<template v-else>
			<ErrorState
				v-if="membersError"
				data-testid="member-names-error"
				:message="t('chorestrike', 'Failed to load members')"
				@retry="loadMembers" />

			<TodayStatusLine
				:energy="energy"
				:weeklyGoal="progress?.weeklyGoal"
				:rhythm="progress?.rhythm"
				:coinMinor="gameStore.wallet?.availableMinor" />
			<BonusDaysBanner :period="houseStore.bonusPeriod" :today="todayKey" />

			<!-- Overload clarification (C5f, ADR-022 decision 4): neutral,
			     amounts visible, never a refusal or a threat. -->
			<div
				v-if="overloaded"
				class="overload-banner"
				data-testid="today-overload"
				role="status">
				<Icon name="AlertTriangle" :size="18" class="overload-icon" />
				<p class="overload-text">
					{{ t('chorestrike', 'Your day holds {capacity} energy; {total} is suggested or still outstanding. That is too much for one day — postpone or share what does not fit.', { total: energy.planned, capacity: energy.capacity }) }}
				</p>
			</div>

			<!-- Partial derived refresh (CS-PLAN-066 D3): a committed action
			     stays successful; stale projections surface a read-only
			     retry — never a write replay. -->
			<div
				v-if="roomTasksStore.refreshFailed"
				class="refresh-banner"
				data-testid="today-refresh-failed"
				role="status">
				<span>{{ t('chorestrike', 'Some views could not be refreshed') }}</span>
				<button
					class="refresh-retry"
					:disabled="roomTasksStore.countsRefreshing"
					@click="retryDerivedRefresh">
					{{ t('chorestrike', 'Refresh') }}
				</button>
			</div>

			<!-- Overdue (C1c/C1d, ADR-021 decision 1): ONE row per chore
			     whose window ended, always first and allowed to be loud.
			     Completing it is late work; the rhythm restarts from it. -->
			<section v-if="sections.overdue.length > 0" class="overdue-block" data-testid="today-section-catchup">
				<h2>{{ t('chorestrike', 'Overdue · {count}', { count: sections.overdue.length }) }}</h2>
				<p class="overdue-hint">
					{{ t('chorestrike', 'Completing it now counts as late work.') }}
				</p>
				<TaskRow
					v-for="item in sections.overdue"
					:key="item.roomTask.id"
					:item="item"
					variant="overdue"
					@complete="(row, split) => complete(row, 'overdue', split)" />
			</section>

			<section v-if="!isEmpty" class="today-section">
				<div class="section-heading">
					<h2 data-testid="today-section-today">
						{{ t('chorestrike', 'Suggested for today') }}
					</h2>
					<span v-if="sections.due.length > 0" class="section-count">
						{{ sections.due.length === 1 ? t('chorestrike', '1 task') : t('chorestrike', '{count} tasks', { count: sections.due.length }) }}
					</span>
				</div>
				<p v-if="sections.due.length === 0" class="section-empty">
					{{ t('chorestrike', 'Nothing is suggested for today') }}
				</p>
				<TaskRow
					v-for="item in sections.due"
					:key="item.roomTask.id"
					:item="item"
					@complete="(row, split) => complete(row, 'due', split)" />
			</section>

			<!-- BUG-UX-005: work whose window is open although today's
			     suggestion does not hold it, behind one line. -->
			<section v-if="sections.possible.length > 0" class="today-section">
				<button
					type="button"
					class="possible-toggle"
					data-testid="today-section-open-window"
					:aria-expanded="showPossible"
					@click="showPossible = !showPossible">
					<span>{{ t('chorestrike', 'Also possible today · {count}', { count: sections.possible.length }) }}</span>
					<ExpandChevron :open="showPossible" />
				</button>
				<template v-if="showPossible">
					<TaskRow
						v-for="item in sections.possible"
						:key="item.roomTask.id"
						:item="item"
						variant="possible"
						@complete="(row, split) => complete(row, 'possible', split)" />
				</template>
			</section>

			<!-- Free day (C5f): a NEUTRAL empty state — no engagement
			     prompt, no pressure, no celebration promise. -->
			<div v-if="isEmpty" class="empty-state" data-testid="today-empty-state">
				<Icon name="Sun" :size="48" />
				<p>{{ t('chorestrike', 'Nothing is planned for today.') }}</p>
			</div>

			<!-- Never due: the bonus tasks stand apart from the day's work. -->
			<TodayBonusSection :rows="bonusRows" @complete="(row, split) => requestCompletion(row.roomTask.id, split)" />

			<SetupWizardEntry :ready="structureRead" autoOpen @applied="load" />
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import ErrorState from '../components/Common/ErrorState.vue'
import ExpandChevron from '../components/Common/ExpandChevron.vue'
import Icon from '../components/Common/Icon.vue'
import HelpEntry from '../components/Help/HelpEntry.vue'
import SetupWizardEntry from '../components/Setup/SetupWizardEntry.vue'
import TaskRow from '../components/Tasks/TaskRow.vue'
import BonusDaysBanner from '../components/Today/BonusDaysBanner.vue'
import TodayBonusSection from '../components/Today/TodayBonusSection.vue'
import TodayStatusLine from '../components/Today/TodayStatusLine.vue'
import { useHouseMemberNames } from '../composables/useHouseMemberNames.js'
import { useTaskCompletion } from '../composables/useTaskCompletion.js'
import { useGameStore } from '../store/game.js'
import { useHouseStore } from '../store/house.js'
import { useRoomsStore } from '../store/rooms.js'
import { useRoomTasksStore } from '../store/roomTasks.js'
import { useSchedulingStore } from '../store/scheduling.js'
import { useUserStore } from '../store/user.js'
import { formatLongDayKey, todayKeyInZone, weekdayOfKey } from '../utils/dateKeys.js'
import { logger } from '../utils/logger.js'
import { buildTodaySections, openEnergy, viewerFilters } from '../utils/todaySections.js'

/**
 * Today, the one landing page for every role (CS-PLAN-090 WP-B): the
 * viewer's status, the overdue block, what the live suggestion places on
 * today (CS-PLAN-091 WP-C, ADR-022), what is already possible and the
 * bonus tasks (WP-B4). The rows are selectors of the store's daily,
 * overdue and bonus reads (CS-PLAN-066 §3.3); the server decides
 * visibility, eligibility, child limits and what is suggested to whom.
 */
export default {
	name: 'TodayView',

	components: {
		Icon,
		ExpandChevron,
		ErrorState,
		HelpEntry,
		SetupWizardEntry,
		BonusDaysBanner,
		TodayBonusSection,
		TodayStatusLine,
		TaskRow,
	},

	setup() {
		const roomTasksStore = useRoomTasksStore()
		const houseStore = useHouseStore()
		const userStore = useUserStore()
		const gameStore = useGameStore()
		const schedulingStore = useSchedulingStore()
		const roomsStore = useRoomsStore()
		const completion = useTaskCompletion({ roomTasksStore, view: 'TodayView' })
		return { roomTasksStore, roomsStore, houseStore, userStore, gameStore, schedulingStore, ...useHouseMemberNames(userStore), ...completion }
	},

	data() {
		return {
			loading: false,
			error: false,
			filter: 'mine',
			showPossible: false,
			// The floors and the own capacity are optional reads: until
			// one succeeded, "no floors" and the default budget are no facts.
			structureRead: false,
			capacityRead: false,
			// Where each row stood when its completion started, by room
			// task: `{ section, index, item }` (see `heldRows`).
			held: {},
		}
	},

	computed: {
		myUserId() {
			return this.userStore.currentUser?.nextcloudUserId || null
		},

		todayKey() {
			return todayKeyInZone(this.houseStore.timezone)
		},

		dateLabel() {
			return formatLongDayKey(this.todayKey)
		},

		/** What "My tasks" shows: the viewer's work and what today suggests to them. */
		mine() {
			return viewerFilters(this.myUserId)
		},

		/** The bonus tasks the filter lets through; they are no part of the day's sections. */
		bonusRows() {
			return this.roomTasksStore.bonusTasks.filter(this.filter === 'all' ? () => true : this.mine.isMine)
		},

		/**
		 * Rows whose completion is in flight or just succeeded keep their
		 * place, so a recurring row shows its feedback before the reads
		 * move it on (D-090-30).
		 * @return {Array<{section: string, index: number, item: object}>}
		 */
		heldRows() {
			return Object.values(this.held).filter(({ item }) => this.roomTasksStore.isCompleting(item.roomTask.id)
				|| this.roomTasksStore.wasJustCompleted(item.roomTask.id))
		},

		sections() {
			return this.filter === 'all'
				? this.buildSections(() => true, () => true, this.heldRows)
				: this.buildSections(this.mine.isMine, this.mine.isSuggested, this.heldRows)
		},

		isEmpty() {
			return Object.values(this.sections).every((rows) => rows.length === 0)
		},

		/**
		 * What is suggested to the viewer or overdue for them against their
		 * own energy for today, whatever the filter shows. Pure aggregation
		 * of server facts; the placement stays server-side.
		 * @return {?{planned: number, capacity: number}} Null until the capacity is read.
		 */
		energy() {
			if (!this.capacityRead) {
				return null
			}
			const weekday = weekdayOfKey(todayKeyInZone(this.houseStore.timezone))
			return {
				planned: openEnergy(this.buildSections(this.mine.isMine, this.mine.isSuggested)),
				capacity: this.schedulingStore.getOwnCapacityForDay(weekday),
			}
		},

		overloaded() {
			return this.energy !== null && this.energy.planned > this.energy.capacity
		},

		/**
		 * The server-evaluated streak and the shared weekly goal (C1e/C1f),
		 * unknown until the game state is read.
		 * @return {?{weeklyGoal: object, rhythm: object}}
		 */
		progress() {
			return this.gameStore.gameState === null
				? null
				: { weeklyGoal: this.gameStore.weeklyGoal, rhythm: this.gameStore.rhythm }
		},
	},

	async mounted() {
		this.loadStructure()
		await this.load()
	},

	methods: {
		t,

		buildSections(isVisible, isSuggested, held = []) {
			return buildTodaySections({
				daily: this.roomTasksStore.dailyTasks,
				overdue: this.roomTasksStore.overdueTasks,
				isVisible,
				isSuggested,
				held,
			})
		},

		/**
		 * Floor names are optional here: without them a row names its room,
		 * and the store has logged the failure. A successful read also
		 * tells the setup entry whether the house has floors at all.
		 */
		async loadStructure() {
			if (this.roomsStore.floors.length > 0) {
				this.structureRead = true
				return
			}
			try {
				this.structureRead = await this.roomsStore.fetchFloorsWithRooms() !== null
			} catch {
				this.structureRead = false
			}
		},

		async load() {
			this.loading = true
			this.error = false
			try {
				// Rows are enriched server-side (CS-PLAN-066 D1) — the daily
				// and overdue reads carry task, room and canonical assignees.
				// No due-today read: nothing on Today shows that count, and a
				// loaded count would join every refresh after a completion.
				await Promise.all([
					this.roomTasksStore.fetchFilteredDailyTasks(),
					this.roomTasksStore.fetchOverdueTasks(),
				])
				this.loadStatus()
			} catch (error) {
				this.error = true
				logger.error('Failed to load today view', error, { view: 'TodayView', action: 'load' })
			} finally {
				this.loading = false
			}
		},

		/**
		 * The status line's reads are OPTIONAL: a failure leaves a dash in
		 * its tile — never an error state for the day's work.
		 */
		loadStatus() {
			this.gameStore.fetchGameState().catch((error) => {
				logger.error('Failed to load the progress snapshot', error, { view: 'TodayView', action: 'loadStatus' })
			})
			this.schedulingStore.fetchUserPreferences().then(() => {
				this.capacityRead = true
			}).catch((error) => {
				logger.error('Failed to load the capacity facts', error, { view: 'TodayView', action: 'loadStatus' })
			})
			// The bonus tasks are an offer: without them the day's work still shows.
			this.roomTasksStore.fetchBonusTasks().catch(() => {})
		},

		async retryDerivedRefresh() {
			// Read-only retry (D3): refetch the stale projections, never
			// replay any committed write.
			try {
				await this.roomTasksStore.refreshDerivedProjections()
			} catch (error) {
				logger.error('Failed to refresh derived projections', error, { view: 'TodayView', action: 'retryDerivedRefresh' })
			}
		},

		/**
		 * Complete a row through the shared flow (`useTaskCompletion`) and
		 * remember where it stands, so it can be held there.
		 * @param {object} item The row.
		 * @param {string} section The section showing it.
		 * @param {?object} [split] The split of the row's picker.
		 */
		async complete(item, section, split = null) {
			const id = item.roomTask.id
			const stillHeld = Object.fromEntries(this.heldRows.map((entry) => [entry.item.roomTask.id, entry]))
			const index = this.sections[section].findIndex((row) => row.roomTask.id === id)
			this.held = { ...stillHeld, [id]: { section, index, item } }
			await this.requestCompletion(id, split)
		},
	},
}
</script>

<style scoped>
.today-view {
	padding: 1rem var(--cs-view-gutter) 2rem;
	max-width: 900px;
	margin: 0 auto;
}

.today-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	flex-wrap: wrap;
	gap: 0.5rem 1rem;
	padding-inline-start: var(--cs-view-heading-inset);
}

.today-header h1 {
	font-size: 2rem;
	font-weight: bold;
	margin: 0;
}

.today-subline {
	display: flex;
	align-items: center;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: 0.25rem 1rem;
	margin: 0.25rem 0 0.75rem;
}

.today-date {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.filter-toggle {
	display: flex;
	gap: 0.25rem;
	padding: 0.25rem;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	background: var(--color-background-dark);
}

.filter-button {
	min-height: 44px;
	padding: 0.5rem 1rem;
	border: 1px solid var(--color-text-maxcontrast);
	border-radius: 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 0.9rem;
	font-weight: 500;
}

.filter-button.active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-color: var(--color-primary-element);
}

.overload-banner {
	display: flex;
	align-items: flex-start;
	gap: 0.75rem;
	padding: 0.75rem 1rem;
	margin-bottom: 0.75rem;
	border: 1px solid var(--color-border);
	border-inline-start: 3px solid var(--color-element-warning);
	border-radius: 10px;
	background: var(--color-background-dark);
}

.overload-icon {
	color: var(--color-warning-text);
	margin-top: 0.15rem;
}

.overload-text {
	margin: 0;
	color: var(--color-main-text);
	font-size: 0.9rem;
	line-height: 1.4;
}

.refresh-banner {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 0.75rem;
	padding: 0.6rem 1rem;
	margin-bottom: 0.75rem;
	border: 1px solid var(--color-element-warning);
	border-radius: 8px;
	background: var(--color-background-dark);
}

.refresh-retry {
	min-height: 44px;
	padding: 0.4rem 1rem;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: transparent;
	cursor: pointer;
	font-weight: 600;
}

/* The one loud element of the page: overdue work, always first. */
.overdue-block {
	margin-bottom: 1.25rem;
	border: 2px solid var(--color-element-error);
	border-radius: 12px;
	background: var(--color-error);
	overflow: hidden;
}

.overdue-block h2 {
	margin: 0;
	padding: 0.4rem 0.75rem;
	border-bottom: 2px solid var(--color-element-error);
	color: var(--color-error-text);
	font-size: 0.85rem;
	font-weight: 700;
	letter-spacing: 0.04em;
	text-transform: uppercase;
}

/* The main text colour: the muted one is too faint on this background. */
.overdue-hint {
	margin: 0;
	padding: 0.4rem 0.75rem 0;
	color: var(--color-main-text);
	font-size: 0.8rem;
}

.today-section {
	margin-bottom: 1.25rem;
}

.section-heading {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 1rem;
}

.section-heading h2 {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
	font-weight: 700;
	letter-spacing: 0.04em;
	text-transform: uppercase;
}

.section-count,
.section-empty {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.section-empty {
	margin: 0.5rem 0 0;
}

.possible-toggle {
	display: flex;
	align-items: center;
	justify-content: space-between;
	width: 100%;
	min-height: 48px;
	padding: 0 0.25rem;
	border: none;
	border-block: 1px solid var(--color-border);
	border-radius: 0;
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
	font-weight: 500;
	text-align: start;
}

.possible-toggle:hover {
	background: var(--color-background-hover);
}

.possible-toggle:focus-visible,
.filter-button:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.loading-state,
.empty-state {
	text-align: center;
	padding: 3rem 1rem;
	color: var(--color-text-maxcontrast);
}

.empty-state p {
	margin: 0.5rem 0 0 0;
	font-size: 1.1rem;
}

@media (max-width: 600px) {
	.today-view {
		padding: 1rem var(--cs-view-gutter);
	}
}
</style>
