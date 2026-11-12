<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="danger-zone-settings cs-settings-form">
		<div class="setting-card danger">
			<div class="danger-item">
				<div class="danger-info">
					<h3>{{ t('chorestrike', 'Reset House') }}</h3>
					<p>{{ t('chorestrike', 'Resets all rooms to a clean state and restores house health. Setup, tasks, agreements, completion history, finances, reward requests, and achievements are kept. This cannot be undone.') }}</p>
				</div>
				<button
					class="danger-button"
					:disabled="houseStore.loading"
					@click="confirmResetHouse">
					{{ t('chorestrike', 'Reset') }}
				</button>
			</div>
		</div>

		<!-- Reset Confirmation Modal -->
		<Modal v-if="showResetModal" :aria-label="t('chorestrike', 'Reset House?')" @close="showResetModal = false">
			<div class="modal-content danger-modal cs-settings-form">
				<div class="warning-icon">
					<Icon name="AlertTriangle" :size="48" />
				</div>
				<h3>{{ t('chorestrike', 'Reset House?') }}</h3>
				<p>{{ t('chorestrike', 'All rooms return to their initial clean state and house health is restored. Setup, structure, tasks, agreements, completion history, finances, reward requests, and achievements are kept.') }}</p>
				<div class="confirmation-input">
					<label>{{ t('chorestrike', 'Type "RESET" to confirm') }}</label>
					<input
						v-model="resetConfirmation"
						type="text"
						class="text-input"
						:placeholder="t('chorestrike', 'RESET')">
				</div>
				<div class="modal-actions">
					<button class="secondary-button" @click="showResetModal = false">
						{{ t('chorestrike', 'Cancel') }}
					</button>
					<button
						class="danger-button"
						:disabled="resetConfirmation !== 'RESET' || houseStore.loading"
						@click="resetHouse">
						{{ t('chorestrike', 'Reset House') }}
					</button>
				</div>
			</div>
		</Modal>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import Icon from '../Common/Icon.vue'
import Modal from '../Common/Modal.vue'
import { useHouseStore } from '../../store/house.js'
import { useRoomsStore } from '../../store/rooms.js'
import { logger } from '../../utils/logger.js'

/**
 * The danger zone (household settings, owner only): the room and
 * house-health reset behind a typed confirmation (CS-PLAN-081 H4).
 */
export default {
	name: 'DangerZoneSettings',

	components: { Icon, Modal },

	setup() {
		return { houseStore: useHouseStore(), roomsStore: useRoomsStore() }
	},

	data() {
		return { showResetModal: false, resetConfirmation: '' }
	},

	methods: {
		t,

		confirmResetHouse() {
			this.resetConfirmation = ''
			this.showResetModal = true
		},

		async resetHouse() {
			if (this.resetConfirmation !== 'RESET') {
				return
			}
			try {
				await this.houseStore.resetHouse()
			} catch (error) {
				showError(t('chorestrike', 'Failed to reset house'))
				logger.error('Failed to reset house', error, { component: 'DangerZoneSettings', action: 'resetHouse' })
				return
			}
			showSuccess(t('chorestrike', 'Rooms and house health were reset'))
			this.resetConfirmation = ''
			this.showResetModal = false
			// The reset changed the rooms and the house: read both again. A
			// failed read does not undo the reset; the stores log it.
			await Promise.allSettled([this.houseStore.fetchHouse(), this.roomsStore.fetchFloorsWithRooms()])
		},
	},
}
</script>

<style scoped>
.danger-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 1rem;
}

.danger-info h3 {
	margin: 0 0 0.25rem 0;
	font-size: 1rem;
	color: var(--color-error-text);
}

.danger-info p {
	margin: 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.modal-content.danger-modal {
	text-align: center;
}

.warning-icon {
	color: var(--color-error-text);
	margin-bottom: 1rem;
}

.confirmation-input {
	margin: 1.5rem 0;
	text-align: start;
}

.confirmation-input label {
	display: block;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	margin-bottom: 0.5rem;
}

@media (max-width: 768px) {
	.danger-item {
		flex-direction: column;
		align-items: flex-start;
	}

	.danger-item .danger-button {
		width: 100%;
	}
}
</style>
