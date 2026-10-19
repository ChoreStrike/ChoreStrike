<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="member-fairness-scroll" data-testid="member-fairness-scroll">
		<!-- UX-018: the table scrolls inside its own box, never the whole view -->
		<table class="member-fairness" data-testid="member-fairness-table">
			<thead>
				<tr class="fairness-header">
					<th>{{ t('chorestrike', 'Member') }}</th>
					<th data-testid="header-contribution">
						{{ t('chorestrike', 'Contribution') }}
					</th>
					<th>{{ t('chorestrike', 'Streak') }}</th>
					<th data-testid="header-load">
						{{ t('chorestrike', 'Load') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="row in rows"
					:key="row.id"
					class="fairness-row"
					data-testid="fairness-row">
					<td class="member-cell">
						<span class="member-name">{{ row.displayName }}</span>
					</td>
					<td class="numeric" :data-testid="`contribution-${row.id}`">
						{{ formatMinor(row.contributionMinor) }}
					</td>
					<td class="numeric">
						{{ row.currentStreak }}
					</td>
					<td class="load-cell" :data-testid="`load-${row.id}`">
						<div class="load-bar" :class="{ over: row.loadRatio > 1 }">
							<div class="load-fill" :style="{ width: loadPercent(row) }" />
						</div>
						<span class="load-label">{{ row.completedEnergy }} / {{ row.weeklyCapacity }} {{ t('chorestrike', 'energy') }}</span>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
// CS-PLAN-070 WP3 cutover: the Coin column, freeze columns and freeze badge
// are gone — money is the wallet (caller-safe own block only, never a
// foreign balance in member DTOs) and dated pauses replaced freezes. The
// exact contributionMinor is the work evidence (100 = 1 energy).
import { translate as t } from '@nextcloud/l10n'
import { formatMinor } from '../../utils/minorUnits.js'
import { WEEKDAY_KEYS, resolveEnergyForDay } from '../../utils/energyBudgets.js'

export default {
	name: 'MemberFairnessTable',

	props: {
		members: {
			type: Array,
			required: true,
		},
		/** Completed energy per house user id (from the activity feed). */
		completedEnergyByUser: {
			type: Object,
			default: () => ({}),
		},
	},

	computed: {
		rows() {
			return this.members.map(member => {
				const preferences = member.schedulingPreferences || {}
				const budgets = preferences.energyBudget || {}
				const availability = preferences.weekdayAvailability || {}

				let weeklyCapacity = 0
				for (const day of WEEKDAY_KEYS) {
					if (availability[day] === false) continue
					weeklyCapacity += resolveEnergyForDay(budgets, day)
				}

				const completedEnergy = this.completedEnergyByUser[member.id] ?? 0

				return {
					id: member.id,
					displayName: member.displayName || member.nextcloudUserId,
					contributionMinor: member.contributionMinor ?? 0,
					currentStreak: member.currentStreak ?? 0,
					completedEnergy,
					weeklyCapacity,
					loadRatio: weeklyCapacity > 0 ? completedEnergy / weeklyCapacity : 0,
				}
			})
		},
	},

	methods: {
		t,
		formatMinor,

		formatNumber(value) {
			return value?.toLocaleString() || '0'
		},

		loadPercent(row) {
			if (row.loadRatio <= 0) return '0%'
			return `${Math.min(100, Math.round(row.loadRatio * 100))}%`
		},
	},
}
</script>

<style scoped>
.member-fairness-scroll {
	max-width: 100%;
	overflow-x: auto;
}

.member-fairness {
	width: 100%;
	border-collapse: collapse;
}

.fairness-header th {
	text-align: start;
	padding: 0.75rem;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	border-bottom: 2px solid var(--color-border);
}

.numeric {
	text-align: end;
	padding: 0.75rem;
}

.member-cell {
	padding: 0.75rem;
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.member-name {
	font-weight: 500;
	color: var(--color-main-text);
}

.fairness-row td {
	border-bottom: 1px solid var(--color-border);
}

.load-cell {
	padding: 0.75rem;
	min-width: 160px;
}

.load-bar {
	height: 8px;
	background: var(--color-background-dark);
	border-radius: 4px;
	overflow: hidden;
	margin-bottom: 0.35rem;
}

.load-fill {
	height: 100%;
	background: var(--color-primary);
	border-radius: 4px;
	transition: width 0.3s ease;
}

.load-bar.over .load-fill {
	background: var(--color-element-error);
}

.load-label {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}
</style>
