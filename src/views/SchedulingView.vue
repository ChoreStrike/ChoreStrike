<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="scheduling-view" data-testid="scheduling-view">
		<header class="scheduling-header">
			<h1>{{ t('chorestrike', 'Smart Scheduling') }}</h1>
			<!-- Essential onboarding slice (WP8 §3.6): agreements vs
			     voluntary help and how actual shares split the work. -->
			<HelpEntry variant="context" topic="agreements" />
			<p class="subtitle">
				{{ t('chorestrike', 'Intelligent task distribution based on member availability and preferences') }}
			</p>
		</header>

		<div v-if="loading" class="scheduling-loading">
			<div class="skeleton skeleton-header" />
			<div class="skeleton skeleton-section" />
			<div class="skeleton skeleton-section" />
		</div>

		<div v-else-if="schedulingStore.error" class="scheduling-error" data-testid="scheduling-error">
			<p>{{ schedulingStore.error }}</p>
		</div>

		<div v-else class="scheduling-content">
			<section class="scheduling-section">
				<SelfAvailabilitySettings data-testid="self-availability-settings" />
			</section>

			<OvercapacityWarning data-testid="overcapacity-warning" />

			<section v-if="userStore.isHouseOwner" class="scheduling-section">
				<AvailabilitySettings data-testid="availability-settings" />
			</section>

			<section class="scheduling-section">
				<EnergyBudgetSettings data-testid="energy-budget-settings" />
			</section>

			<!-- CS-PLAN-065: the task-plan preview/apply is owner-only —
			     members and children keep the personal settings surfaces. -->
			<section v-if="userStore.isHouseOwner" class="scheduling-section">
				<SchedulePlanner data-testid="schedule-planner" />
			</section>

			<section class="scheduling-section">
				<ScheduleCalendar data-testid="schedule-calendar" />
			</section>
		</div>
	</div>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useSchedulingStore } from '../store/scheduling.js'
import { useRoomTasksStore } from '../store/roomTasks.js'
import { useRoomsStore } from '../store/rooms.js'
import { useTasksStore } from '../store/tasks.js'
import { useUserStore } from '../store/user.js'
import { logger } from '../utils/logger.js'
import AvailabilitySettings from '../components/Scheduling/AvailabilitySettings.vue'
import EnergyBudgetSettings from '../components/Scheduling/EnergyBudgetSettings.vue'
import SchedulePlanner from '../components/Scheduling/SchedulePlanner.vue'
import ScheduleCalendar from '../components/Scheduling/ScheduleCalendar.vue'
import SelfAvailabilitySettings from '../components/Scheduling/SelfAvailabilitySettings.vue'
import OvercapacityWarning from '../components/Scheduling/OvercapacityWarning.vue'
import HelpEntry from '../components/Help/HelpEntry.vue'

const schedulingStore = useSchedulingStore()
const tasksStore = useTasksStore()
const userStore = useUserStore()
const roomsStore = useRoomsStore()
const roomTasksStore = useRoomTasksStore()

const loading = computed(() => schedulingStore.loading)

onMounted(async () => {
	try {
		await schedulingStore.initializeScheduling()
	} catch (error) {
		logger.error('Failed to initialize scheduling', error, { view: 'SchedulingView', action: 'initializeScheduling' })
	}
	// Best-effort: preload tasks so the preview/suggestions sections have
	// data. Failures are non-fatal (those sections show their empty states).
	try {
		await tasksStore.fetchTasks()
	} catch (error) {
		logger.warn('Could not preload tasks for scheduling preview', { view: 'SchedulingView', error })
	}
	// Best-effort: room tasks feed the overcapacity warning. A failure
	// must not break the scheduling view (warning simply stays empty).
	// CS-PLAN-067 (D1): the planning candidates load as ONE house-wide
	// batch instead of a per-room request loop.
	try {
		await roomsStore.fetchFloorsWithRooms()
		await roomTasksStore.fetchHouseRoomTasks()
	} catch (error) {
		logger.warn('Could not preload room tasks for overcapacity warning', { view: 'SchedulingView', error })
	}
})
</script>

<style scoped>
.scheduling-view {
	max-width: 1200px;
	margin: 0 auto;
	padding: 2rem;
}

.scheduling-header {
	text-align: center;
	margin-bottom: 3rem;
}

.scheduling-header h1 {
	font-size: 2.5rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 0.5rem;
}

.subtitle {
	font-size: 1.1rem;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.scheduling-content {
	display: flex;
	flex-direction: column;
	gap: 2rem;
}

.scheduling-section {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 12px;
	padding: 1.5rem;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.scheduling-loading {
	padding: 1rem 0;
}

.scheduling-error {
	text-align: center;
	padding: 3rem 1rem;
	color: var(--color-error-text);
	font-size: 1rem;
}

.skeleton {
	background: linear-gradient(90deg, var(--color-background-dark) 25%, var(--color-background) 50%, var(--color-background-dark) 75%);
	background-size: 200% 100%;
	animation: skeleton-loading 1.5s infinite;
	border-radius: var(--border-radius-large);
}

.skeleton-header {
	width: 300px;
	height: 2rem;
	margin: 0 auto 2rem;
}

.skeleton-section {
	width: 100%;
	height: 200px;
	margin-bottom: 2rem;
}

@keyframes skeleton-loading {
	0% { background-position: 200% 0; }
	100% { background-position: -200% 0; }
}

@media (max-width: 768px) {
	.scheduling-view {
		padding: 1rem;
	}

	.scheduling-header h1 {
		font-size: 2rem;
	}

	.scheduling-section {
		padding: 1rem;
	}
}
</style>
