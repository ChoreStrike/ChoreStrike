<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="house-health-bar" :class="{ compact }" data-testid="house-health">
		<div class="health-header">
			<h3 class="health-title">
				<Icon name="Home" :size="20" class="icon" />
				{{ houseName }}
			</h3>
			<div class="health-percentage">
				{{ houseHealth }}%
			</div>
		</div>

		<div class="health-bar-container">
			<!-- The track comes first: both layers are absolutely positioned, so DOM order sets the paint order. -->
			<div class="health-bar-background" />
			<div
				class="health-bar-fill"
				:class="healthClass"
				:style="{ width: `${houseHealth}%` }">
				<div class="health-bar-glow" />
			</div>
		</div>

		<div class="health-status">
			<span class="status-text" :class="healthClass">
				{{ healthStatus }}
			</span>
			<button
				v-if="showRefreshButton"
				class="refresh-button"
				data-testid="refresh-house-health-button"
				:disabled="loading"
				:title="t('chorestrike', 'Refresh house health')"
				@click="refreshHealth">
				<Icon name="RefreshCw" :size="16" class="icon" />
				<span class="button-text">{{ t('chorestrike', 'Reload') }}</span>
			</button>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'
import { useHouseStore } from '../../store/house.js'
import { logger } from '../../utils/logger.js'
import { Icon } from '../Common/index.js'

export default {
	name: 'HouseHealthBar',

	components: {
		Icon,
	},

	props: {
		showRefreshButton: {
			type: Boolean,
			default: true,
		},
		compact: {
			type: Boolean,
			default: false,
		},
	},

	setup(props) {
		const houseStore = useHouseStore()

		// Computed properties
		const houseHealth = computed(() => houseStore.houseHealth)
		const houseName = computed(() => houseStore.houseName)
		const loading = computed(() => houseStore.loading)

		const healthClass = computed(() => {
			const health = houseHealth.value
			if (health >= 80) {
				return 'excellent'
			}
			if (health >= 60) {
				return 'good'
			}
			if (health >= 40) {
				return 'fair'
			}
			if (health >= 20) {
				return 'poor'
			}
			return 'terrible'
		})

		const healthStatus = computed(() => {
			const health = houseHealth.value
			if (health >= 80) {
				return t('chorestrike', 'Excellent! 🌟')
			}
			if (health >= 60) {
				return t('chorestrike', 'Good! 👍')
			}
			if (health >= 40) {
				return t('chorestrike', 'Fair 📊')
			}
			if (health >= 20) {
				return t('chorestrike', 'Needs attention ⚠️')
			}
			return t('chorestrike', 'Critical! 🚨')
		})

		// Methods
		const refreshHealth = async () => {
			try {
				await houseStore.calculateHouseHealth()
			} catch (error) {
				logger.error('Failed to refresh house health', error, { component: 'HouseHealthBar', action: 'refreshHealth' })
			}
		}

		return {
			t,
			houseHealth,
			houseName,
			loading,
			healthClass,
			healthStatus,
			refreshHealth,
		}
	},
}
</script>

<style scoped>
.house-health-bar {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 16px;
  margin-bottom: 16px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.health-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
}

.health-title {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  overflow-wrap: anywhere;
  font-size: 18px;
  font-weight: 600;
  margin: 0;
  color: var(--color-main-text);
}

.health-title .icon {
  font-size: 20px;
}

.health-percentage {
  font-size: 24px;
  font-weight: bold;
  color: var(--color-main-text);
}

.health-bar-container {
  position: relative;
  height: 24px;
  margin-bottom: 12px;
}

.health-bar-background {
  position: absolute;
  top: 0;
  inset-inline: 0;
  bottom: 0;
  background: var(--color-background-dark);
  border-radius: 12px;
  overflow: hidden;
}

.health-bar-fill {
  position: absolute;
  top: 0;
  inset-inline-start: 0;
  height: 100%;
  border-radius: 12px;
  transition: width 0.6s ease, background-color 0.3s ease;
  overflow: hidden;
}

.health-bar-fill.excellent {
  background: linear-gradient(90deg, var(--cs-health-full), var(--cs-health-good));
}

.health-bar-fill.good {
  background: linear-gradient(90deg, var(--cs-health-full), var(--color-primary-element-light, #4FA8E0));
}

.health-bar-fill.fair {
  background: linear-gradient(90deg, var(--cs-celebrate-gold), var(--cs-celebrate-accent));
}

.health-bar-fill.poor {
  background: linear-gradient(90deg, var(--cs-celebrate-accent), var(--cs-health-low));
}

.health-bar-fill.terrible {
  background: linear-gradient(90deg, var(--cs-health-low), var(--cs-streak-flame));
}

.health-bar-glow {
  position: absolute;
  top: 0;
  inset-inline: 0;
  bottom: 0;
  background: linear-gradient(90deg,
    rgba(255, 255, 255, 0.3) 0%,
    rgba(255, 255, 255, 0.1) 50%,
    rgba(255, 255, 255, 0.3) 100%);
  animation: shimmer 2s infinite;
}

@keyframes shimmer {
  0% { transform: translateX(-100%); }
  100% { transform: translateX(100%); }
}

.health-status {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.status-text {
  font-size: 14px;
  font-weight: 500;
  transition: color 0.3s ease;
}

.status-text.excellent {
  color: var(--color-success-text);
}

.status-text.good {
  color: var(--color-info-text);
}

.status-text.fair {
  color: var(--color-warning-text);
}

.status-text.poor,
.status-text.terrible {
  color: var(--color-error-text);
}

.refresh-button {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 6px;
  padding: 7px 10px;
  cursor: pointer;
  transition: all 0.2s ease;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 600;
}

.refresh-button:hover:not(:disabled) {
  background: var(--color-background-hover);
  border-color: var(--color-primary-element);
}

.refresh-button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.refresh-button .icon {
  font-size: 15px;
  transition: transform 0.3s ease;
}

.button-text {
  line-height: 1;
}

.refresh-button:hover:not(:disabled) .icon {
  transform: rotate(180deg);
}

/* Compact mode */
.house-health-bar.compact {
  padding: 12px;
  margin-bottom: 12px;
}

.house-health-bar.compact .health-title {
  font-size: 16px;
}

.house-health-bar.compact .health-percentage {
  font-size: 20px;
}

.house-health-bar.compact .health-bar-container {
  height: 20px;
  margin-bottom: 8px;
}

/* Responsive design */
@media (max-width: 768px) {
  .house-health-bar {
    padding: 12px;
  }

  .health-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
  }

  .health-title {
    font-size: 16px;
  }

  .health-percentage {
    font-size: 20px;
  }

  .button-text {
    display: none;
  }
}
</style>
