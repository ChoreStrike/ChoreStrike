<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<table class="member-fairness" data-testid="member-fairness-table">
		<thead>
			<tr class="fairness-header">
				<th>{{ t('chorestrike', 'Member') }}</th>
				<th data-testid="header-contribution">
					{{ t('chorestrike', 'Contribution') }}
				</th>
				<th data-testid="header-coins">
					{{ t('chorestrike', 'Coins') }}
				</th>
				<th>{{ t('chorestrike', 'Streak') }}</th>
				<th>{{ t('chorestrike', 'Freezes') }}</th>
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
					<span
						v-if="row.frozenToday"
						class="freeze-badge active-freeze"
						data-testid="active-freeze-badge"
						:title="t('chorestrike', 'Streak protected by a freeze')">
						❄ {{ t('chorestrike', 'Frozen') }}
					</span>
				</td>
				<td class="numeric" :data-testid="`contribution-${row.id}`">
					{{ formatNumber(row.contributionScore) }}
				</td>
				<!-- Coins stay a SEPARATE column — contribution and coins must
				     never be merged into one metric (energy-and-balancing.md). -->
				<td class="numeric coins" :data-testid="`coins-${row.id}`">
					{{ formatNumber(row.coins) }}
				</td>
				<td class="numeric">
					{{ row.currentStreak }}
				</td>
				<td class="numeric freeze-count" :data-testid="`freezes-${row.id}`">
					❄ {{ row.streakFreezes }}
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
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
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
			const yesterday = new Date()
			yesterday.setDate(yesterday.getDate() - 1)
			const yesterdayKey = `${yesterday.getFullYear()}-${String(yesterday.getMonth() + 1).padStart(2, '0')}-${String(yesterday.getDate()).padStart(2, '0')}`

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

				// "Frozen" display rule: badge only when the last consumption
				// was yesterday (server-timezone-normalized dates).
				let frozenToday = false
				if (member.lastFreezeDate) {
					const freezeDate = new Date(member.lastFreezeDate)
					const freezeKey = `${freezeDate.getFullYear()}-${String(freezeDate.getMonth() + 1).padStart(2, '0')}-${String(freezeDate.getDate()).padStart(2, '0')}`
					frozenToday = freezeKey === yesterdayKey
				}

				return {
					id: member.id,
					displayName: member.displayName || member.nextcloudUserId,
					contributionScore: member.contributionScore ?? 0,
					coins: member.coins ?? 0,
					currentStreak: member.currentStreak ?? 0,
					streakFreezes: member.streakFreezes ?? 0,
					frozenToday,
					completedEnergy,
					weeklyCapacity,
					loadRatio: weeklyCapacity > 0 ? completedEnergy / weeklyCapacity : 0,
				}
			})
		},
	},

	methods: {
		t,

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

.freeze-badge.active-freeze {
	font-size: 0.75rem;
	color: #4fc3f7;
}

.freeze-count {
	color: #4fc3f7;
}

.coins {
	font-weight: 600;
	color: var(--color-warning, #f39c12);
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
	background: var(--color-error);
}

.load-label {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

@media (max-width: 768px) {
	.fairness-header th:nth-child(4),
	.fairness-header th:nth-child(5),
	.numeric:nth-child(4),
	.numeric:nth-child(5) {
		display: none;
	}
}
</style>
