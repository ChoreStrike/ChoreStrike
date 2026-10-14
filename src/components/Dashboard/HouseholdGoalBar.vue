<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div
		class="household-goal-bar"
		:class="{ achieved: isAchieved }"
		data-testid="household-goal-bar">
		<div class="goal-header">
			<h3 class="goal-title">
				<Icon name="Users" :size="20" class="icon" />
				{{ t('chorestrike', 'Weekly Household Goal') }}
			</h3>
			<div class="goal-percentage" data-testid="household-goal-percent">
				{{ percent }}%
			</div>
		</div>

		<div class="goal-bar-container">
			<div class="goal-bar-track">
				<div
					class="goal-bar-fill"
					:style="{ width: `${percent}%` }" />
				<div
					v-if="targetPercent < 100"
					class="goal-target-marker"
					data-testid="goal-target-marker"
					:style="{ left: `${targetPercent}%` }"
					:title="t('chorestrike', 'Goal: {target}%', { target: targetPercent })" />
			</div>
		</div>

		<div class="goal-status">
			<span class="goal-counts" data-testid="household-goal-counts">
				{{
					t('chorestrike', '{completed} of {due} tasks this week', {
						completed: completedCount,
						due: dueCount,
					})
				}}
			</span>
			<span
				v-if="isAchieved"
				class="goal-achievement-badge"
				data-testid="household-goal-achieved">
				{{ t('chorestrike', 'Goal achieved!') }}
			</span>
			<span
				v-else-if="hasActivity"
				class="goal-target-text">
				{{ t('chorestrike', 'Target: {target}%', { target: targetPercent }) }}
			</span>
		</div>
	</div>
</template>

<script>
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useGameStore } from '../../store/game.js'
import Icon from '../Common/Icon.vue'

export default {
	name: 'HouseholdGoalBar',

	components: {
		Icon,
	},

	setup() {
		const gameStore = useGameStore()

		const snapshot = computed(() => gameStore.weeklyGoal)
		const percent = computed(() => snapshot.value?.percent ?? 0)
		const targetPercent = computed(() => snapshot.value?.targetPercent ?? 80)
		const completedCount = computed(() => snapshot.value?.completedCount ?? 0)
		const dueCount = computed(() => snapshot.value?.dueCount ?? 0)
		const isAchieved = computed(() => snapshot.value?.achieved === true)
		// Suppress copy when the house is entirely empty (no due AND no completed).
		const hasActivity = computed(() => dueCount.value > 0 || completedCount.value > 0)

		return {
			t,
			percent,
			targetPercent,
			completedCount,
			dueCount,
			isAchieved,
			hasActivity,
		}
	},
}
</script>

<style scoped>
.household-goal-bar {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 16px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
  transition: background 0.4s ease, border-color 0.4s ease;
}

.household-goal-bar.achieved {
  background: linear-gradient(135deg, var(--color-success), var(--color-main-background));
  border-color: var(--color-border-success);
}

.goal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}

.goal-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 1rem;
  font-weight: 600;
  margin: 0;
  color: var(--color-main-text);
}

.goal-percentage {
  font-size: 1.4rem;
  font-weight: 700;
  color: var(--color-primary-element);
  font-variant-numeric: tabular-nums;
}

.goal-bar-container {
  margin-bottom: 10px;
}

.goal-bar-track {
  position: relative;
  height: 14px;
  background: var(--color-background-dark);
  border-radius: 8px;
  overflow: visible;
}

.goal-bar-fill {
  position: absolute;
  top: 0;
  inset-inline-start: 0;
  height: 100%;
  background: linear-gradient(90deg, var(--color-primary-element), var(--color-primary-element-light));
  border-radius: 8px;
  transition: width 0.5s ease;
}

.household-goal-bar.achieved .goal-bar-fill {
  background: linear-gradient(90deg, var(--cs-celebrate-success), var(--cs-health-good));
}

.goal-target-marker {
  position: absolute;
  top: -3px;
  bottom: -3px;
  width: 3px;
  background: var(--color-main-text);
  opacity: 0.55;
  border-radius: 2px;
  transform: translateX(-1.5px);
  pointer-events: none;
}

.goal-status {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.goal-counts {
  font-size: 0.85rem;
  color: var(--color-text-maxcontrast);
}

.goal-target-text {
  font-size: 0.8rem;
  color: var(--color-text-maxcontrast);
}

.goal-achievement-badge {
  font-size: 0.85rem;
  font-weight: 700;
  color: var(--color-success-text);
  background: var(--color-success);
  padding: 3px 10px;
  border-radius: 10px;
}

@media (max-width: 768px) {
  .household-goal-bar {
    padding: 12px;
  }

  .goal-title {
    font-size: 0.95rem;
  }

  .goal-percentage {
    font-size: 1.2rem;
  }
}
</style>
