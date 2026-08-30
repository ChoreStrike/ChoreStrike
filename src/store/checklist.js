/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { api } from '../services/api.js'
import { logger } from '../utils/logger.js'

/**
 * Store for managing task checklists (sub-tasks)
 * Feature 3: Sub-Checklists
 */
export const useChecklistStore = defineStore('checklist', {
	state: () => ({
		items: {}, // taskId -> items[]
		progress: {}, // roomTaskId -> progress[]
		loading: false,
		error: null,
	}),

	getters: {
		getTaskItems: (state) => (taskId) => {
			return state.items[taskId] || []
		},

		getRoomTaskProgress: (state) => (roomTaskId) => {
			return state.progress[roomTaskId] || []
		},
	},

	actions: {
		/**
		 * Fetch checklist items for a task
		 * @param {number} taskId The task ID
		 */
		async fetchTaskItems(taskId) {
			this.loading = true
			this.error = null

			try {
				const data = await api.get(`/api/v1/checklists/task/${taskId}`)
				this.items[taskId] = data
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch checklist items', error, { store: 'checklist', action: 'fetchTaskItems', taskId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch progress for a room task
		 *
		 * The endpoint returns `{ items, stats }`; only the row list is
		 * cached (rows carry `checklistItemId` + `isChecked`).
		 *
		 * @param {number} roomTaskId The room task ID
		 */
		async fetchRoomTaskProgress(roomTaskId) {
			this.loading = true
			this.error = null

			try {
				const data = await api.get(`/api/v1/checklists/progress/${roomTaskId}`)
				this.progress[roomTaskId] = data.items || []
				return this.progress[roomTaskId]
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch checklist progress', error, { store: 'checklist', action: 'fetchRoomTaskProgress', roomTaskId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Add a checklist item to a task
		 * @param {number} taskId The task ID
		 * @param {object} itemData The item data (backend contract)
		 * @param {string} itemData.label Item label
		 * @param {number} [itemData.sortOrder] Explicit sort position
		 */
		async addItem(taskId, itemData) {
			const label = itemData?.label
			if (!label || String(label).trim() === '') {
				throw new Error('Checklist item label is required')
			}

			this.loading = true
			this.error = null

			try {
				const data = await api.post(`/api/v1/checklists/task/${taskId}`, {
					label,
					sortOrder: itemData.sortOrder,
				})

				if (!this.items[taskId]) {
					this.items[taskId] = []
				}
				this.items[taskId].push(data)
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to add checklist item', error, { store: 'checklist', action: 'addItem', taskId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update a checklist item label
		 * @param {number} itemId The item ID
		 * @param {string} label The new label
		 */
		async updateItem(itemId, label) {
			if (!label || String(label).trim() === '') {
				throw new Error('Checklist item label is required')
			}

			this.loading = true
			this.error = null

			try {
				const data = await api.put(`/api/v1/checklists/item/${itemId}`, { label })

				for (const taskId in this.items) {
					const index = this.items[taskId].findIndex(item => item.id === itemId)
					if (index >= 0) {
						this.items[taskId][index] = data
						break
					}
				}
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to update checklist item', error, { store: 'checklist', action: 'updateItem', itemId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Delete a checklist item
		 * @param {number} itemId The item ID
		 * @param {number} taskId The task ID (for local state update)
		 */
		async deleteItem(itemId, taskId) {
			this.loading = true
			this.error = null

			try {
				await api.delete(`/api/v1/checklists/item/${itemId}`)

				if (this.items[taskId]) {
					this.items[taskId] = this.items[taskId].filter(item => item.id !== itemId)
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to delete checklist item', error, { store: 'checklist', action: 'deleteItem', itemId, taskId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Toggle a checklist item's completion status
		 *
		 * The endpoint returns `{ progress, stats }`; the progress row is
		 * upserted into the cached list by its `checklistItemId`.
		 *
		 * @param {number} roomTaskId The room task ID
		 * @param {number} itemId The item ID
		 */
		async toggleItem(roomTaskId, itemId) {
			this.loading = true
			this.error = null

			try {
				const data = await api.post(`/api/v1/checklists/toggle/${roomTaskId}/${itemId}`)

				if (!this.progress[roomTaskId]) {
					this.progress[roomTaskId] = []
				}

				const row = data?.progress || data
				const existingIndex = this.progress[roomTaskId]
					.findIndex(p => p.checklistItemId === row.checklistItemId)
				if (existingIndex >= 0) {
					this.progress[roomTaskId][existingIndex] = row
				} else {
					this.progress[roomTaskId].push(row)
				}

				return row
			} catch (error) {
				this.error = error.message
				logger.error('Failed to toggle checklist item', error, { store: 'checklist', action: 'toggleItem', roomTaskId, itemId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Reorder checklist items
		 *
		 * Sends the persisted order as an `itemOrders` map
		 * (itemId → position), the shape the controller expects.
		 *
		 * @param {number} taskId The task ID
		 * @param {Array<number>} itemIds Ordered array of item IDs
		 */
		async reorderItems(taskId, itemIds) {
			this.loading = true
			this.error = null

			try {
				await api.post('/api/v1/checklists/reorder', {
					itemOrders: Object.fromEntries(itemIds.map((id, index) => [id, index])),
				})

				if (this.items[taskId]) {
					const orderedItems = []
					itemIds.forEach(id => {
						const item = this.items[taskId].find(i => i.id === id)
						if (item) orderedItems.push(item)
					})
					this.items[taskId] = orderedItems
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to reorder checklist items', error, { store: 'checklist', action: 'reorderItems', taskId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Clear error state
		 */
		clearError() {
			this.error = null
		},

		/**
		 * Reset store state
		 */
		reset() {
			this.items = {}
			this.progress = {}
			this.loading = false
			this.error = null
		},
	},
})
