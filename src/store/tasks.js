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

		tasksByCategory: (state) => {
			const tasksByCategory = {}
			state.tasks.forEach(task => {
				if (!tasksByCategory[task.category]) {
					tasksByCategory[task.category] = []
				}
				tasksByCategory[task.category].push(task)
			})
			return tasksByCategory
		},

		highPriorityTasks: (state) => state.tasks.filter(task => task.priority === 3),

		mediumPriorityTasks: (state) => state.tasks.filter(task => task.priority === 2),

		lowPriorityTasks: (state) => state.tasks.filter(task => task.priority === 1),
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

		/**
		 * Create a new task
		 * @param {object} taskData Payload used to create the task.
		 */
		async createTask(taskData) {
			this.loading = true
			this.error = null

			try {
				const data = await api.post('/api/v1/tasks', taskData)
				this.tasks.push(data)
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to create task', error, { store: 'tasks', action: 'createTask' })
				throw error
			} finally {
				this.loading = false
			}
		},

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
		 * Delete task
		 * @param {number} taskId Task identifier to delete.
		 */
		async deleteTask(taskId) {
			this.loading = true
			this.error = null

			try {
				await api.delete(`/api/v1/tasks/${taskId}`)

				this.tasks = this.tasks.filter(t => t.id !== taskId)
			} catch (error) {
				this.error = error.message
				logger.error('Failed to delete task', error, { store: 'tasks', action: 'deleteTask', taskId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Search tasks by name
		 * @param {string} query Search text value.
		 */
		async searchTasks(query) {
			if (!query || query.trim() === '') {
				return this.tasks
			}

			this.loading = true
			this.error = null

			try {
				const data = await api.get('/api/v1/tasks/search', { params: { query } })
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to search tasks', error, { store: 'tasks', action: 'searchTasks', query })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Get tasks by category
		 * @param {string} category Category key to filter by.
		 */
		async fetchTasksByCategory(category) {
			this.loading = true
			this.error = null

			try {
				const data = await api.get(`/api/v1/tasks/category/${encodeURIComponent(category)}`)
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch tasks by category', error, { store: 'tasks', action: 'fetchTasksByCategory', category })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Get task categories
		 */
		async fetchTaskCategories() {
			this.loading = true
			this.error = null

			try {
				const data = await api.get('/api/v1/tasks/categories')
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch task categories', error, { store: 'tasks', action: 'fetchTaskCategories' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Initialize tasks
		 */
		async initializeTasks() {
			await this.fetchTasks()
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
