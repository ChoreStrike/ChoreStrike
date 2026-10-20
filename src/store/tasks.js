/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { api } from '../services/api.js'
import { logger } from '../utils/logger.js'

export const useTasksStore = defineStore('tasks', {
	state: () => ({
		tasks: [],
		loading: false,
		error: null,
		// CS-PLAN-066 §3.3: definition reads are generation-guarded; every
		// accepted definition mutation invalidates pre-mutation reads and
		// reset() discards in-flight results.
		requestSeq: 0,
	}),

	getters: {
		activeTasks: (state) => state.tasks,

		getTaskById: (state) => (taskId) => {
			return state.tasks.find((task) => task.id === taskId)
		},
	},

	actions: {
		/**
		 * Fetch all tasks
		 */
		async fetchTasks() {
			const seq = ++this.requestSeq
			this.loading = true
			this.error = null

			try {
				const data = await api.get('/api/v1/tasks')
				if (seq !== this.requestSeq) {
					logger.debug('Dropping superseded definition read', { store: 'tasks', action: 'fetchTasks' })
					return this.tasks
				}
				this.tasks = data
				return data
			} catch (error) {
				if (seq === this.requestSeq) {
					this.error = error.message
				}
				logger.error('Failed to fetch tasks', error, { store: 'tasks', action: 'fetchTasks' })
				throw error
			} finally {
				this.loading = false
			}
		},

		// CS-PLAN-063: the former createTask action was pruned with the
		// definition-only POST /tasks endpoint — creation runs through the
		// atomic aggregate (roomTasksStore.createTaskAggregate).

		/**
		 * Update task
		 * @param {number} taskId Task identifier to update.
		 * @param {object} updates Partial task data to apply.
		 */
		async updateTask(taskId, updates) {
			this.loading = true
			this.error = null

			try {
				const data = await api.put(`/api/v1/tasks/${taskId}`, updates)

				// Accepted mutation invalidates pre-mutation definition reads
				// (CS-PLAN-066 §3.3) — the authoritative row lands here.
				this.requestSeq++
				const taskIndex = this.tasks.findIndex((t) => t.id === taskId)
				if (taskIndex >= 0) {
					this.tasks[taskIndex] = data
				}
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to update task', error, { store: 'tasks', action: 'updateTask', taskId })
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
			this.requestSeq++
			this.tasks = []
			this.loading = false
			this.error = null
		},
	},
})
