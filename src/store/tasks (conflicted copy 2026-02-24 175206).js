import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'

export const useTasksStore = defineStore('tasks', {
	state: () => ({
		tasks: [],
		templates: [],
		roomTasks: [],
		loading: false,
		error: null,
	}),

	getters: {
		activeTasks: (state) => state.tasks.filter(task => !task.isTemplate),

		taskTemplates: (state) => state.tasks.filter(task => task.isTemplate),

		getTaskById: (state) => (taskId) => {
			return state.tasks.find(task => task.id === taskId)
		},

		getRoomTaskById: (state) => (roomTaskId) => {
			return state.roomTasks.find(roomTask => roomTask.id === roomTaskId)
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

		// Room task getters
		activeRoomTasks: (state) => state.roomTasks.filter(roomTask => roomTask.isActive),

		overdueRoomTasks: (state) => {
			return state.roomTasks.filter(roomTask => {
				return roomTask.isActive && new Date(roomTask.nextDue) < new Date()
			})
		},

		dueTodayRoomTasks: (state) => {
			const today = new Date()
			today.setHours(0, 0, 0, 0)
			const tomorrow = new Date(today)
			tomorrow.setDate(tomorrow.getDate() + 1)

			return state.roomTasks.filter(roomTask => {
				if (!roomTask.isActive) return false
				const dueDate = new Date(roomTask.nextDue)
				return dueDate >= today && dueDate < tomorrow
			})
		},

		dueSoonRoomTasks: (state) => {
			const now = new Date()
			const future = new Date()
			future.setDate(future.getDate() + 3)

			return state.roomTasks.filter(roomTask => {
				if (!roomTask.isActive) return false
				const dueDate = new Date(roomTask.nextDue)
				return dueDate >= now && dueDate <= future
			})
		},

		roomTasksByRoom: (state) => {
			const tasksByRoom = {}
			state.roomTasks.forEach(roomTask => {
				if (!tasksByRoom[roomTask.roomId]) {
					tasksByRoom[roomTask.roomId] = []
				}
				tasksByRoom[roomTask.roomId].push(roomTask)
			})
			return tasksByRoom
		},

		roomTasksByUser: (state) => {
			const tasksByUser = {}
			state.roomTasks.forEach(roomTask => {
				const userId = roomTask.assignedToUserId || 'unassigned'
				if (!tasksByUser[userId]) {
					tasksByUser[userId] = []
				}
				tasksByUser[userId].push(roomTask)
			})
			return tasksByUser
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
				const response = await axios.get('/apps/chorestrike/api/v1/tasks')

				if (response.data.success) {
					this.tasks = response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch tasks')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to fetch tasks:', error)
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch task templates
		 */
		async fetchTaskTemplates() {
			this.loading = true
			this.error = null

			try {
				const response = await axios.get('/apps/chorestrike/api/v1/tasks/templates')

				if (response.data.success) {
					this.templates = response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch task templates')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to fetch task templates:', error)
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch room tasks for a room
		 * @param {number} roomId Room identifier to load assignments for.
		 * @param {boolean} activeOnly Whether only active room tasks should be returned.
		 */
		async fetchRoomTasks(roomId, activeOnly = true) {
			this.loading = true
			this.error = null

			try {
				const response = await axios.get(`/apps/chorestrike/api/v1/room-tasks?roomId=${roomId}&activeOnly=${activeOnly}`)

				if (response.data.success) {
					// Update room tasks in state, removing old ones for this room
					this.roomTasks = this.roomTasks.filter(roomTask => roomTask.roomId !== roomId)
					this.roomTasks.push(...response.data.data)
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch room tasks')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to fetch room tasks:', error)
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
				const response = await axios.post('/apps/chorestrike/api/v1/tasks', taskData)

				if (response.data.success) {
					this.tasks.push(response.data.data)
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to create task')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to create task:', error)
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create task from template
		 * @param {number} templateId Template identifier used as source.
		 */
		async createTaskFromTemplate(templateId) {
			this.loading = true
			this.error = null

			try {
				const response = await axios.post(`/apps/chorestrike/api/v1/tasks/create-from-template/${templateId}`)

				if (response.data.success) {
					this.tasks.push(response.data.data)
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to create task from template')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to create task from template:', error)
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
				const response = await axios.put(`/apps/chorestrike/api/v1/tasks/${taskId}`, updates)

				if (response.data.success) {
					const taskIndex = this.tasks.findIndex(t => t.id === taskId)
					if (taskIndex >= 0) {
						this.tasks[taskIndex] = response.data.data
					}
				} else {
					throw new Error(response.data.error?.message || 'Failed to update task')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to update task:', error)
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
				const response = await axios.delete(`/apps/chorestrike/api/v1/tasks/${taskId}`)

				if (response.data.success) {
					// Remove task from state
					this.tasks = this.tasks.filter(t => t.id !== taskId)
				} else {
					throw new Error(response.data.error?.message || 'Failed to delete task')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to delete task:', error)
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create default task templates
		 */
		async createDefaultTemplates() {
			this.loading = true
			this.error = null

			try {
				const response = await axios.post('/apps/chorestrike/api/v1/tasks/create-default-templates')

				if (response.data.success) {
					// Update templates in state
					this.templates = response.data.data
					// Also add to tasks if they're not already there
					response.data.data.forEach(template => {
						if (!this.tasks.find(t => t.id === template.id)) {
							this.tasks.push(template)
						}
					})
				} else {
					throw new Error(response.data.error?.message || 'Failed to create default templates')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to create default templates:', error)
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
				const response = await axios.get(`/apps/chorestrike/api/v1/tasks/search?query=${encodeURIComponent(query)}`)

				if (response.data.success) {
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to search tasks')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to search tasks:', error)
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
				const response = await axios.get(`/apps/chorestrike/api/v1/tasks/category/${encodeURIComponent(category)}`)

				if (response.data.success) {
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch tasks by category')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to fetch tasks by category:', error)
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
				const response = await axios.get('/apps/chorestrike/api/v1/tasks/categories')

				if (response.data.success) {
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch task categories')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to fetch task categories:', error)
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Initialize tasks and templates
		 */
		async initializeTasks() {
			await Promise.all([
				this.fetchTasks(),
				this.fetchTaskTemplates(),
			])
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
			this.templates = []
			this.roomTasks = []
			this.loading = false
			this.error = null
		},
	},
})
