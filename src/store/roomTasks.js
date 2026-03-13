/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { logger } from '../utils/logger.js'
import { useSchedulingStore } from './scheduling.js'
import { useGameStore } from './game.js'

export const useRoomTasksStore = defineStore('roomTasks', {
	state: () => ({
		roomTasks: [],
		loading: false,
		error: null,
	}),

	getters: {
		getRoomTaskById: (state) => (roomTaskId) => {
			return state.roomTasks.find(roomTask => roomTask.id === roomTaskId)
		},

		activeRoomTasks: (state) => state.roomTasks.filter(roomTask => roomTask.isActive),

		inactiveRoomTasks: (state) => state.roomTasks.filter(roomTask => !roomTask.isActive),

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

		unassignedRoomTasks: (state) => {
			return state.roomTasks.filter(roomTask =>
				roomTask.isActive && !roomTask.assignedToUserId,
			)
		},

		assignedRoomTasks: (state) => {
			return state.roomTasks.filter(roomTask =>
				roomTask.isActive && roomTask.assignedToUserId,
			)
		},

		// Statistics
		totalActiveTasks: (state) => state.roomTasks.filter(roomTask => roomTask.isActive).length,

		totalOverdueTasks: (state) => {
			return state.roomTasks.filter(roomTask => {
				return roomTask.isActive && new Date(roomTask.nextDue) < new Date()
			}).length
		},

		totalDueTodayTasks: (state) => {
			const today = new Date()
			today.setHours(0, 0, 0, 0)
			const tomorrow = new Date(today)
			tomorrow.setDate(tomorrow.getDate() + 1)

			return state.roomTasks.filter(roomTask => {
				if (!roomTask.isActive) return false
				const dueDate = new Date(roomTask.nextDue)
				return dueDate >= today && dueDate < tomorrow
			}).length
		},
	},

	actions: {
		/**
		 * Fetch room tasks for a room
		 * @param {number} roomId Room identifier to load tasks for.
		 * @param {boolean} activeOnly Whether to limit results to active tasks.
		 */
		async fetchRoomTasks(roomId, activeOnly = true) {
			this.loading = true
			this.error = null

			try {
				const response = await axios.get(`/index.php/apps/chorestrike/api/v1/room-tasks?roomId=${roomId}&activeOnly=${activeOnly}`)

				if (response.data.success) {
					// Update room tasks in state, removing old ones for this room
					this.roomTasks = this.roomTasks.filter(roomTask => roomTask.roomId !== roomId)
					this.roomTasks.push(...response.data.data)
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch room tasks')
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch room tasks', error, { store: 'roomTasks', action: 'fetchRoomTasks', roomId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Get room task with details
		 * @param {number} roomTaskId Room task identifier to load.
		 */
		async fetchRoomTaskWithDetails(roomTaskId) {
			this.loading = true
			this.error = null

			try {
				const response = await axios.get(`/index.php/apps/chorestrike/api/v1/room-tasks/${roomTaskId}`)

				if (response.data.success) {
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch room task details')
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch room task details', error, { store: 'roomTasks', action: 'fetchRoomTaskWithDetails', roomTaskId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a new room task
		 * @param {object} roomTaskData Payload used to create the room task.
		 */
		async createRoomTask(roomTaskData) {
			this.loading = true
			this.error = null

			try {
				const response = await axios.post('/index.php/apps/chorestrike/api/v1/room-tasks', roomTaskData)

				if (response.data.success) {
					this.roomTasks.push(response.data.data)
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to create room task')
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to create room task', error, { store: 'roomTasks', action: 'createRoomTask' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Assign room task to user
		 * @param {number} roomTaskId Room task identifier to assign.
		 * @param {string} userId User identifier receiving the assignment.
		 */
		async assignRoomTask(roomTaskId, userId) {
			if (!userId || userId.trim() === '') {
				throw new Error('User ID is required')
			}

			this.loading = true
			this.error = null

			try {
				const response = await axios.post(`/index.php/apps/chorestrike/api/v1/room-tasks/${roomTaskId}/assign`, { userId })

				if (response.data.success) {
					const roomTaskIndex = this.roomTasks.findIndex(rt => rt.id === roomTaskId)
					if (roomTaskIndex >= 0) {
						this.roomTasks[roomTaskIndex] = response.data.data
					}
				} else {
					throw new Error(response.data.error?.message || 'Failed to assign room task')
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to assign room task', error, { store: 'roomTasks', action: 'assignRoomTask', roomTaskId, userId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Unassign room task from user
		 * @param {number} roomTaskId Room task identifier to unassign.
		 */
		async unassignRoomTask(roomTaskId) {
			this.loading = true
			this.error = null

			try {
				const response = await axios.post(`/index.php/apps/chorestrike/api/v1/room-tasks/${roomTaskId}/unassign`)

				if (response.data.success) {
					const roomTaskIndex = this.roomTasks.findIndex(rt => rt.id === roomTaskId)
					if (roomTaskIndex >= 0) {
						this.roomTasks[roomTaskIndex] = response.data.data
					}
				} else {
					throw new Error(response.data.error?.message || 'Failed to unassign room task')
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to unassign room task', error, { store: 'roomTasks', action: 'unassignRoomTask', roomTaskId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Deactivate room task
		 * @param {number} roomTaskId Room task identifier to deactivate.
		 */
		async deactivateRoomTask(roomTaskId) {
			this.loading = true
			this.error = null

			try {
				const response = await axios.post(`/index.php/apps/chorestrike/api/v1/room-tasks/${roomTaskId}/deactivate`)

				if (response.data.success) {
					const roomTaskIndex = this.roomTasks.findIndex(rt => rt.id === roomTaskId)
					if (roomTaskIndex >= 0) {
						this.roomTasks[roomTaskIndex] = response.data.data
					}
				} else {
					throw new Error(response.data.error?.message || 'Failed to deactivate room task')
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to deactivate room task', error, { store: 'roomTasks', action: 'deactivateRoomTask', roomTaskId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Reactivate room task
		 * @param {number} roomTaskId Room task identifier to reactivate.
		 */
		async reactivateRoomTask(roomTaskId) {
			this.loading = true
			this.error = null

			try {
				const response = await axios.post(`/index.php/apps/chorestrike/api/v1/room-tasks/${roomTaskId}/reactivate`)

				if (response.data.success) {
					const roomTaskIndex = this.roomTasks.findIndex(rt => rt.id === roomTaskId)
					if (roomTaskIndex >= 0) {
						this.roomTasks[roomTaskIndex] = response.data.data
					}
				} else {
					throw new Error(response.data.error?.message || 'Failed to reactivate room task')
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to reactivate room task', error, { store: 'roomTasks', action: 'reactivateRoomTask', roomTaskId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update room task due date
		 * @param {number} roomTaskId Room task identifier to update.
		 * @param {string} nextDue ISO date string for the next due date.
		 */
		async updateRoomTaskDueDate(roomTaskId, nextDue) {
			if (!nextDue) {
				throw new Error('Next due date is required')
			}

			this.loading = true
			this.error = null

			try {
				const response = await axios.put(`/index.php/apps/chorestrike/api/v1/room-tasks/${roomTaskId}/due-date`, { nextDue })

				if (response.data.success) {
					const roomTaskIndex = this.roomTasks.findIndex(rt => rt.id === roomTaskId)
					if (roomTaskIndex >= 0) {
						this.roomTasks[roomTaskIndex] = response.data.data
					}
				} else {
					throw new Error(response.data.error?.message || 'Failed to update room task due date')
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to update room task due date', error, { store: 'roomTasks', action: 'updateRoomTaskDueDate', roomTaskId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Complete room task
		 * @param {number} roomTaskId Room task identifier to complete.
		 */
		async completeRoomTask(roomTaskId) {
			this.loading = true
			this.error = null

			try {
				const response = await axios.post(`/index.php/apps/chorestrike/api/v1/room-tasks/${roomTaskId}/complete`)

				if (response.data.success) {
					const roomTaskIndex = this.roomTasks.findIndex(rt => rt.id === roomTaskId)
					if (roomTaskIndex >= 0) {
						this.roomTasks[roomTaskIndex] = response.data.data.roomTask
					}

					const energyCost = response.data.data.rewards?.energyCost
					if (Number.isFinite(energyCost) && energyCost > 0) {
						const schedulingStore = useSchedulingStore()
						schedulingStore.registerTodayEnergyConsumption(energyCost)
					}

					// Update household cooperative progress
					const gameStore = useGameStore()
					gameStore.incrementWeeklyGoal()

					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to complete room task')
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to complete room task', error, { store: 'roomTasks', action: 'completeRoomTask', roomTaskId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Delete room task
		 * @param {number} roomTaskId Room task identifier to delete.
		 */
		async deleteRoomTask(roomTaskId) {
			this.loading = true
			this.error = null

			try {
				const response = await axios.delete(`/index.php/apps/chorestrike/api/v1/room-tasks/${roomTaskId}`)

				if (response.data.success) {
					// Remove room task from state
					this.roomTasks = this.roomTasks.filter(rt => rt.id !== roomTaskId)
				} else {
					throw new Error(response.data.error?.message || 'Failed to delete room task')
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to delete room task', error, { store: 'roomTasks', action: 'deleteRoomTask', roomTaskId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Get overdue tasks
		 * @param {?number} roomId Optional room identifier for filtering.
		 */
		async fetchOverdueTasks(roomId = null) {
			this.loading = true
			this.error = null

			try {
				const url = roomId
					? `/index.php/apps/chorestrike/api/v1/room-tasks/overdue?roomId=${roomId}`
					: '/index.php/apps/chorestrike/api/v1/room-tasks/overdue'

				const response = await axios.get(url)

				if (response.data.success) {
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch overdue tasks')
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch overdue tasks', error, { store: 'roomTasks', action: 'fetchOverdueTasks', roomId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Get tasks due today
		 * @param {?number} roomId Optional room identifier for filtering.
		 */
		async fetchDueTodayTasks(roomId = null) {
			this.loading = true
			this.error = null

			try {
				const url = roomId
					? `/index.php/apps/chorestrike/api/v1/room-tasks/due-today?roomId=${roomId}`
					: '/index.php/apps/chorestrike/api/v1/room-tasks/due-today'

				const response = await axios.get(url)

				if (response.data.success) {
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch due today tasks')
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch due today tasks', error, { store: 'roomTasks', action: 'fetchDueTodayTasks', roomId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Get tasks due within days
		 * @param {number} days Number of days ahead to query.
		 * @param {?number} roomId Optional room identifier for filtering.
		 */
		async fetchDueWithinDaysTasks(days, roomId = null) {
			if (days < 1 || days > 365) {
				throw new Error('Days must be between 1 and 365')
			}

			this.loading = true
			this.error = null

			try {
				const url = roomId
					? `/index.php/apps/chorestrike/api/v1/room-tasks/due-within-days?days=${days}&roomId=${roomId}`
					: `/index.php/apps/chorestrike/api/v1/room-tasks/due-within-days?days=${days}`

				const response = await axios.get(url)

				if (response.data.success) {
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch due within days tasks')
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch due within days tasks', error, { store: 'roomTasks', action: 'fetchDueWithinDaysTasks', days, roomId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Get user tasks
		 * @param {string} userId User identifier to filter by.
		 * @param {boolean} activeOnly Whether to limit results to active tasks.
		 */
		async fetchUserTasks(userId, activeOnly = true) {
			if (!userId || userId.trim() === '') {
				throw new Error('User ID is required')
			}

			this.loading = true
			this.error = null

			try {
				const response = await axios.get(`/index.php/apps/chorestrike/api/v1/room-tasks/user/${userId}?activeOnly=${activeOnly}`)

				if (response.data.success) {
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch user tasks')
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch user tasks', error, { store: 'roomTasks', action: 'fetchUserTasks', userId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Get room task statistics
		 * @param {number} roomId Room identifier to load statistics for.
		 */
		async fetchRoomTaskStats(roomId) {
			this.loading = true
			this.error = null

			try {
				const response = await axios.get(`/index.php/apps/chorestrike/api/v1/room-tasks/room/${roomId}/stats`)

				if (response.data.success) {
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch room task stats')
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch room task stats', error, { store: 'roomTasks', action: 'fetchRoomTaskStats', roomId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Get user task statistics
		 * @param {string} userId User identifier to load statistics for.
		 */
		async fetchUserTaskStats(userId) {
			if (!userId || userId.trim() === '') {
				throw new Error('User ID is required')
			}

			this.loading = true
			this.error = null

			try {
				const response = await axios.get(`/index.php/apps/chorestrike/api/v1/room-tasks/user/${userId}/stats`)

				if (response.data.success) {
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch user task stats')
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch user task stats', error, { store: 'roomTasks', action: 'fetchUserTaskStats', userId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Bulk assign tasks to user
		 * @param {Array<number>} roomTaskIds Room task identifiers to assign.
		 * @param {string} userId User identifier receiving assignments.
		 */
		async bulkAssignTasks(roomTaskIds, userId) {
			if (!userId || userId.trim() === '') {
				throw new Error('User ID is required')
			}

			this.loading = true
			this.error = null

			try {
				// For now, assign tasks one by one (API doesn't have bulk endpoint yet)
				const results = []
				for (const roomTaskId of roomTaskIds) {
					try {
						await this.assignRoomTask(roomTaskId, userId)
						results.push({ success: true, roomTaskId })
					} catch (error) {
						results.push({ success: false, roomTaskId, error: error.message })
					}
				}

				return results
			} catch (error) {
				this.error = error.message
				logger.error('Failed to bulk assign tasks', error, { store: 'roomTasks', action: 'bulkAssignTasks', userId })
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
			this.roomTasks = []
			this.loading = false
			this.error = null
		},
	},
})
