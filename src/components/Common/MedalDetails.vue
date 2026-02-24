<template>
	<div v-if="medal" class="medal-details">
		<div class="medal-header">
			<MedalBadge :type="medal.medalType" size="large" show-label />
			<span v-if="medal.value" class="medal-value">
				{{ t('chorestrike', 'Value: {value}', { value: medal.value }) }}
			</span>
		</div>

		<div class="medal-info">
			<div v-if="medal.userName" class="info-row">
				<span class="info-label">{{ t('chorestrike', 'Awarded to:') }}</span>
				<span class="info-value">{{ medal.userName }}</span>
			</div>

			<div v-if="medal.awardedAt" class="info-row">
				<span class="info-label">{{ t('chorestrike', 'Awarded:') }}</span>
				<span class="info-value">{{ formatDate(medal.awardedAt) }}</span>
			</div>

			<div v-if="medal.weekStart" class="info-row">
				<span class="info-label">{{ t('chorestrike', 'Week:') }}</span>
				<span class="info-value">{{ formatWeek(medal.weekStart) }}</span>
			</div>
		</div>

		<p v-if="description" class="medal-description">
			{{ description }}
		</p>
	</div>
</template>

<script>
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import MedalBadge from './MedalBadge.vue'

/**
 * Medal details component showing comprehensive medal information
 */
export default {
	name: 'MedalDetails',
	components: {
		MedalBadge,
	},
	props: {
		/**
		 * Medal object with details
		 */
		medal: {
			type: Object,
			required: true,
		},
	},
	setup(props) {
		const descriptions = {
			team_anchor: t('chorestrike', 'Awarded for completing 10 or more tasks in a week. A true pillar of the team!'),
			flow_keeper: t('chorestrike', 'Earned by maintaining activity for 5 or more days in a week. Consistency is key!'),
			room_guardian: t('chorestrike', 'Recognizes improving 3 or more rooms in a week. Every space matters!'),
			team_player: t('chorestrike', 'Given for completing 5 or more collaborative tasks. Together we achieve more!'),
			streak_keeper: t('chorestrike', 'Celebrates maintaining a 7-day activity streak. Dedication pays off!'),
		}

		const description = computed(() => descriptions[props.medal?.medalType] || '')

		/**
		 * Format date for display
		 * @param {string} dateString - ISO date string
		 */
		const formatDate = (dateString) => {
			if (!dateString) return ''
			const date = new Date(dateString)
			return date.toLocaleString(undefined, {
				dateStyle: 'medium',
				timeStyle: 'short',
			})
		}

		/**
		 * Format week range for display
		 * @param {string} weekStart - Week start date string
		 */
		const formatWeek = (weekStart) => {
			if (!weekStart) return ''
			const start = new Date(weekStart)
			const end = new Date(start)
			end.setDate(end.getDate() + 6)

			return t('chorestrike', '{start} - {end}', {
				start: start.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }),
				end: end.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }),
			})
		}

		return {
			description,
			formatDate,
			formatWeek,
			t,
		}
	},
}
</script>

<style scoped>
.medal-details {
	padding: 1rem;
}

.medal-header {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	margin-bottom: 1rem;
}

.medal-value {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
	padding: 0.25rem 0.5rem;
	border-radius: 6px;
}

.medal-info {
	display: grid;
	gap: 0.5rem;
	margin-bottom: 1rem;
}

.info-row {
	display: flex;
	gap: 0.5rem;
	align-items: baseline;
}

.info-label {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
	min-width: 100px;
}

.info-value {
	color: var(--color-main-text);
}

.medal-description {
	margin: 0;
	padding-top: 0.75rem;
	border-top: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
	font-style: italic;
	line-height: 1.5;
}
</style>
