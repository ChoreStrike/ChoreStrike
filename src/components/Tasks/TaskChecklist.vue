<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="task-checklist" data-testid="task-checklist">
		<div class="checklist-header">
			<h4>{{ t('chorestrike', 'Sub-Tasks') }}</h4>
			<span class="progress-badge" :class="progressClass">
				{{ completedCount }}/{{ items.length }}
			</span>
		</div>

		<!-- Progress Bar -->
		<div class="progress-bar-container">
			<div class="progress-bar" :style="{ width: progressPercent + '%' }" :class="progressClass" />
		</div>

		<!-- Checklist Items -->
		<ul class="checklist-items">
			<li
				v-for="item in sortedItems"
				:key="item.id"
				class="checklist-item"
				:data-testid="'checklist-item-' + item.id"
				:draggable="canEdit"
				@dragstart="dragStart($event, item)"
				@dragover.prevent
				@drop="drop($event, item)">
				<!-- Checkbox -->
				<label class="item-checkbox" :class="{ 'is-completed': isCompleted(item.id) }">
					<input
						type="checkbox"
						:checked="isCompleted(item.id)"
						:disabled="readonly"
						@change="toggleItem(item.id)">
					<span class="checkmark" />
				</label>

				<!-- Item Content -->
				<div class="item-content" :class="{ 'is-completed': isCompleted(item.id) }">
					<span class="item-title">{{ item.title }}</span>
					<span v-if="item.description" class="item-description">
						{{ item.description }}
					</span>
				</div>

				<!-- Item Actions -->
				<div v-if="canEdit" class="item-actions">
					<button
						class="action-btn edit"
						:title="t('chorestrike', 'Edit')"
						@click="editItem(item)">
						<Icon name="Edit2" :size="14" />
					</button>
					<button
						class="action-btn delete"
						:title="t('chorestrike', 'Delete')"
						@click="deleteItem(item.id)">
						<Icon name="Trash2" :size="14" />
					</button>
					<button
						class="action-btn drag"
						:title="t('chorestrike', 'Drag to reorder')"
						@mousedown="dragStart($event, item)">
						<Icon name="GripVertical" :size="14" />
					</button>
				</div>
			</li>
		</ul>

		<!-- Add Item Form -->
		<div v-if="canEdit" class="add-item-form">
			<div v-if="!isAdding" class="add-item-trigger" @click="startAdd">
				<Icon name="Plus" :size="16" />
				<span>{{ t('chorestrike', 'Add sub-task') }}</span>
			</div>

			<div v-else class="add-item-inputs">
				<input
					ref="newItemInput"
					v-model="newItem.title"
					type="text"
					class="form-input"
					:placeholder="t('chorestrike', 'Sub-task title...')"
					@keyup.enter="saveNewItem"
					@keyup.esc="cancelAdd">
				<textarea
					v-model="newItem.description"
					class="form-input"
					rows="2"
					:placeholder="t('chorestrike', 'Description (optional)...')" />
				<div class="form-actions">
					<button class="btn-secondary" @click="cancelAdd">
						{{ t('chorestrike', 'Cancel') }}
					</button>
					<button class="btn-primary" :disabled="!newItem.title" @click="saveNewItem">
						{{ t('chorestrike', 'Add') }}
					</button>
				</div>
			</div>
		</div>

		<!-- Edit Item Modal -->
		<Modal v-if="editingItem" @close="cancelEdit">
			<div class="edit-item-modal">
				<h4>{{ t('chorestrike', 'Edit Sub-Task') }}</h4>
				<input
					v-model="editingItem.title"
					type="text"
					class="form-input"
					:placeholder="t('chorestrike', 'Title')">
				<textarea
					v-model="editingItem.description"
					class="form-input"
					rows="3"
					:placeholder="t('chorestrike', 'Description')" />
				<div class="form-actions">
					<button class="btn-secondary" @click="cancelEdit">
						{{ t('chorestrike', 'Cancel') }}
					</button>
					<button class="btn-primary" :disabled="!editingItem.title" @click="saveEdit">
						{{ t('chorestrike', 'Save') }}
					</button>
				</div>
			</div>
		</Modal>

		<!-- Delete Item Confirmation -->
		<ConfirmDialog
			v-if="itemToDelete !== null"
			:title="t('chorestrike', 'Delete this sub-task?')"
			@confirm="confirmDeleteItem"
			@cancel="itemToDelete = null" />
	</div>
