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
		// CS-PLAN-072 WP1: occurrence identity + progress revision of the
		// cached progress read — the toggle's stale-mutation guard.
		progressMeta: {}, // roomTaskId -> { occurrenceId, revision }
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
		 * Fetch progress for a room task's CURRENT occurrence
		 * (CS-PLAN-072 WP1).
		 *
		 * The endpoint returns `{ items, stats, occurrence }`: rows are
		 * occurrence-scoped and carry `occurrenceId` + `revision`; the
		 * resolved target block is cached as the toggle target. A GET is
		 * write-free — virtual agreed occurrences derive empty progress.
		 *
		 * @param {number} roomTaskId The room task ID
		 */
		async fetchRoomTaskProgress(roomTaskId) {
			this.loading = true
			this.error = null

			try {
				const data = await api.get(`/api/v1/checklists/progress/${roomTaskId}`)
				this.progress[roomTaskId] = data.items || []
				this.progressMeta[roomTaskId] = {
					occurrenceId: data.occurrence?.occurrenceId ?? null,
					revision: this.progress[roomTaskId].reduce((max, row) => Math.max(max, row.revision ?? 0), 0),
				}
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
				// A concurrent fetchTaskItems (row expansion) may already have
				// delivered the new item; replace it instead of rendering it twice.
				const existing = this.items[taskId].findIndex(item => item.id === data.id)
				if (existing >= 0) {
					this.items[taskId][existing] = data
				} else {
					this.items[taskId].push(data)
				}
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
		 * Toggle a checklist item's completion status on the room task's
		 * CURRENT occurrence (CS-PLAN-072 WP1).
		 *
		 * The request carries the cached occurrence identity and progress
		 * revision (stale-mutation guard); the endpoint returns
		 * `{ progress, stats, occurrence }` and the progress row is
		 * upserted into the cached list by its `checklistItemId`. A 409
		 * (stale revision) refetches the occurrence progress so the next
		 * toggle uses the current state.
		 *
		 * @param {number} roomTaskId The room task ID
		 * @param {number} itemId The item ID
		 */
		async toggleItem(roomTaskId, itemId) {
			this.loading = true
			this.error = null

			try {
				const meta = this.progressMeta[roomTaskId] ?? { occurrenceId: null, revision: null }
				const data = await api.post(`/api/v1/checklists/toggle/${roomTaskId}/${itemId}`, {
					occurrenceId: meta.occurrenceId,
					expectedRevision: meta.revision,
				})

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

				this.progressMeta[roomTaskId] = {
					occurrenceId: data?.occurrence?.occurrenceId ?? meta.occurrenceId,
					revision: this.progress[roomTaskId].reduce((max, entry) => Math.max(max, entry.revision ?? 0), 0),
				}

				return row
			} catch (error) {
				this.error = error.message
				logger.error('Failed to toggle checklist item', error, { store: 'checklist', action: 'toggleItem', roomTaskId, itemId })
				// Stale occurrence progress (409): refetch so the next
				// attempt carries the current revision.
				if (error?.status === 409) {
					try {
						await this.fetchRoomTaskProgress(roomTaskId)
					} catch (refetchError) {
						logger.warn('Checklist progress refetch after 409 failed', refetchError, { store: 'checklist', action: 'toggleItem', roomTaskId })
					}
				}
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
						if (item) {
							orderedItems.push(item)
						}
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
			this.progressMeta = {}
			this.loading = false
			this.error = null
		},
	},
})
