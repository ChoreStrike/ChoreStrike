/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
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

		getCompletionPercent: (state) => (roomTaskId) => {
			const progress = state.progress[roomTaskId] || []
			if (progress.length === 0) return 0
			const completed = progress.filter(p => p.isCompleted).length
			return Math.round((completed / progress.length) * 100)
		},

		isItemCompleted: (state) => (roomTaskId, itemId) => {
			const progress = state.progress[roomTaskId] || []
			const itemProgress = progress.find(p => p.itemId === itemId)
			return itemProgress?.isCompleted || false
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
				const response = await axios.get(`/apps/chorestrike/api/v1/checklists/task/${taskId}`)

				if (response.data.success) {
					this.items[taskId] = response.data.data
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch checklist items')
				}
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
		 * @param {number} roomTaskId The room task ID
		 */
		async fetchRoomTaskProgress(roomTaskId) {
			this.loading = true
			this.error = null

			try {
				const response = await axios.get(`/apps/chorestrike/api/v1/checklists/progress/${roomTaskId}`)

				if (response.data.success) {
					this.progress[roomTaskId] = response.data.data
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch checklist progress')
				}
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
		 * @param {object} itemData The item data { title, description, orderIndex }
		 */
		async addItem(taskId, itemData) {
			this.loading = true
			this.error = null

			try {
				const response = await axios.post(`/apps/chorestrike/api/v1/checklists/task/${taskId}`, itemData)

				if (response.data.success) {
					// Add to local state
					if (!this.items[taskId]) {
						this.items[taskId] = []
					}
					this.items[taskId].push(response.data.data)
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to add checklist item')
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to add checklist item', error, { store: 'checklist', action: 'addItem', taskId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update a checklist item
		 * @param {number} itemId The item ID
		 * @param {object} updates The updates { title, description }
		 */
		async updateItem(itemId, updates) {
			this.loading = true
			this.error = null

			try {
				const response = await axios.put(`/apps/chorestrike/api/v1/checklists/item/${itemId}`, updates)

				if (response.data.success) {
					// Update in local state
					for (const taskId in this.items) {
						const index = this.items[taskId].findIndex(item => item.id === itemId)
						if (index >= 0) {
							this.items[taskId][index] = response.data.data
							break
						}
					}
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to update checklist item')
				}
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
				const response = await axios.delete(`/apps/chorestrike/api/v1/checklists/item/${itemId}`)

				if (response.data.success) {
					// Remove from local state
					if (this.items[taskId]) {
						this.items[taskId] = this.items[taskId].filter(item => item.id !== itemId)
					}
				} else {
					throw new Error(response.data.error?.message || 'Failed to delete checklist item')
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
		 * @param {number} roomTaskId The room task ID
		 * @param {number} itemId The item ID
		 */
		async toggleItem(roomTaskId, itemId) {
			this.loading = true
			this.error = null

			try {
				const response = await axios.post(`/apps/chorestrike/api/v1/checklists/toggle/${roomTaskId}/${itemId}`)

				if (response.data.success) {
					// Update progress in local state
					if (!this.progress[roomTaskId]) {
						this.progress[roomTaskId] = []
					}

					const existingIndex = this.progress[roomTaskId].findIndex(p => p.itemId === itemId)
					if (existingIndex >= 0) {
						this.progress[roomTaskId][existingIndex] = response.data.data
					} else {
						this.progress[roomTaskId].push(response.data.data)
					}

					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to toggle checklist item')
				}
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
		 * @param {number} taskId The task ID
		 * @param {Array} itemIds Ordered array of item IDs
		 */
		async reorderItems(taskId, itemIds) {
			this.loading = true
			this.error = null

			try {
				const response = await axios.post('/apps/chorestrike/api/v1/checklists/reorder', {
					taskId,
					itemIds,
				})

				if (response.data.success) {
					// Update local order
					if (this.items[taskId]) {
						const orderedItems = []
						itemIds.forEach(id => {
							const item = this.items[taskId].find(i => i.id === id)
							if (item) orderedItems.push(item)
						})
						this.items[taskId] = orderedItems
					}
				} else {
					throw new Error(response.data.error?.message || 'Failed to reorder checklist items')
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
		 * Initialize checklist progress for a room task
		 * @param {number} roomTaskId The room task ID
		 */
		async initializeProgress(roomTaskId) {
			this.loading = true
			this.error = null

			try {
				const response = await axios.post(`/apps/chorestrike/api/v1/checklists/initialize/${roomTaskId}`)

				if (response.data.success) {
					this.progress[roomTaskId] = response.data.data
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to initialize checklist progress')
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to initialize checklist progress', error, { store: 'checklist', action: 'initializeProgress', roomTaskId })
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
