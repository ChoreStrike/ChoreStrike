<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="schedule-calendar" data-testid="schedule-calendar">
		<div class="calendar-header">
			<h2>{{ t('chorestrike', 'Schedule Calendar') }}</h2>
			<div class="calendar-controls">
				<div class="date-navigation">
					<button
						class="nav-button"
						data-testid="previous-week-button"
						@click="previousWeek">
						&larr;
					</button>
					<div class="current-week">
						<span class="week-range" data-testid="week-range">{{ weekRange }}</span>
					</div>
					<button
						class="nav-button"
						data-testid="next-week-button"
						@click="nextWeek">
						&rarr;
					</button>
				</div>
				<button
					class="today-button"
					data-testid="today-button"
					@click="goToToday">
					{{ t('chorestrike', 'Today') }}
				</button>
			</div>
		</div>

		<div class="calendar-content">
			<div class="week-view">
				<div class="week-header">
					<div
						v-for="day in weekDays"
						:key="day.date"
						:class="['day-header', { 'today': isToday(day.date), 'weekend': isWeekend(day.date) }]">
						<div class="day-info">
							<span class="day-name">{{ day.name }}</span>
							<span class="day-date">{{ day.dayNumber }}</span>
						</div>
						<div class="day-capacity">
							<span class="capacity-label">{{ t('chorestrike', 'Capacity') }}:</span>
							<span class="capacity-value">{{ getDayCapacity(day.dayKey) }}</span>
							<span
								v-if="isOvercapacity(day)"
								class="day-warning"
								data-testid="overcapacity-marker"
								:title="overcapacityTitle(day)">
								⚠ {{ plannedEnergy(day.date) }}/{{ getDayCapacity(day.dayKey) }}
							</span>
						</div>
					</div>
				</div>

				<div class="member-rows">
					<div
						v-for="member in members"
						:key="member.userId"
						class="member-row">
						<div class="member-label">
							{{ member.userName || member.userId }}
						</div>
						<div
							v-for="day in weekDays"
							:key="`${member.userId}-${day.date}`"
							:class="['member-day-cell', { available: isMemberAvailableOnDay(member, day.dayKey), unavailable: !isMemberAvailableOnDay(member, day.dayKey) }]"
							:data-testid="`slot-${day.dayKey}`">
							<span class="cell-energy">{{ getMemberDayEnergy(member, day.dayKey) }}</span>
						</div>
					</div>
				</div>
			</div>

			<div v-if="members.length === 0" class="empty-state">
				<p>{{ t('chorestrike', 'No member data available. Load scheduling data first.') }}</p>
			</div>
		</div>
	</div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { translate as t, getDayNames, getLocale } from '@nextcloud/l10n'
import { useSchedulingStore } from '../../store/scheduling.js'
import { useHouseStore } from '../../store/house.js'
import { addDaysToKey, todayKeyInZone, weekdayOfKey } from '../../utils/dateKeys.js'
import { isMemberAvailable, getMemberEnergy } from '../../constants/scheduling.js'

const schedulingStore = useSchedulingStore()
const houseStore = useHouseStore()

// CS-PLAN-057: the calendar week is anchored on the house-local "today"
// day key, so the week grid, today-highlight and the per-day energy keys
// agree with the backend's day bucketing regardless of browser timezone.
// Initialized synchronously (not onMounted) so the first render already
// shows the week.
const currentWeekStartKey = ref(null)
const weekDays = ref([])

// Locale-aware calendar names (CR-016-06): day names come from the
// Nextcloud translations (Sunday-first, indexed by Date.getUTCDay()), the
// week range is formatted with Intl for the user's Nextcloud locale. The
// range label formats the (house-local) grid keys verbatim — the formatter
// is pinned to UTC and fed the key's noon, so the displayed date parts are
// exactly the key's.
const localizedDayNames = getDayNames()
const rangeFormatter = new Intl.DateTimeFormat(getLocale(), { day: 'numeric', month: 'short', timeZone: 'UTC' })

const members = computed(() => schedulingStore.memberAvailability)

const dateAtNoonUtc = (key) => {
	const [year, month, day] = key.split('-').map(Number)
	return new Date(Date.UTC(year, month - 1, day, 12))
}

const weekRange = computed(() => {
	if (weekDays.value.length === 0) return ''
	const first = weekDays.value[0]
	const last = weekDays.value[weekDays.value.length - 1]
	return `${rangeFormatter.format(dateAtNoonUtc(first.date))} - ${rangeFormatter.format(dateAtNoonUtc(last.date))}`
})

const weekdayIndexOfKey = (key) => {
	const [year, month, day] = key.split('-').map(Number)
	return new Date(Date.UTC(year, month - 1, day)).getUTCDay()
}

const mondayOfWeekOfKey = (key) => {
	const index = weekdayIndexOfKey(key)
	// Monday-start week (matches the availability/energy data model).
	return addDaysToKey(key, index === 0 ? -6 : 1 - index)
}

const generateWeekDays = () => {
	if (!currentWeekStartKey.value) return
	const days = []

	for (let i = 0; i < 7; i++) {
		const key = addDaysToKey(currentWeekStartKey.value, i)
		days.push({
			date: key,
			name: localizedDayNames[weekdayIndexOfKey(key)],
			dayNumber: Number(key.slice(8, 10)),
			dayKey: weekdayOfKey(key),
		})
	}

	weekDays.value = days
}

const getDayCapacity = (dayKey) => {
	// getCapacityForDay falls back to the current user's own budget for
	// non-owners (member availability is owner-only data).
	return schedulingStore.getCapacityForDay(dayKey)
}

