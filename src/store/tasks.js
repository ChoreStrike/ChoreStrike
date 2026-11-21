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

		// The definitions by id: every room task has one of its own (D-091-88), so a list never searches per row.
		tasksById: (state) => new Map(state.tasks.map((task) => [task.id, task])),

		getTaskById() {
			return (taskId) => this.tasksById.get(taskId)
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

		// CS-PLAN-063 pruned createTask and CS-PLAN-091 WP-B6 updateTask:
		// the one form creates and saves through the taskForm store, which
		// puts the saved definition back here (upsertTask).

		/**
		 * Put the definition a save or a creation answered into the cache;
		 * it invalidates every definition read that started before it.
		 * @param {object} task The definition as the server holds it now.
		 */
		upsertTask(task) {
			if (!task?.id) {
				return
			}
			this.requestSeq++
			const index = this.tasks.findIndex((entry) => entry.id === task.id)
			if (index >= 0) {
				this.tasks[index] = task
			} else {
				this.tasks.push(task)
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