</template>

<script>
import { computed, ref, nextTick } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import Icon from '../Common/Icon.vue'
import Modal from '../Common/Modal.vue'
import ConfirmDialog from '../Common/ConfirmDialog.vue'

export default {
	name: 'TaskChecklist',

	components: {
		Icon,
		Modal,
		ConfirmDialog,
	},

	props: {
		items: {
			type: Array,
			default: () => [],
		},
		progress: {
			type: Array,
			default: () => [],
		},
		canEdit: {
			type: Boolean,
			default: false,
		},
		readonly: {
			type: Boolean,
			default: false,
		},
		roomTaskId: {
			type: Number,
			default: null,
		},
	},

	emits: ['toggle', 'add', 'update', 'delete', 'reorder'],

	setup(props, { emit }) {
		const isAdding = ref(false)
		const newItemInput = ref(null)
		const newItem = ref({ title: '', description: '' })
		const editingItem = ref(null)
		const draggedItem = ref(null)
		const itemToDelete = ref(null)

		// Completed item IDs from progress
		const completedIds = computed(() => {
			return props.progress
				?.filter(p => p.isCompleted)
				?.map(p => p.itemId) || []
		})

		const isCompleted = (itemId) => {
			return completedIds.value.includes(itemId)
		}

		const completedCount = computed(() => {
			return completedIds.value.length
		})

		const progressPercent = computed(() => {
			if (props.items.length === 0) return 0
			return Math.round((completedCount.value / props.items.length) * 100)
		})

		const progressClass = computed(() => {
			if (progressPercent.value === 100) return 'complete'
			if (progressPercent.value >= 50) return 'half'
			return 'start'
		})

		const sortedItems = computed(() => {
			// Sort by orderIndex if available
			return [...props.items].sort((a, b) => {
				const orderA = a.orderIndex ?? a.id
				const orderB = b.orderIndex ?? b.id
				return orderA - orderB
			})
		})

		const toggleItem = (itemId) => {
			emit('toggle', { itemId, roomTaskId: props.roomTaskId })
		}

		const startAdd = () => {
			isAdding.value = true
			newItem.value = { title: '', description: '' }
			nextTick(() => {
				newItemInput.value?.focus()
			})
		}

		const cancelAdd = () => {
			isAdding.value = false
			newItem.value = { title: '', description: '' }
		}

		const saveNewItem = () => {
			if (!newItem.value.title.trim()) return

			emit('add', {
				title: newItem.value.title.trim(),
				description: newItem.value.description.trim(),
				roomTaskId: props.roomTaskId,
			})

			cancelAdd()
		}

		const editItem = (item) => {
			editingItem.value = { ...item }
		}

		const cancelEdit = () => {
			editingItem.value = null
		}

		const saveEdit = () => {
			if (!editingItem.value?.title.trim()) return

			emit('update', {
				id: editingItem.value.id,
				title: editingItem.value.title.trim(),
				description: editingItem.value.description.trim(),
			})

			cancelEdit()
		}

		const deleteItem = (itemId) => {
			itemToDelete.value = itemId
		}

		const confirmDeleteItem = () => {
			const itemId = itemToDelete.value
			if (itemId === null) return
			itemToDelete.value = null
			emit('delete', { itemId, roomTaskId: props.roomTaskId })
		}

		// Drag and drop for reordering
		const dragStart = (event, item) => {
			draggedItem.value = item
			event.dataTransfer.effectAllowed = 'move'
		}

		const drop = (event, targetItem) => {
			event.preventDefault()

			if (!draggedItem.value || draggedItem.value.id === targetItem.id) return

			const reordered = sortedItems.value.map(item => item.id)
			const fromIndex = reordered.indexOf(draggedItem.value.id)
			const toIndex = reordered.indexOf(targetItem.id)

			reordered.splice(fromIndex, 1)
			reordered.splice(toIndex, 0, draggedItem.value.id)

			emit('reorder', { itemIds: reordered, roomTaskId: props.roomTaskId })
			draggedItem.value = null
		}

		return {
			isAdding,
			newItemInput,
			newItem,
			editingItem,
			sortedItems,
			isCompleted,
			completedCount,
			progressPercent,
			progressClass,
			toggleItem,
			startAdd,
			cancelAdd,
			saveNewItem,
			editItem,
			cancelEdit,
			saveEdit,
			deleteItem,
			itemToDelete,
			confirmDeleteItem,
			dragStart,
			drop,
			t,
		}
	},
}
</script>

