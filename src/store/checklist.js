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
		// Cycle key + progress revision of the cached progress read — the
		// toggle's stale-mutation guard (ADR-021 decision 3).
		progressMeta: {}, // roomTaskId -> { cycleKey, revision }
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
		 * Fetch the sub-task ticks of a room task's CURRENT cycle.
		 *
		 * The endpoint returns `{ items, stats, cycle }`: rows belong to
		 * the cycle and carry its `cycleKey` + `revision`; the cycle key is
		 * cached as the toggle target. A GET is write-free — a new cycle
		 * starts without ticks.
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
					cycleKey: data.cycle?.cycleKey ?? null,
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
		 * Toggle a sub-task of the room task's CURRENT cycle.
		 *
		 * The request carries the cached cycle key and progress revision
		 * (stale-mutation guard); the endpoint returns
		 * `{ progress, stats, cycle }` and the progress row is upserted
		 * into the cached list by its `checklistItemId`. A 409 (the cycle
		 * closed or the revision moved on) refetches the progress so the
		 * next toggle uses the current state.
		 *
		 * @param {number} roomTaskId The room task ID
		 * @param {number} itemId The item ID
		 */
		async toggleItem(roomTaskId, itemId) {
			this.loading = true
			this.error = null

			try {
				const meta = this.progressMeta[roomTaskId] ?? { cycleKey: null, revision: null }
				const data = await api.post(`/api/v1/checklists/toggle/${roomTaskId}/${itemId}`, {
					cycleKey: meta.cycleKey,
					expectedRevision: meta.revision,
				})

				if (!this.progress[roomTaskId]) {
					this.progress[roomTaskId] = []
				}

				const row = data?.progress || data
				const existingIndex = this.progress[roomTaskId]
					.findIndex((p) => p.checklistItemId === row.checklistItemId)
				if (existingIndex >= 0) {
					this.progress[roomTaskId][existingIndex] = row
				} else {
					this.progress[roomTaskId].push(row)
				}

				this.progressMeta[roomTaskId] = {
					cycleKey: data?.cycle?.cycleKey ?? meta.cycleKey,
					revision: this.progress[roomTaskId].reduce((max, entry) => Math.max(max, entry.revision ?? 0), 0),
				}

				return row
			} catch (error) {
				this.error = error.message
				logger.error('Failed to toggle checklist item', error, { store: 'checklist', action: 'toggleItem', roomTaskId, itemId })
				// Stale progress (409): refetch so the next
				// attempt carries the current revision.
				if (error?.status === 409) {
					try {
						await this.fetchRoomTaskProgress(roomTaskId)
					} catch (refetchError) {
						logger.warn('Checklist progress refetch after 409 failed', { store: 'checklist', action: 'toggleItem', roomTaskId, error: refetchError })
					}
				}
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * The sub-tasks a save or a creation of the one form answered
		 * (CS-PLAN-091 WP-B6): they replace the cached list of the task.
		 * @param {number} taskId The definition.
		 * @param {object[]} items Its sub-tasks in their order.
		 */
		setTaskItems(taskId, items) {
			this.items = { ...this.items, [taskId]: items ?? [] }
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
