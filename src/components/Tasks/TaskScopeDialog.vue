<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<Modal :aria-label="t('chorestrike', 'Several rooms share this task')" @close="$emit('cancel')">
		<div class="scope-dialog" data-testid="task-scope-dialog">
			<h3>{{ t('chorestrike', 'Several rooms share this task') }}</h3>
			<p>{{ t('chorestrike', 'Change it only in this room, where it then becomes a task of its own, or in every room that shares it?') }}</p>
			<div class="scope-actions">
				<button type="button"
					class="primary"
					data-testid="task-scope-this-room"
					@click="$emit('choose', 'thisRoom')">
					{{ t('chorestrike', 'Only this room') }}
				</button>
				<button type="button" data-testid="task-scope-all-rooms" @click="$emit('choose', 'allRooms')">
					{{ t('chorestrike', 'All rooms') }}
				</button>
				<button type="button" data-testid="task-scope-cancel" @click="$emit('cancel')">
					{{ t('chorestrike', 'Cancel') }}
				</button>
			</div>
		</div>
	</Modal>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import Modal from '../Common/Modal.vue'

/**
 * The question of a save that changes a definition several rooms share
 * (FEAT-UX-043, form part): "only this room" splits off a copy for this
 * room, "all rooms" changes the shared one. History stays where it was
 * either way.
 */
defineEmits(['choose', 'cancel'])
</script>

<style scoped>
.scope-dialog {
	padding: 1rem;
}

.scope-dialog h3 {
	margin: 0 0 0.5rem;
}

.scope-actions {
	display: flex;
	flex-wrap: wrap;
	justify-content: flex-end;
	gap: 0.5rem;
	margin-top: 1rem;
}

.scope-actions button {
	min-height: 44px;
	padding: 0 1rem;
	border: 1px solid var(--color-border-dark);
	border-radius: 22px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.scope-actions .primary {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}
</style>
