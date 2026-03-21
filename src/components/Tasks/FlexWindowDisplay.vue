<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
  
  Displays flex window bounds (earliest/ideal/latest dates) for tasks with flexible scheduling.
-->

<template>
	<div v-if="hasFlexWindow" class="flex-window-display" :class="{ compact }">
		<div class="flex-window-header">
			<Icon name="Calendar" :size="14" />
			<span class="flex-label">{{ t('chorestrike', 'Flex Window') }}</span>
			<span class="flex-days-badge">{{ flexDays }} {{ t('chorestrike', 'days') }}</span>
		</div>
		
		<div class="flex-window-timeline">
			<div class="timeline-point earliest" :class="{ active: isEarliestActive }">
				<div class="point-marker" />
				<div class="point-label">{{ t('chorestrike', 'Earliest') }}</div>
				<div class="point-date">{{ formatDate(earliest) }}</div>
			</div>
			
			<div class="timeline-connector">
				<div class="connector-line" />
			</div>
			
			<div class="timeline-point ideal" :class="{ active: isIdealActive }">
				<div class="point-marker">
					<Icon name="Star" :size="12" />
				</div>
				<div class="point-label">{{ t('chorestrike', 'Ideal') }}</div>
				<div class="point-date">{{ formatDate(ideal) }}</div>
			</div>
			
			<div class="timeline-connector">
				<div class="connector-line" />
			</div>
			
			<div class="timeline-point latest" :class="{ active: isLatestActive, overdue: isLatestOverdue }">
				<div class="point-marker" />
				<div class="point-label">{{ t('chorestrike', 'Latest') }}</div>
				<div class="point-date">{{ formatDate(latest) }}</div>
			</div>
		</div>
		
		<div v-if="showStatus" class="flex-window-status">
			<span v-if="isEarlyCompletion" class="status-early">
				<Icon name="Zap" :size="12" />
				{{ t('chorestrike', 'Early completion bonus available!') }}
			</span>
			<span v-else-if="isInWindow" class="status-optimal">
				<Icon name="Check" :size="12" />
				{{ t('chorestrike', 'Within optimal window') }}
			</span>
			<span v-else-if="isPastLatest" class="status-overdue">
				<Icon name="AlertCircle" :size="12" />
				{{ t('chorestrike', 'Past latest date') }}
			</span>
		</div>
	</div>
</template>

<script>
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { Icon } from '../Common/index.js'

/**
 * FlexWindowDisplay component shows the flexible scheduling window for tasks.
 * Displays earliest, ideal, and latest due dates with visual timeline.
 */
export default {
	name: 'FlexWindowDisplay',

	components: {
		Icon,
	},

	props: {
		/** ISO date string for earliest bound */
		earliest: {
			type: String,
			default: null,
		},
		/** ISO date string for ideal due date */
		ideal: {
			type: String,
			default: null,
		},
		/** ISO date string for latest bound */
		latest: {
			type: String,
			default: null,
		},
		/** Number of flex days */
		flexDays: {
			type: Number,
			default: 0,
		},
		/** Show compact version */
		compact: {
			type: Boolean,
			default: false,
		},
		/** Show status indicator */
		showStatus: {
			type: Boolean,
			default: true,
		},
	},

	setup(props) {
		const hasFlexWindow = computed(() => {
			return props.flexDays > 0 && props.earliest && props.ideal && props.latest
		})

		const now = computed(() => new Date())

		const earliestDate = computed(() => props.earliest ? new Date(props.earliest) : null)
		const idealDate = computed(() => props.ideal ? new Date(props.ideal) : null)
		const latestDate = computed(() => props.latest ? new Date(props.latest) : null)

		const isEarliestActive = computed(() => {
			if (!earliestDate.value) return false
			return now.value >= earliestDate.value
		})

		const isIdealActive = computed(() => {
			if (!idealDate.value) return false
			return now.value >= idealDate.value
		})

		const isLatestActive = computed(() => {
			if (!latestDate.value) return false
			return now.value >= latestDate.value
		})

		const isLatestOverdue = computed(() => {
			if (!latestDate.value) return false
			return now.value > latestDate.value
		})

		const isEarlyCompletion = computed(() => {
			if (!earliestDate.value || !idealDate.value) return false
			return now.value >= earliestDate.value && now.value < idealDate.value
		})

		const isInWindow = computed(() => {
			if (!idealDate.value || !latestDate.value) return false
			return now.value >= idealDate.value && now.value <= latestDate.value
		})

		const isPastLatest = computed(() => {
			if (!latestDate.value) return false
			return now.value > latestDate.value
		})

		/**
		 * Format date for display
		 * @param {string} dateStr - ISO date string
		 * @returns {string} Formatted date
		 */
		const formatDate = (dateStr) => {
			if (!dateStr) return ''
			const date = new Date(dateStr)
			const today = new Date()
			today.setHours(0, 0, 0, 0)
			
			const tomorrow = new Date(today)
			tomorrow.setDate(tomorrow.getDate() + 1)
			
			const dateOnly = new Date(date)
			dateOnly.setHours(0, 0, 0, 0)

			if (dateOnly.getTime() === today.getTime()) {
				return t('chorestrike', 'Today')
			}
			if (dateOnly.getTime() === tomorrow.getTime()) {
				return t('chorestrike', 'Tomorrow')
			}

			// Format as short date
			return date.toLocaleDateString(undefined, { 
				month: 'short', 
				day: 'numeric' 
			})
		}

		return {
			hasFlexWindow,
			isEarliestActive,
			isIdealActive,
			isLatestActive,
			isLatestOverdue,
			isEarlyCompletion,
			isInWindow,
			isPastLatest,
			formatDate,
			t,
		}
	},
}
</script>