// CS-PLAN-040 (FEAT-UX-022): per-day overcapacity marker — planned energy
// vs available capacity, computed by the scheduling-store getters.
const plannedEnergy = (dateKey) => {
	return schedulingStore.plannedEnergyForDay(dateKey)
}

const isOvercapacity = (day) => {
	const planned = plannedEnergy(day.date)
	return planned > 0 && planned > getDayCapacity(day.dayKey)
}

const overcapacityTitle = (day) => {
	return t('chorestrike', 'Planned energy ({planned}) exceeds the available capacity ({capacity})', {
		planned: plannedEnergy(day.date),
		capacity: getDayCapacity(day.dayKey),
	})
}

const isMemberAvailableOnDay = (member, dayKey) => {
	return isMemberAvailable(member, dayKey)
}

const getMemberDayEnergy = (member, dayKey) => {
	if (!isMemberAvailableOnDay(member, dayKey)) return '—'
	return getMemberEnergy(member, dayKey)
}

const isToday = (date) => {
	return date === todayKeyInZone(houseStore.timezone)
}

const isWeekend = (date) => {
	const dayName = weekdayOfKey(date)
	return dayName === 'saturday' || dayName === 'sunday'
}

const previousWeek = () => {
	currentWeekStartKey.value = addDaysToKey(currentWeekStartKey.value, -7)
	generateWeekDays()
}

const nextWeek = () => {
	currentWeekStartKey.value = addDaysToKey(currentWeekStartKey.value, 7)
	generateWeekDays()
}

const goToToday = () => {
	currentWeekStartKey.value = mondayOfWeekOfKey(todayKeyInZone(houseStore.timezone))
	generateWeekDays()
}

goToToday()

watch(() => schedulingStore.memberAvailability, () => {
	generateWeekDays()
}, { deep: true })
</script>

<style scoped>
.schedule-calendar {
	background: var(--color-main-background);
	border-radius: 12px;
	padding: 1.5rem;
}

.calendar-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 2rem;
	flex-wrap: wrap;
	gap: 1rem;
}

.calendar-header h2 {
	font-size: 1.5rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0;
}

.calendar-controls {
	display: flex;
	align-items: center;
	gap: 1rem;
	flex-wrap: wrap;
}

.date-navigation {
	display: flex;
	align-items: center;
	gap: 1rem;
}

.nav-button {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 36px;
	height: 36px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	transition: all 0.2s ease;
}

.nav-button:hover {
	background: var(--color-background-dark);
}

.current-week {
	font-weight: 500;
	color: var(--color-main-text);
	min-width: 150px;
	text-align: center;
}

.today-button {
	padding: 0.5rem 1rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	transition: all 0.2s ease;
}

.today-button:hover {
	background: var(--color-background-dark);
}

.week-view {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	overflow: hidden;
}

.week-header {
	display: grid;
	grid-template-columns: repeat(7, 1fr);
	background: var(--color-background-dark);
	border-bottom: 1px solid var(--color-border);
}

.day-header {
	padding: 1rem;
	border-inline-end: 1px solid var(--color-border);
	text-align: center;
}

.day-header:last-child {
	border-inline-end: none;
}

.day-header.today {
	background: rgba(33, 150, 243, 0.1);
}

.day-header.weekend {
	background: rgba(255, 152, 0, 0.05);
}

.day-info {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	margin-bottom: 0.5rem;
}

.day-name {
	font-weight: 600;
	color: var(--color-main-text);
}

.day-date {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.day-capacity {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.capacity-label {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.capacity-value {
	font-weight: 600;
	color: var(--color-primary-element);
}

.day-warning {
	display: inline-flex;
	align-items: center;
	gap: 0.2rem;
	font-size: 0.75rem;
	font-weight: 600;
	color: var(--color-error-text);
	background: var(--color-error-hover, #fdedec);
	border-radius: 8px;
	padding: 0.1rem 0.4rem;
	margin-top: 0.25rem;
}

.member-rows {
	max-height: 400px;
	overflow-y: auto;
}

.member-row {
	display: grid;
	grid-template-columns: 120px repeat(7, 1fr);
	border-bottom: 1px solid var(--color-border);
}

.member-row:last-child {
	border-bottom: none;
}

.member-label {
	padding: 1rem;
	border-inline-end: 1px solid var(--color-border);
	background: var(--color-background-dark);
	font-weight: 500;
	color: var(--color-main-text);
	display: flex;
	align-items: center;
}

.member-day-cell {
	padding: 1rem;
	border-inline-end: 1px solid var(--color-border);
	text-align: center;
	display: flex;
	align-items: center;
	justify-content: center;
}

.member-day-cell:last-child {
	border-inline-end: none;
}

.member-day-cell.available {
	background: rgba(76, 175, 80, 0.08);
}

.member-day-cell.unavailable {
	background: rgba(244, 67, 54, 0.08);
}

.cell-energy {
	font-weight: 500;
	color: var(--color-main-text);
}

.empty-state {
	text-align: center;
	padding: 2rem 0;
	color: var(--color-text-maxcontrast);
}

@media (max-width: 768px) {
	.calendar-header {
		flex-direction: column;
		align-items: flex-start;
	}

	.calendar-controls {
		width: 100%;
		justify-content: space-between;
	}

	.week-header {
		grid-template-columns: repeat(7, 1fr);
	}

	.member-row {
		grid-template-columns: 80px repeat(7, 1fr);
	}

	.member-label {
		font-size: 0.8rem;
		padding: 0.5rem;
	}

	.member-day-cell {
		padding: 0.5rem;
	}
}
</style>
