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

		<div v-if="loading" class="loading-state">
			<div class="loading-spinner" />
			<p>{{ t('chorestrike', 'Loading schedule calendar...') }}</p>
		</div>

		<div v-else class="calendar-content">
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
import { computed, onMounted, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useSchedulingStore } from '../../store/scheduling.js'

const schedulingStore = useSchedulingStore()

const currentWeekStart = ref(new Date())
const weekDays = ref([])

const loading = computed(() => schedulingStore.loading)

const members = computed(() => schedulingStore.memberAvailability)

const weekRange = computed(() => {
	const start = new Date(currentWeekStart.value)
	const end = new Date(start)
	end.setDate(start.getDate() + 6)
	return `${start.toLocaleDateString()} - ${end.toLocaleDateString()}`
})

const dayKeyFromDate = (date) => {
	const dayNames = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']
	return dayNames[date.getDay()]
}

// Local YYYY-MM-DD (avoids the UTC drift of toISOString() near midnight, which
// made the "today" highlight land on the wrong cell in non-UTC timezones).
const localDateString = (date) => {
	const y = date.getFullYear()
	const m = String(date.getMonth() + 1).padStart(2, '0')
	const d = String(date.getDate()).padStart(2, '0')
	return `${y}-${m}-${d}`
}

const generateWeekDays = () => {
	const start = new Date(currentWeekStart.value)
	const days = []
	const dayNames = [
		t('chorestrike', 'Sunday'),
		t('chorestrike', 'Monday'),
		t('chorestrike', 'Tuesday'),
		t('chorestrike', 'Wednesday'),
		t('chorestrike', 'Thursday'),
		t('chorestrike', 'Friday'),
		t('chorestrike', 'Saturday'),
	]

	for (let i = 0; i < 7; i++) {
		const date = new Date(start)
		date.setDate(start.getDate() + i)
		days.push({
			date: localDateString(date),
			name: dayNames[date.getDay()],
			dayNumber: date.getDate(),
			dayKey: dayKeyFromDate(date),
		})
	}

	weekDays.value = days
}

const getDayCapacity = (dayKey) => {
	return schedulingStore.getTotalEnergyForDay(dayKey)
}

const isMemberAvailableOnDay = (member, dayKey) => {
	return member.weekdayAvailability?.[dayKey] !== false
}

const getMemberDayEnergy = (member, dayKey) => {
	if (!isMemberAvailableOnDay(member, dayKey)) return '—'
	return member.energyBudget?.[dayKey] ?? 10
}

const isToday = (date) => {
	return date === localDateString(new Date())
}

const isWeekend = (date) => {
	const day = new Date(date).getDay()
	return day === 0 || day === 6
}

const previousWeek = () => {
	const newStart = new Date(currentWeekStart.value)
	newStart.setDate(newStart.getDate() - 7)
	currentWeekStart.value = newStart
	generateWeekDays()
}

const nextWeek = () => {
	const newStart = new Date(currentWeekStart.value)
	newStart.setDate(newStart.getDate() + 7)
	currentWeekStart.value = newStart
	generateWeekDays()
}

const goToToday = () => {
	const today = new Date()
	// Monday-start week (matches the availability/energy data model).
	const dayOfWeek = today.getDay()
	const offset = dayOfWeek === 0 ? -6 : 1 - dayOfWeek
	const startOfWeek = new Date(today)
	startOfWeek.setDate(today.getDate() + offset)
	currentWeekStart.value = startOfWeek
	generateWeekDays()
}

onMounted(() => {
	// Open on the current week instead of today + 6 days.
	goToToday()
})

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

.loading-state {
	text-align: center;
	padding: 3rem 0;
}

.loading-spinner {
	width: 40px;
	height: 40px;
	border: 3px solid var(--color-border);
	border-top: 3px solid var(--color-primary);
	border-radius: 50%;
	animation: spin 1s linear infinite;
	margin: 0 auto 1rem;
}

@keyframes spin {
	0% { transform: rotate(0deg); }
	100% { transform: rotate(360deg); }
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
	border-right: 1px solid var(--color-border);
	text-align: center;
}

.day-header:last-child {
	border-right: none;
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
	color: var(--color-primary);
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
	border-right: 1px solid var(--color-border);
	background: var(--color-background-dark);
	font-weight: 500;
	color: var(--color-main-text);
	display: flex;
	align-items: center;
}

.member-day-cell {
	padding: 1rem;
	border-right: 1px solid var(--color-border);
	text-align: center;
	display: flex;
	align-items: center;
	justify-content: center;
}

.member-day-cell:last-child {
	border-right: none;
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
