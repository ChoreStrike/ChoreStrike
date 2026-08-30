/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { api } from '../services/api.js'
import { emit } from '@nextcloud/event-bus'
import { logger } from '../utils/logger.js'
import { dateKeyInZone, todayKeyInZone } from '../utils/dateKeys.js'
import { useHouseStore } from './house.js'
import { useSchedulingStore } from './scheduling.js'
import { useGameStore } from './game.js'
import { useTasksStore } from './tasks.js'

export const useRoomTasksStore = defineStore('roomTasks', {
	state: () => ({
		roomTasks: [],
		loading: false,
		error: null,
		rangeTasks: { scheduled: [], completed: [], from: null, to: null },
		// Today-view slice: keeps the child-aware filtered daily payload
		// separate so Today items never bleed into the shared flat list
		// consumed by TaskManager/RoomDetail.
		dailyTasks: [],
	}),

	getters: {
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

		// Statistics — bucketed in the house timezone (CS-PLAN-057). The
		// `new Date(null)`-epoch guard (CR-P4-06) treats a missing nextDue
		// as never-due instead of counting the 1970 epoch as overdue.
		totalOverdueTasks: (state) => {
			const now = new Date()
			return state.roomTasks.filter(roomTask => {
				if (!roomTask.isActive || !roomTask.nextDue) return false
				return new Date(roomTask.nextDue) < now
			}).length
		},

		totalDueTodayTasks: (state) => {
			const houseStore = useHouseStore()
			const todayKey = todayKeyInZone(houseStore.timezone)

			return state.roomTasks.filter(roomTask => {
				if (!roomTask.isActive || !roomTask.nextDue) return false
				return dateKeyInZone(roomTask.nextDue, houseStore.timezone) === todayKey
			}).length
		},

		/**
		 * Group rangeTasks.scheduled by the house-local YYYY-MM-DD day key
		 * (CS-PLAN-057), expanding each task across its projected
		 * occurrences. Backend `occurrences` entries are already house-local
		 * day keys (service-side bucketing); fallback nextDue instants are
		 * converted via dateKeyInZone.
		 * @param {object} state Pinia state
		 * @return {{[date: string]: {scheduled: Array, completed: Array}}}
		 */
		rangeTasksByDay: (state) => {
			const houseStore = useHouseStore()
			const byDay = {}
			for (const item of state.rangeTasks.scheduled) {
				// Recurrence projection (D2): when the backend supplies an
				// `occurrences` list, the task appears on each of those days
				// (house-local keys straight from the backend). Fall back to
				// the single nextDue instant, bucketed in the house TZ.
				const dates = Array.isArray(item.occurrences) && item.occurrences.length
					? item.occurrences
					: [dateKeyInZone(item.roomTask?.nextDue, houseStore.timezone)]

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
				const day = dateKeyInZone(item.completedAt, houseStore.timezone)
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
		 * Create a task definition and materialize it as a room task,
		 * optionally assigning members (unified creation chain, UX-001).
		 *
		 * The definition stays created when a later stage fails — the result
		 * reports which stage failed so the caller can toast partial success
		 * instead of silently dropping the user's work.
		 *
		 * @param {object} taskData Task definition payload (POST /tasks).
		 * @param {number} roomId Room the task is materialized in.
		 * @param {Array<string>} assigneeUserIds Nextcloud user IDs to assign.
		 * @return {Promise<{task: object, roomTask: ?object, roomTaskError: ?Error, assignError: ?Error}>}
		 */
		async createTaskWithRoomTask(taskData, roomId, assigneeUserIds = []) {
			const tasksStore = useTasksStore()
			const task = await tasksStore.createTask(taskData)

			let roomTask = null
			let roomTaskError = null
			try {
				roomTask = await this.createRoomTask({ roomId, taskId: task.id, nextDue: null })
			} catch (error) {
				roomTaskError = error
				return { task, roomTask, roomTaskError, assignError: null }
			}

			let assignError = null
			if (assigneeUserIds.length > 0) {
				try {
					await this.setAssignees(roomTask.id, assigneeUserIds)
				} catch (error) {
					assignError = error
				}
			}

			return { task, roomTask, roomTaskError, assignError }
		},

		/**
		 * Reactivate room task
		 * @param {number} roomTaskId Room task identifier to reactivate.
		 * @return {Promise<object>} Updated room task.
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

				return data
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
		 * Uncomplete a room task (undo primitive, CS-PLAN-041).
		 *
		 * The uncomplete endpoint lives under /tasks/{id}/uncomplete but
		 * operates on the room task id (TaskController::uncomplete →
		 * RoomTaskService::uncompleteTask) and only clears `lastCompleted`.
		 *
		 * @param {number} roomTaskId Room task identifier to uncomplete.
		 * @return {Promise<object>} Updated room task.
		 */
		async uncompleteRoomTask(roomTaskId) {
			this.loading = true
			this.error = null

			try {
				const data = await api.put(`/api/v1/tasks/${roomTaskId}/uncomplete`)
				this.replaceRoomTaskInState(roomTaskId, data)
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to uncomplete room task', error, { store: 'roomTasks', action: 'uncompleteRoomTask', roomTaskId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Undo a completion (CS-PLAN-041): clears lastCompleted and — for
		 * one-time tasks, whose completion deactivates them — reactivates.
		 *
		 * Known limitation (accepted pre-release): server-side rewards,
		 * streaks and recurrence advances from the completion are not
		 * reverted; this restores the task itself for a misclick.
		 *
		 * Emits `chorestrike:task-uncompleted` with the final room task so
		 * open views can restore their local rows.
		 *
		 * @param {number} roomTaskId Room task identifier to restore.
		 * @return {Promise<object>} Final room task state.
		 */
		async undoRoomTaskCompletion(roomTaskId) {
			let roomTask = await this.uncompleteRoomTask(roomTaskId)

			if (!roomTask.isActive) {
				roomTask = await this.reactivateRoomTask(roomTaskId)
			}

			emit('chorestrike:task-uncompleted', { roomTaskId, roomTask })

			return roomTask
		},

		/**
		 * Replace a room task in every store slice that caches it.
		 * @param {number} roomTaskId Room task identifier to replace.
		 * @param {object} roomTask Fresh room task payload.
		 */
		replaceRoomTaskInState(roomTaskId, roomTask) {
			if (!roomTask) return

			const index = this.roomTasks.findIndex(rt => rt.id === roomTaskId)
			if (index >= 0) {
				this.roomTasks[index] = roomTask
			}

			const dailyIndex = this.dailyTasks.findIndex(item => item.roomTask?.id === roomTaskId)
			if (dailyIndex >= 0) {
				this.dailyTasks[dailyIndex] = {
					...this.dailyTasks[dailyIndex],
					roomTask,
				}
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
		 * Bulk assign tasks to one house member in a single call
		 * (CR-SVC-61). Foreign/missing ids are skipped server-side; the
		 * response carries only the successfully assigned room tasks.
		 * @param {Array<number>} roomTaskIds Room task identifiers to assign.
		 * @param {string} userId User identifier receiving assignments.
		 * @return {Promise<Array<object>>} The assigned room tasks (input order).
		 */
		async bulkAssignTasks(roomTaskIds, userId) {
			if (!userId || userId.trim() === '') {
				throw new Error('User ID is required')
			}
			if (!Array.isArray(roomTaskIds)) {
				throw new Error('Room task IDs must be an array')
			}

			this.loading = true
			this.error = null

			try {
				const assigned = await api.post('/api/v1/room-tasks/bulk-assign', { roomTaskIds, userId })

				// Merge the persisted assignment into the cached slices so
				// assignee chips and counters update without a re-fetch.
				for (const roomTask of assigned || []) {
					this.replaceRoomTaskInState(roomTask.id, {
						...roomTask,
						assignees: [...new Set([...(roomTask.assignees || []), userId])],
					})
				}

				return assigned || []
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

				// Dedicated slice: the shared `roomTasks` list must stay
				// scoped to room fetches, or Today items bleed into the
				// TaskManager flat list.
				this.dailyTasks = data
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
			this.dailyTasks = []
		},
	},
})