<style scoped>
.task-checklist {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 1rem;
	margin-top: 1rem;
}

.checklist-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 0.75rem;
}

.checklist-header h4 {
	margin: 0;
	font-size: 0.95rem;
	font-weight: 600;
}

.progress-badge {
	font-size: 0.8rem;
	padding: 0.25rem 0.5rem;
	border-radius: 12px;
	background: var(--color-background-darker);
}

.progress-badge.complete {
	background: var(--color-success);
	color: white;
}

.progress-badge.half {
	background: var(--color-warning);
	color: white;
}

.progress-bar-container {
	height: 6px;
	background: var(--color-background-darker);
	border-radius: 3px;
	margin-bottom: 1rem;
	overflow: hidden;
}

.progress-bar {
	height: 100%;
	background: var(--color-primary);
	border-radius: 3px;
	transition: width 0.3s ease;
}

.progress-bar.complete {
	background: var(--color-success);
}

.progress-bar.half {
	background: var(--color-warning);
}

.checklist-items {
	list-style: none;
	margin: 0;
	padding: 0;
}

.checklist-item {
	display: flex;
	align-items: flex-start;
	gap: 0.75rem;
	padding: 0.75rem;
	background: var(--color-main-background);
	border-radius: 6px;
	margin-bottom: 0.5rem;
	transition: background-color 0.2s;
}

.checklist-item:hover {
	background: var(--color-background-hover);
}

.checklist-item[draggable="true"] {
	cursor: grab;
}

.checklist-item[draggable="true"]:active {
	cursor: grabbing;
}

.item-checkbox {
	flex-shrink: 0;
	position: relative;
	width: 20px;
	height: 20px;
	cursor: pointer;
}

.item-checkbox input {
	position: absolute;
	opacity: 0;
	width: 0;
	height: 0;
}

.checkmark {
	position: absolute;
	top: 0;
	inset-inline-start: 0;
	width: 20px;
	height: 20px;
	border: 2px solid var(--color-border);
	border-radius: 4px;
	transition: all 0.2s;
}

.item-checkbox input:checked + .checkmark {
	background: var(--color-primary);
	border-color: var(--color-primary);
}

.item-checkbox input:checked + .checkmark::after {
	content: '';
	position: absolute;
	inset-inline-start: 5px;
	top: 1px;
	width: 5px;
	height: 10px;
	border: solid white;
	border-width: 0 2px 2px 0;
	transform: rotate(45deg);
}

.item-content {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.item-content.is-completed {
	opacity: 0.6;
}

.item-title {
	font-size: 0.95rem;
	color: var(--color-main-text);
}

.item-content.is-completed .item-title {
	text-decoration: line-through;
}

.item-description {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.item-actions {
	display: flex;
	gap: 0.25rem;
	opacity: 0;
	transition: opacity 0.2s;
}

.checklist-item:hover .item-actions {
	opacity: 1;
}

.action-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	border: none;
	border-radius: 4px;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	transition: all 0.2s;
}

.action-btn:hover {
	background: var(--color-background-darker);
	color: var(--color-main-text);
}

.action-btn.edit:hover {
	color: var(--color-primary);
}

.action-btn.delete:hover {
	color: var(--color-error);
}

.action-btn.drag {
	cursor: grab;
}

.add-item-form {
	margin-top: 0.75rem;
}

.add-item-trigger {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.75rem;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	border-radius: 6px;
	transition: all 0.2s;
}

.add-item-trigger:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.add-item-inputs {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.form-input {
	padding: 0.6rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.95rem;
}

.form-input:focus {
	border-color: var(--color-primary);
	outline: none;
}

.form-actions {
	display: flex;
	gap: 0.5rem;
	justify-content: flex-end;
}

.btn-primary,
.btn-secondary {
	padding: 0.5rem 1rem;
	border: none;
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.9rem;
	transition: opacity 0.2s;
}

.btn-primary {
	background: var(--color-primary);
	color: white;
}

.btn-primary:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.btn-secondary {
	background: var(--color-background-darker);
	color: var(--color-main-text);
}

.edit-item-modal {
	padding: 1.5rem;
	min-width: 300px;
}

.edit-item-modal h4 {
	margin: 0 0 1rem 0;
}

.edit-item-modal .form-input {
	margin-bottom: 0.75rem;
}
</style>