<style scoped>
.flex-window-display {
	background: var(--color-background-hover);
	border-radius: 8px;
	padding: 0.75rem;
	margin-top: 0.5rem;
	border: 1px solid var(--color-border);
}

.flex-window-display.compact {
	padding: 0.5rem;
}

.flex-window-header {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-bottom: 0.5rem;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.flex-label {
	font-weight: 500;
}

.flex-days-badge {
	background: var(--color-primary-light);
	color: var(--color-primary);
	padding: 2px 6px;
	border-radius: 10px;
	font-size: 10px;
	font-weight: 600;
}

.flex-window-timeline {
	display: flex;
	align-items: flex-start;
	gap: 0;
	margin-bottom: 0.5rem;
}

.timeline-point {
	flex: 1;
	display: flex;
	flex-direction: column;
	align-items: center;
	text-align: center;
	min-width: 60px;
}

.point-marker {
	width: 16px;
	height: 16px;
	border-radius: 50%;
	background: var(--color-background-dark);
	border: 2px solid var(--color-border);
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--color-text-maxcontrast);
}

.timeline-point.earliest .point-marker {
	border-color: #27ae60;
}

.timeline-point.ideal .point-marker {
	background: var(--color-primary);
	border-color: var(--color-primary);
	color: white;
}

.timeline-point.latest .point-marker {
	border-color: #e74c3c;
}

.timeline-point.active .point-marker {
	background: var(--color-primary-light);
}

.timeline-point.earliest.active .point-marker {
	background: #d5f4e6;
}

.timeline-point.latest.active .point-marker {
	background: #fdedec;
}

.timeline-point.latest.overdue .point-marker {
	background: #e74c3c;
	border-color: #e74c3c;
}

.point-label {
	font-size: 10px;
	color: var(--color-text-maxcontrast);
	margin-top: 0.25rem;
	text-transform: uppercase;
	font-weight: 500;
}

.point-date {
	font-size: 11px;
	color: var(--color-main-text);
	font-weight: 600;
	margin-top: 0.1rem;
}

.timeline-connector {
	flex: 0 0 20px;
	display: flex;
	align-items: center;
	justify-content: center;
	padding-top: 7px;
}

.connector-line {
	width: 100%;
	height: 2px;
	background: var(--color-border);
}

.flex-window-status {
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 11px;
	padding: 0.25rem 0.5rem;
	border-radius: 4px;
	margin-top: 0.25rem;
}

.status-early {
	color: #27ae60;
	background: #d5f4e6;
}

.status-optimal {
	color: var(--color-primary);
	background: var(--color-primary-light);
}

.status-overdue {
	color: #e74c3c;
	background: #fdedec;
}

.flex-window-status span {
	display: flex;
	align-items: center;
	gap: 0.25rem;
}

/* Compact mode adjustments */
.flex-window-display.compact .flex-window-header {
	margin-bottom: 0.25rem;
}

.flex-window-display.compact .flex-window-timeline {
	margin-bottom: 0.25rem;
}

.flex-window-display.compact .timeline-point {
	min-width: 50px;
}

.flex-window-display.compact .point-label {
	font-size: 9px;
}

.flex-window-display.compact .point-date {
	font-size: 10px;
}
</style>
