<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="scheduling-view" data-testid="scheduling-view">
		<header class="scheduling-header">
			<router-link :to="{ name: 'calendar' }" class="back-link" data-testid="scheduling-back">
				{{ t('chorestrike', 'Back to the calendar') }}
			</router-link>
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
			<OvercapacityWarning data-testid="overcapacity-warning" />

			<section v-if="userStore.isHouseOwner" class="scheduling-section">
				<AvailabilitySettings data-testid="availability-settings" />
			</section>

			<!-- CS-PLAN-065: owner-only. The own days and energy are in the
			     personal settings (CS-PLAN-090 WP-D). -->
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
import { translate as t } from '@nextcloud/l10n'
import { computed, onMounted } from 'vue'
import HelpEntry from '../components/Help/HelpEntry.vue'
import AvailabilitySettings from '../components/Scheduling/AvailabilitySettings.vue'
import OvercapacityWarning from '../components/Scheduling/OvercapacityWarning.vue'
import ScheduleCalendar from '../components/Scheduling/ScheduleCalendar.vue'
import SchedulePlanner from '../components/Scheduling/SchedulePlanner.vue'
import { useRoomsStore } from '../store/rooms.js'
import { useRoomTasksStore } from '../store/roomTasks.js'
import { useSchedulingStore } from '../store/scheduling.js'
import { useTasksStore } from '../store/tasks.js'
import { useUserStore } from '../store/user.js'
import { logger } from '../utils/logger.js'

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
	padding: 2rem var(--cs-view-gutter);
}

.scheduling-header {
	text-align: center;
	margin-bottom: 3rem;
	padding-inline-start: var(--cs-view-heading-inset);
}

.back-link {
	display: inline-flex;
	align-items: center;
	min-height: 44px;
	color: var(--color-main-text);
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
	box-shadow: var(--cs-shadow-md);
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
	background: linear-gradient(90deg, var(--color-background-dark) 25%, var(--color-main-background) 50%, var(--color-background-dark) 75%);
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
		padding: 1rem var(--cs-view-gutter);
	}

	.scheduling-header h1 {
		font-size: 2rem;
	}

	.scheduling-section {
		padding: 1rem;
	}
}
</style>
