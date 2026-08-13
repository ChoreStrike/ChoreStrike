/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { api } from '../services/api.js'
import { emit } from '@nextcloud/event-bus'
import { logger } from '../utils/logger.js'
import { useSchedulingStore } from './scheduling.js'
import { useGameStore } from './game.js'

export const useRoomTasksStore = defineStore('roomTasks', {
	state: () => ({
		roomTasks: [],
		loading: false,
		error: null,
		rangeTasks: { scheduled: [], completed: [], from: null, to: null },
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

		/**
		 * Group rangeTasks.scheduled by YYYY-MM-DD, expanding each task
		 * across its projected occurrences. Falls back to nextDue when
		 * the backend omits the occurrences array.
		 * @param {object} state Pinia state
		 * @return {{[date: string]: {scheduled: Array, completed: Array}}}
		 */
		rangeTasksByDay: (state) => {
			const byDay = {}
			for (const item of state.rangeTasks.scheduled) {
				// Recurrence projection (D2): when the backend supplies an
				// `occurrences` list, the task appears on each of those days.
				// Fall back to the single nextDue for items without occurrences.
				const dates = Array.isArray(item.occurrences) && item.occurrences.length
					? item.occurrences
					: [(item.roomTask?.nextDue || '').slice(0, 10)]

				for (const date of dates) {
					if (!date) continue
					if (!byDay[date]) byDay[date] = { scheduled: [], completed: [] }
					byDay[date].scheduled.push({
						roomTask: item.roomTask,
						task: item.task,
						room: item.room,
						flexWindow: item.flexWindow,
						dueDate: date,
					})
				}
			}
			for (const item of state.rangeTasks.completed) {
				const day = (item.completedAt || '').slice(0, 10)
				if (!day) continue
				if (!byDay[day]) byDay[day] = { scheduled: [], completed: [] }
				byDay[day].completed.push(item)
			}
			return byDay
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
				const data = await api.get('/api/v1/room-tasks', { params: { roomId, activeOnly } })

				this.roomTasks = this.roomTasks.filter(roomTask => roomTask.roomId !== roomId)
				this.roomTasks.push(...data)
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
				const data = await api.get(`/api/v1/room-tasks/${roomTaskId}`)
				return data
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
				const data = await api.post('/api/v1/room-tasks', roomTaskData)
				this.roomTasks.push(data)
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to create room task', error, { store: 'roomTasks', action: 'createRoomTask' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a room task from a catalog template (per-room picker).
		 * @param {object} payload { roomId, templateId, level?, overrides?, assigneeUserId? }
		 * @return {Promise<object>} Created room task
		 */
		async createFromTemplate(payload) {
			this.loading = true
			this.error = null

			try {
				const data = await api.post('/api/v1/room-tasks/from-template', payload)
				this.roomTasks.push(data)
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to create room task from template', error, { store: 'roomTasks', action: 'createFromTemplate', templateId: payload?.templateId })
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
				const data = await api.post(`/api/v1/room-tasks/${roomTaskId}/assign`, { userId })

				const roomTaskIndex = this.roomTasks.findIndex(rt => rt.id === roomTaskId)
				if (roomTaskIndex >= 0) {
					this.roomTasks[roomTaskIndex] = data
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
				const data = await api.post(`/api/v1/room-tasks/${roomTaskId}/unassign`)

				const roomTaskIndex = this.roomTasks.findIndex(rt => rt.id === roomTaskId)
				if (roomTaskIndex >= 0) {
					this.roomTasks[roomTaskIndex] = data
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
		 * Fetch the assignee user IDs for a room task
		 * @param {number} roomTaskId Room task identifier to load assignees for.
		 * @return {Promise<Array<string>>} Nextcloud user IDs assigned to the task.
		 */
		async fetchAssignees(roomTaskId) {
			this.loading = true
			this.error = null

			try {
				const data = await api.get(`/api/v1/room-tasks/${roomTaskId}/assignees`)
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch assignees', error, { store: 'roomTasks', action: 'fetchAssignees', roomTaskId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Replace the full set of assignees for a room task (multi-assign)
		 * @param {number} roomTaskId Room task identifier to update.
		 * @param {Array<string>} userIds Nextcloud user IDs to assign.
		 * @return {Promise<Array<string>>} The resulting assignee user IDs.
		 */
		async setAssignees(roomTaskId, userIds) {
			this.loading = true
			this.error = null

			try {
				const data = await api.put(
					`/api/v1/room-tasks/${roomTaskId}/assignees`,
					{ userIds },
				)

				const assigneeIds = (data || []).map(a => a.userId)
				const roomTaskIndex = this.roomTasks.findIndex(rt => rt.id === roomTaskId)
				if (roomTaskIndex >= 0) {
					this.roomTasks[roomTaskIndex] = {
						...this.roomTasks[roomTaskIndex],
						assignees: assigneeIds,
					}
				}
				return assigneeIds
			} catch (error) {
				this.error = error.message
				logger.error('Failed to set assignees', error, { store: 'roomTasks', action: 'setAssignees', roomTaskId })
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
				const data = await api.post(`/api/v1/room-tasks/${roomTaskId}/deactivate`)

				const roomTaskIndex = this.roomTasks.findIndex(rt => rt.id === roomTaskId)
				if (roomTaskIndex >= 0) {
					this.roomTasks[roomTaskIndex] = data
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
				const data = await api.post(`/api/v1/room-tasks/${roomTaskId}/reactivate`)

				const roomTaskIndex = this.roomTasks.findIndex(rt => rt.id === roomTaskId)
				if (roomTaskIndex >= 0) {
					this.roomTasks[roomTaskIndex] = data
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
				const data = await api.put(`/api/v1/room-tasks/${roomTaskId}/due-date`, { nextDue })

				const roomTaskIndex = this.roomTasks.findIndex(rt => rt.id === roomTaskId)
				if (roomTaskIndex >= 0) {
					this.roomTasks[roomTaskIndex] = data
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
				const data = await api.post(`/api/v1/room-tasks/${roomTaskId}/complete`)

				const roomTaskIndex = this.roomTasks.findIndex(rt => rt.id === roomTaskId)
				if (roomTaskIndex >= 0) {
					this.roomTasks[roomTaskIndex] = data.roomTask
				}

				const energyCost = data.rewards?.energyCost
				if (Number.isFinite(energyCost) && energyCost > 0) {
					const schedulingStore = useSchedulingStore()
					schedulingStore.registerTodayEnergyConsumption(energyCost)
				}

				const gameStore = useGameStore()
				const weeklyGoal = data.rewards?.weeklyGoal
				if (weeklyGoal) {
					gameStore.setWeeklyGoal(weeklyGoal)
				}

				const rewards = data.rewards
				emit('chorestrike:task-completed', {
					roomTaskId,
					coins: rewards?.coins,
					isCombo: rewards?.isCombo,
					currentStreak: rewards?.currentStreak,
					streakFreezeUsed: rewards?.streakFreezeUsed,
					streakFreezesRemaining: rewards?.streakFreezesRemaining,
					comboBonusFreeze: rewards?.comboBonusFreeze,
					contributionScore: rewards?.contributionScore,
				})

				if (rewards?.streakFreezeUsed) {
					emit('chorestrike:streak-freeze-used', {
						remaining: rewards.streakFreezesRemaining,
					})
				}

				if (rewards?.newTitle) {
					emit('chorestrike:title-unlocked', {
						pack: rewards.newTitle.pack,
						labelKey: rewards.newTitle.labelKey,
						tierIndex: rewards.newTitle.tierIndex,
					})
				}

				return data
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
				await api.delete(`/api/v1/room-tasks/${roomTaskId}`)

				this.roomTasks = this.roomTasks.filter(rt => rt.id !== roomTaskId)
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
				const config = roomId ? { params: { roomId } } : {}
				const data = await api.get('/api/v1/room-tasks/overdue', config)
				return data
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
				const config = roomId ? { params: { roomId } } : {}
				const data = await api.get('/api/v1/room-tasks/due-today', config)
				return data
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
				const config = { params: { days, ...(roomId && { roomId }) } }
				const data = await api.get('/api/v1/room-tasks/due-within-days', config)
				return data
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
				const data = await api.get(`/api/v1/room-tasks/user/${userId}`, { params: { activeOnly } })
				return data
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
				const data = await api.get(`/api/v1/room-tasks/room/${roomId}/stats`)
				return data
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
				const data = await api.get(`/api/v1/room-tasks/user/${userId}/stats`)
				return data
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
		 * Fetch filtered daily tasks for the current user.
		 * Child users receive a filtered view based on weekdayAvailability and maxDailyTasks.
		 * @return {Promise<Array>} Filtered daily tasks with task and room details.
		 */
		async fetchFilteredDailyTasks() {
			this.loading = true
			this.error = null

			try {
				const data = await api.get('/api/v1/room-tasks/filtered-daily')

				data.forEach((item) => {
					const existingIndex = this.roomTasks.findIndex(rt => rt.id === item.roomTask.id)
					if (existingIndex >= 0) {
						this.roomTasks[existingIndex] = item.roomTask
					} else {
						this.roomTasks.push(item.roomTask)
					}
				})
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch filtered daily tasks', error, { store: 'roomTasks', action: 'fetchFilteredDailyTasks' })
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
		 * Fetch the current user's private tasks.
		 * @return {Promise<Array>} Private tasks owned by the current user.
		 */
		async fetchMyPrivateTasks() {
			this.loading = true
			this.error = null

			try {
				const data = await api.get('/api/v1/tasks/my-private')
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch private tasks', error, { store: 'roomTasks', action: 'fetchMyPrivateTasks' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch scheduled and completed tasks for a date range (calendar view).
		 * @param {string} from Start date (Y-m-d).
		 * @param {string} to End date (Y-m-d).
		 */
		async fetchRangeTasks(from, to) {
			this.error = null

			try {
				const data = await api.get('/api/v1/room-tasks/range', { params: { from, to } })
				this.rangeTasks = { scheduled: data.scheduled, completed: data.completed, from, to }
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch range tasks', error, { store: 'roomTasks', action: 'fetchRangeTasks' })
				throw error
			}
		},

		/**
		 * Reset store state
		 */
		reset() {
			this.roomTasks = []
			this.loading = false
			this.error = null
			this.rangeTasks = { scheduled: [], completed: [], from: null, to: null }
		},
	},
})
