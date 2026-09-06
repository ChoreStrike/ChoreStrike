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
	}),

	getters: {
		activeTasks: (state) => state.tasks,

		getTaskById: (state) => (taskId) => {
			return state.tasks.find(task => task.id === taskId)
		},
	},

	actions: {
		/**
		 * Fetch all tasks
		 */
		async fetchTasks() {
			this.loading = true
			this.error = null

			try {
				const data = await api.get('/api/v1/tasks')
				this.tasks = data
			} catch (error) {
				this.error = error.message
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

				const taskIndex = this.tasks.findIndex(t => t.id === taskId)
				if (taskIndex >= 0) {
					this.tasks[taskIndex] = data
				}
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
			this.tasks = []
			this.loading = false
			this.error = null
		},
	},
})
