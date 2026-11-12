<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<!-- Compact bars (CS-PLAN-090 WP-E): one line per member, the bar is
	     this period's completed energy against the weekly capacity. The
	     rows wrap on a phone, so nothing scrolls sideways (UX-018). -->
	<ul class="member-fairness" data-testid="member-fairness-table">
		<li
			v-for="row in rows"
			:key="row.id"
			class="fairness-row"
			data-testid="fairness-row">
			<span class="member-name">{{ row.displayName }}</span>
			<span class="load-cell" :data-testid="`load-${row.id}`">
				<span class="load-bar" :class="{ over: row.loadRatio > 1 }" aria-hidden="true">
					<span class="load-fill" :style="{ width: loadPercent(row) }" />
				</span>
				<span class="load-label">{{ formatEnergyMinor(row.completedEnergyMinor) }} / {{ row.weeklyCapacity }} {{ t('chorestrike', 'energy') }}</span>
			</span>
			<span class="member-facts">
				<span>{{ t('chorestrike', 'Contribution') }} <strong :data-testid="`contribution-${row.id}`">{{ formatMinor(row.contributionMinor) }}</strong></span>
				<span>{{ t('chorestrike', 'Streak') }} <strong :data-testid="`streak-${row.id}`">{{ row.currentStreak }}</strong></span>
			</span>
		</li>
	</ul>
</template>

<script>
// CS-PLAN-070 WP3 cutover: the Coin column, freeze columns and freeze badge
// are gone — money is the wallet (caller-safe own block only, never a
// foreign balance in member DTOs) and dated pauses replaced freezes. The
// exact contributionMinor is the work evidence (100 = 1 energy).
import { translate as t } from '@nextcloud/l10n'
import { resolveEnergyForDay, WEEKDAY_KEYS } from '../../utils/energyBudgets.js'
import { ENERGY_MINOR_PER_UNIT, formatEnergyMinor, formatMinor } from '../../utils/minorUnits.js'

export default {
	name: 'MemberFairnessTable',

	props: {
		members: {
			type: Array,
			required: true,
		},

		/** Completed energy minor units per house user id (from the activity feed). */
		completedEnergyMinorByUser: {
			type: Object,
			default: () => ({}),
		},
	},

	computed: {
		rows() {
			return this.members.map((member) => {
				const preferences = member.schedulingPreferences || {}
				const budgets = preferences.energyBudget || {}
				const availability = preferences.weekdayAvailability || {}

				let weeklyCapacity = 0
				for (const day of WEEKDAY_KEYS) {
					if (availability[day] === false) {
						continue
					}
					weeklyCapacity += resolveEnergyForDay(budgets, day)
				}

				const completedEnergyMinor = this.completedEnergyMinorByUser[member.id] ?? 0

				return {
					id: member.id,
					displayName: member.displayName || member.nextcloudUserId,
					contributionMinor: member.contributionMinor ?? 0,
					currentStreak: member.currentStreak ?? 0,
					completedEnergyMinor,
					weeklyCapacity,
					loadRatio: weeklyCapacity > 0 ? completedEnergyMinor / (weeklyCapacity * ENERGY_MINOR_PER_UNIT) : 0,
				}
			})
		},
	},

	methods: {
		t,
		formatEnergyMinor,
		formatMinor,

		loadPercent(row) {
			if (row.loadRatio <= 0) {
				return '0%'
			}
			return `${Math.min(100, Math.round(row.loadRatio * 100))}%`
		},
	},
}
</script>

<style scoped>
.member-fairness {
	list-style: none;
	margin: 0;
	padding: 0;
}

.fairness-row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.15rem 0.75rem;
	min-height: 36px;
	padding: 0.25rem 0.5rem;
	border-bottom: 1px solid var(--color-border);
}

.member-name {
	flex: 0 0 7rem;
	min-width: 0;
	overflow: hidden;
	font-weight: 500;
	color: var(--color-main-text);
	text-overflow: ellipsis;
	white-space: nowrap;
}

.load-cell {
	display: flex;
	flex: 1 1 12rem;
	align-items: center;
	gap: 0.5rem;
	min-width: 0;
}

.load-bar {
	display: block;
	flex: 1;
	height: 8px;
	background: var(--color-background-dark);
	box-shadow: var(--cs-graphic-track-outline);
	border-radius: 4px;
	overflow: hidden;
}

.load-fill {
	display: block;
	height: 100%;
	background: var(--color-primary-element);
	border-radius: 4px;
	transition: width 0.3s ease;
}

.load-bar.over .load-fill {
	background: var(--color-element-error);
}

.load-label,
.member-facts {
	flex: none;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.member-facts {
	display: flex;
	gap: 0.75rem;
}

.member-facts strong {
	color: var(--color-main-text);
}
</style>
