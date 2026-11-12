<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="house-settings cs-settings-form">
		<ErrorState v-if="loadError" @retry="load" />
		<div class="setting-card">
			<div class="form-group">
				<label for="house-name">{{ t('chorestrike', 'House Name') }}</label>
				<div class="input-group">
					<input
						id="house-name"
						v-model="houseName"
						type="text"
						class="text-input"
						data-testid="house-name-input"
						:disabled="!houseReady || houseStore.loading"
						:placeholder="t('chorestrike', 'Enter house name')">
					<button
						class="save-button"
						data-testid="save-house-name"
						:disabled="!canSaveHouseName || houseStore.loading"
						@click="saveHouseName">
						<Icon name="Save" :size="16" />
						{{ t('chorestrike', 'Save') }}
					</button>
				</div>
			</div>
			<div class="form-group">
				<label for="house-timezone">{{ t('chorestrike', 'Timezone') }}</label>
				<p class="setting-hint">
					{{ t('chorestrike', 'Calendar days, streaks and weekly goals are counted by this timezone.') }}
				</p>
				<div class="input-group">
					<select
						id="house-timezone"
						v-model="houseTimezone"
						class="text-input"
						data-testid="house-timezone-select"
						:disabled="!houseReady || houseStore.loading">
						<option v-for="zone in curatedTimezones" :key="zone" :value="zone">
							{{ zone }}
						</option>
					</select>
					<button
						class="save-button"
						data-testid="save-house-timezone"
						:disabled="!canSaveHouseTimezone || houseStore.loading"
						@click="saveHouseTimezone">
						<Icon name="Save" :size="16" />
						{{ t('chorestrike', 'Save') }}
					</button>
				</div>
			</div>
			<div class="form-group">
				<label for="house-ranking">{{ t('chorestrike', 'Weekly ranking') }}</label>
				<p class="setting-hint">
					{{ t('chorestrike', 'Opt-in comparison of on-time shares. Every member decides separately on their own participation; turning it off removes everyone immediately.') }}
				</p>
				<div class="input-group">
					<input
						id="house-ranking"
						v-model="rankingEnabled"
						type="checkbox"
						class="ranking-toggle"
						data-testid="ranking-toggle"
						:disabled="!houseReady || houseStore.loading">
					<button
						class="save-button"
						data-testid="save-house-ranking"
						:disabled="!canSaveRanking || houseStore.loading"
						@click="saveRanking">
						<Icon name="Save" :size="16" />
						{{ t('chorestrike', 'Save') }}
					</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import ErrorState from '../Common/ErrorState.vue'
import Icon from '../Common/Icon.vue'
import { CURATED_TIMEZONES } from '../../constants/timezones.js'
import { useHouseStore } from '../../store/house.js'
import { logger } from '../../utils/logger.js'

/**
 * House name, timezone and the weekly-ranking switch (household settings,
 * owner only). Reads the house when it opens; the controls stay disabled
 * until that read has answered, and a failed read offers a retry without
 * enabling any write.
 */
export default {
	name: 'HouseSettings',

	components: { ErrorState, Icon },

	setup() {
		return { houseStore: useHouseStore() }
	},

	data() {
		return {
			houseReady: false,
			loadError: false,
			houseName: '',
			houseTimezone: 'UTC',
			rankingEnabled: false,
		}
	},

	computed: {
		canSaveHouseName() {
			return this.houseReady && this.houseStore.house?.name && this.houseName.trim() && this.houseName !== this.houseStore.houseName
		},

		canSaveHouseTimezone() {
			return this.houseReady && this.houseStore.house?.name && this.houseTimezone && this.houseTimezone !== this.houseStore.timezone
		},

		canSaveRanking() {
			return this.houseReady && this.houseStore.house?.name && this.rankingEnabled !== this.houseStore.rankingEnabled
		},

		curatedTimezones() {
			return CURATED_TIMEZONES
		},
	},

	mounted() {
		return this.load()
	},

	methods: {
		t,

		async load() {
			this.houseReady = false
			this.loadError = false
			try {
				await this.houseStore.fetchHouse()
				if (!this.houseStore.house?.name) {
					throw new Error('House name is required')
				}
				this.houseName = this.houseStore.house.name
				this.houseTimezone = this.houseStore.timezone
				this.rankingEnabled = this.houseStore.rankingEnabled
				this.houseReady = true
			} catch (error) {
				this.loadError = true
				logger.error('Failed to load the house settings', error, { component: 'HouseSettings', action: 'load' })
			}
		},

		async saveHouseName() {
			if (!this.canSaveHouseName) {
				return
			}
			try {
				await this.houseStore.updateHouseName(this.houseName.trim())
				showSuccess(t('chorestrike', 'House name saved'))
			} catch (error) {
				showError(t('chorestrike', 'Failed to save house name'))
				logger.error('Failed to save house name', error, { component: 'HouseSettings', action: 'saveHouseName' })
			}
		},

		async saveHouseTimezone() {
			if (!this.canSaveHouseTimezone) {
				return
			}
			try {
				// Name is passed unchanged so the owner-gated PUT keeps it intact.
				await this.houseStore.updateHouseName(this.houseStore.house.name, this.houseTimezone)
				showSuccess(t('chorestrike', 'Timezone saved'))
			} catch (error) {
				showError(t('chorestrike', 'Failed to save timezone'))
				logger.error('Failed to save timezone', error, { component: 'HouseSettings', action: 'saveHouseTimezone' })
				// Re-sync the select with the persisted value.
				this.houseTimezone = this.houseStore.timezone
			}
		},

		async saveRanking() {
			if (!this.canSaveRanking) {
				return
			}
			try {
				await this.houseStore.updateRankingEnabled(this.rankingEnabled)
				showSuccess(this.rankingEnabled
					? t('chorestrike', 'Ranking enabled — every member decides on their own participation')
					: t('chorestrike', 'Ranking turned off for everyone'))
			} catch (error) {
				showError(t('chorestrike', 'Failed to save the ranking setting'))
				logger.error('Failed to save the ranking setting', error, { component: 'HouseSettings', action: 'saveRanking' })
				// Re-sync the checkbox with the persisted value.
				this.rankingEnabled = this.houseStore.rankingEnabled
			}
		},
	},
}
</script>

<style scoped>
/* The ranking checkbox aligns with the text inputs it shares the
 * input-group with (CS-PLAN-070 WP4 owner toggle). */
.ranking-toggle {
	width: 1.1rem;
	height: 1.1rem;
	margin: 0.65rem 0;
	accent-color: var(--color-primary-element);
	cursor: pointer;
}
</style>
