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
import { useGameStore } from './game.js'
import { useRoomsStore } from './rooms.js'
import { useUserStore } from './user.js'
import { useMinigameStore } from './minigame.js'
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
		// CS-PLAN-065 §3.4: request-order protection scoped to the
		// scheduling mutations and their readers — a pre-apply response
		// must never overwrite post-apply refreshed results.
		dailyRequestSeq: 0,
		rangeRequestSeq: 0,
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
		 * Create a room task — the one atomic aggregate request (CS-PLAN-063
		 * §3.5, CR-WF-03): definition, room-task materialization, and initial
		 * assignees commit or roll back together on the backend, so there is
		 * no partial-success reporting anymore. The aggregate response
		 * `{task, roomTask, assignees}` updates both stores and is returned
		 * to the caller.
		 * @param {object} payload { roomId, task|taskId, assigneeUserIds?, nextDue? }
		 * @return {Promise<object>} The creation aggregate
		 */
		async createTaskAggregate(payload) {
			this.loading = true
			this.error = null

			try {
				const aggregate = await api.post('/api/v1/room-tasks', payload)

				const roomTask = {
					...aggregate.roomTask,
					assignees: (aggregate.assignees || []).map(a => a.userId),
				}
				this.roomTasks.push(roomTask)

				const tasksStore = useTasksStore()
				if (aggregate.task && !tasksStore.getTaskById(aggregate.task.id)) {
					tasksStore.tasks.push(aggregate.task)
				}

				return aggregate
			} catch (error) {
				this.error = error.message
				logger.error('Failed to create task aggregate', error, { store: 'roomTasks', action: 'createTaskAggregate' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a room task from a catalog template (per-room picker).
		 * The response is the aggregate shape `{task, roomTask, assignees}`
		 * (CS-PLAN-063 D1); the materialized room task joins the store.
		 * @param {object} payload { roomId, templateId, level?, overrides?, assigneeUserId? }
		 * @return {Promise<object>} The creation aggregate
		 */
		async createFromTemplate(payload) {
			this.loading = true
			this.error = null

			try {
				const aggregate = await api.post('/api/v1/room-tasks/from-template', payload)

				const roomTask = {
					...aggregate.roomTask,
					assignees: (aggregate.assignees || []).map(a => a.userId),
				}
				this.roomTasks.push(roomTask)

				// Same aggregate-shape handling as createTaskAggregate (D1):
				// cache the materialized definition for TaskList rendering.
				const tasksStore = useTasksStore()
				if (aggregate.task && !tasksStore.getTaskById(aggregate.task.id)) {
					tasksStore.tasks.push(aggregate.task)
				}

				return aggregate
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
		 * Complete room task — the one transactional aggregate request
		 * (CS-PLAN-064 WP5). The effect record (before-state + deltas) is
		 * written server-side; the response reconciles every owning store
		 * BEFORE any celebration event is emitted (decision D10), so event
		 * consumers never observe stale caches.
		 * @param {number} roomTaskId Room task identifier to complete.
		 * @return {Promise<object>} The completion aggregate
		 */
		async completeRoomTask(roomTaskId) {
			this.loading = true
			this.error = null

			try {
				const data = await api.post(`/api/v1/room-tasks/${roomTaskId}/complete`)

				this.reconcileCompletionPayload(roomTaskId, data)

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
		 * Undo the newest revertible completion of a room task — one
		 * aggregate request (CS-PLAN-064 §3.5, decision D5):
		 * `POST /room-tasks/{id}/undo` restores recurrence, ledger marking,
		 * rewards/streak/freezes/titles, cleanliness, and checklist
		 * progress server-side from the recorded effect. The response
		 * reconciles the owning stores before
		 * `chorestrike:task-uncompleted` restores open views (TodayView's
		 * contract is preserved). No revertible occurrence → server-side
		 * zero-write no-op with the current state echoed back.
		 * @param {number} roomTaskId Room task identifier to restore.
		 * @return {Promise<object>} Final room task state.
		 */
		async undoRoomTaskCompletion(roomTaskId) {
			this.loading = true
			this.error = null

			try {
				const data = await api.post(`/api/v1/room-tasks/${roomTaskId}/undo`)

				const roomTask = this.reconcileCompletionPayload(roomTaskId, data)
				emit('chorestrike:task-uncompleted', { roomTaskId, roomTask })

				return roomTask
			} catch (error) {
				this.error = error.message
				logger.error('Failed to undo room task completion', error, { store: 'roomTasks', action: 'undoRoomTaskCompletion', roomTaskId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Reconcile every owning store from ONE authoritative
		 * completion/undo payload (decision D10) before any event fires:
		 * roomTasks + dailyTasks slices, the rooms store (cleanliness),
		 * the game store (weekly goal), and — when the payload's totals
		 * describe the current user — the user progression state and the
		 * shared coin wallet.
		 * @param {number} roomTaskId Room task the payload belongs to.
		 * @param {object} data `{ roomTask, room, task, rewards, undone? }`
		 * @return {?object} Reconciled room task (assignees preserved).
		 */
		reconcileCompletionPayload(roomTaskId, data) {
			let reconciled = null
			if (data.roomTask) {
				const cached = this.roomTasks.find(rt => rt.id === roomTaskId)
					?? this.dailyTasks.find(item => item.roomTask?.id === roomTaskId)?.roomTask
				// The aggregate's serialized room task carries no assignee
				// relation — preserve the cached membership so assignee
				// chips and the Today "mine" filter survive the reconcile.
				reconciled = {
					...data.roomTask,
					assignees: cached?.assignees ?? data.roomTask.assignees ?? [],
				}
				this.replaceRoomTaskInState(roomTaskId, reconciled)
			}

			if (data.room) {
				useRoomsStore().reconcileRoom(data.room)
			}

			const rewards = data.rewards
			if (rewards) {
				if (rewards.weeklyGoal) {
					useGameStore().setWeeklyGoal(rewards.weeklyGoal)
				}

				// The totals describe the COMPLETER (ADR-011); a completion's
				// completer is always the current user, an undo's actor may
				// be a house owner restoring someone else's state — the
				// completerUserId marker gates the user-side reconciliation.
				const userStore = useUserStore()
				const completerUid = rewards.completerUserId ?? userStore.currentUser?.nextcloudUserId
				if (completerUid && completerUid === userStore.currentUser?.nextcloudUserId) {
					userStore.applyRewardTotals(rewards)
					useMinigameStore().reconcileCoins(rewards.totalCoins)
				}
			}

			return reconciled
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
		 * Child users receive a filtered view based on assignment
		 * eligibility (D4), weekdayAvailability and maxDailyTasks.
		 * @return {Promise<Array>} Filtered daily tasks with task and room details.
		 */
		async fetchFilteredDailyTasks() {
			this.loading = true
			this.error = null

			// CS-PLAN-065 §3.4: only the latest request may land — a stale
			// in-flight response must not overwrite post-apply state.
			const seq = ++this.dailyRequestSeq

			try {
				const data = await api.get('/api/v1/room-tasks/filtered-daily')

				if (seq !== this.dailyRequestSeq) {
					logger.debug('Dropping superseded daily read', { store: 'roomTasks', action: 'fetchFilteredDailyTasks' })
					return this.dailyTasks
				}

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

			// CS-PLAN-065 §3.4: a pre-apply range response must not overwrite
			// the post-apply refreshed window.
			const seq = ++this.rangeRequestSeq

			try {
				const data = await api.get('/api/v1/room-tasks/range', { params: { from, to } })

				if (seq !== this.rangeRequestSeq) {
					logger.debug('Dropping superseded range read', { store: 'roomTasks', action: 'fetchRangeTasks' })
					return this.rangeTasks
				}

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
			// Invalidate in-flight reads so a stale response cannot land
			// after the reset (house/user change).
			this.dailyRequestSeq++
			this.rangeRequestSeq++
		},
	},
})
