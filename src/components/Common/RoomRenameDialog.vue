<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<Modal persistent
		:aria-label="t('chorestrike', 'Rename Room')"
		@close="$emit('close')">
		<div class="modal-content">
			<h3>{{ t('chorestrike', 'Rename Room') }}</h3>
			<div class="form-group">
				<label for="room-rename-input">{{ t('chorestrike', 'Room Name') }}</label>
				<input
					id="room-rename-input"
					v-model="name"
					type="text"
					class="text-input"
					data-testid="room-rename-input"
					@keyup.enter="save">
			</div>
			<div class="modal-actions cs-dialog-actions">
				<button class="secondary-button" @click="$emit('close')">
					{{ t('chorestrike', 'Cancel') }}
				</button>
				<button
					class="primary-button"
					data-testid="room-rename-save"
					:disabled="!canSave"
					@click="save">
					{{ t('chorestrike', 'Save') }}
				</button>
			</div>
		</div>
	</Modal>
</template>

<script setup>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import Modal from './Modal.vue'
import { useRoomsStore } from '../../store/rooms.js'
import { logger } from '../../utils/logger.js'

/**
 * Owner-only room rename, shared by the room view and the Floors & Rooms
 * list in Settings (CS-PLAN-042, FEAT-UX-049).
 */
const props = defineProps({
	room: { type: Object, required: true },
})
const emit = defineEmits(['close'])

const roomsStore = useRoomsStore()
const name = ref(props.room.name)
const saving = ref(false)

const trimmedName = computed(() => name.value.trim())
const canSave = computed(() => trimmedName.value !== '' && trimmedName.value !== props.room.name && !saving.value)

/**
 * Save the new name and close; a refused name keeps the draft.
 */
async function save() {
	if (!canSave.value) {
		return
	}
	saving.value = true
	try {
		const room = await roomsStore.updateRoomName(props.room.id, trimmedName.value)
		// FEAT-UX-044: a taken name comes back numbered.
		showSuccess(room && room.name !== trimmedName.value
			? t('chorestrike', 'Name already taken — room renamed to "{name}"', { name: room.name })
			: t('chorestrike', 'Room renamed'))
		emit('close')
	} catch (error) {
		showError(t('chorestrike', 'Failed to rename room'))
		logger.error('Failed to rename room', error, { component: 'RoomRenameDialog', action: 'save', roomId: props.room.id })
	} finally {
		saving.value = false
	}
}
</script>

<style scoped>
.modal-content {
	padding: 1rem;
}

.modal-content h3 {
	margin: 0 0 1rem 0;
	font-size: 1.2rem;
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	margin-bottom: 1rem;
}

.form-group label {
	font-size: 0.9rem;
	font-weight: 500;
	color: var(--color-main-text);
}

.text-input {
	padding: 0.6rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.95rem;
}

.text-input:focus {
	outline: none;
	border-color: var(--color-primary-element);
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 0.75rem;
	margin-top: 1rem;
}

.primary-button,
.secondary-button {
	padding: 0.6rem 1rem;
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.9rem;
}

.primary-button {
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
}

.primary-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.secondary-button {
	background: var(--color-background-dark);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
}
</style>
