/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { api } from '../services/api.js'
import { emit } from '@nextcloud/event-bus'
import { logger } from '../utils/logger.js'
import { dateKeyInZone } from '../utils/dateKeys.js'
import { useHouseStore } from './house.js'
import { useGameStore } from './game.js'
import { useRoomsStore } from './rooms.js'
import { useTasksStore } from './tasks.js'
import { useUserStore } from './user.js'
import { useMinigameStore } from './minigame.js'
import { useTitlesStore } from './titles.js'

/**
 * Generate a client idempotency UUID (CS-PLAN-072 WP1): one fresh UUID
 * per logical completion command. Crypto RNG when available, otherwise a
 * RFC 4122 v4 fallback.
 * @return {string} Canonical lowercase UUID.
 */
function generateCommandId() {
	const webCrypto = globalThis.crypto
	if (webCrypto && typeof webCrypto.randomUUID === 'function') {
		return webCrypto.randomUUID()
	}
	return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
		const random = Math.random() * 16 | 0
		const value = char === 'x' ? random : (random & 0x3 | 0x8)
		return value.toString(16)
	})
}

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
		// CS-PLAN-066 (D2): dedicated due-today/overdue count projections.
		// They hold complete viewer-visible ENDPOINT results (enriched rows
		// `{roomTask, task, room, assignees}`) so counters stay truthful on
		// a cold Dashboard — a scoped room load or the child-limited daily
		// list can never masquerade as a count source.
		dueTodayTasks: [],
		overdueTasks: [],
		dueTodayLoaded: false,
		overdueLoaded: false,
		dueTodayStale: false,
		overdueStale: false,
		// D3 coordinated derived refresh: a committed action stays
		// successful even when an optional projection refetch fails — the
		// affected projections are marked stale and `refreshFailed` offers a
		// refresh-only retry (no write replay, no undo loss).
		refreshFailed: false,
		countsRefreshing: false,
		// CS-PLAN-065 §3.4 / CS-PLAN-066 §3.3: request-order protection.
		// Generations are scoped per resource/query so a stale sibling load
		// can never discard or overwrite a newer result.
		dailyRequestSeq: 0,
		rangeRequestSeq: 0,
		dueTodayRequestSeq: 0,
		overdueRequestSeq: 0,
		houseRequestSeq: 0,
		roomRequestSeqs: {},
		// CS-PLAN-067 (D1/D3): house-wide instance projection state —
		// `fetchHouseRoomTasks` loads the whole house in ONE request and
		// replaces the instance set; loaded/stale metadata lets mutations
		// invalidate it without an extra fetch.
		houseLoaded: false,
		houseStale: false,
		// Shared in-flight read counter backing `loading` (CS-PLAN-066
		// §3.3): a finishing request may only clear the shared loading state
		// when it is the LAST in-flight one.
		pendingReads: 0,
		// CS-PLAN-072 WP1 (RF-072-WP1-07): pending completion commands keyed
		// by `<roomTaskId>:<occurrenceId>:<expectedVersion>` → commandId.
		// An uncertain-response retry (network failure, 5xx) reuses the SAME
		// command UUID so the server replays the original receipt instead of
		// conflicting on a new command against the fulfilled target. The
		// entry clears on a definitive response (success or 4xx).
		pendingCommands: {},
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

		// CS-PLAN-066 (D2): the counters read the dedicated endpoint-backed
		// projections. Inactive rows are filtered defensively so a projection
		// never counts a completed occurrence between refreshes.
		totalOverdueTasks: (state) => {
			return state.overdueTasks.filter(item => item.roomTask?.isActive).length
		},

		totalDueTodayTasks: (state) => {
			return state.dueTodayTasks.filter(item => item.roomTask?.isActive).length
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
		/** Begin a shared in-flight read (loading = at least one request). */
		_beginRead() {
			this.pendingReads++
			this.loading = true
		},

		/** End a shared in-flight read; only the last one clears loading. */
		_endRead() {
			this.pendingReads = Math.max(0, this.pendingReads - 1)
			if (this.pendingReads === 0) {
				this.loading = false
			}
		},

		/**
		 * Fetch room tasks for a room
		 * @param {number} roomId Room identifier to load tasks for.
		 * @param {boolean} activeOnly Whether to limit results to active tasks.
		 */
		async fetchRoomTasks(roomId, activeOnly = true) {
			// CS-PLAN-066 §3.3: the generation is scoped PER ROOM so two
			// valid parallel room loads can never discard each other.
			const seq = (this.roomRequestSeqs[roomId] ?? 0) + 1
			this.roomRequestSeqs = { ...this.roomRequestSeqs, [roomId]: seq }
			this._beginRead()
			this.error = null

			try {
				const data = await api.get('/api/v1/room-tasks', { params: { roomId, activeOnly } })

				if (seq !== this.roomRequestSeqs[roomId]) {
					logger.debug('Dropping superseded room-task read', { store: 'roomTasks', action: 'fetchRoomTasks', roomId })
					return this.roomTasks.filter(roomTask => roomTask.roomId === roomId)
				}

				this.roomTasks = this.roomTasks.filter(roomTask => roomTask.roomId !== roomId)
				this.roomTasks.push(...data)
				return data
			} catch (error) {
				if (seq === this.roomRequestSeqs[roomId]) {
					this.error = error.message
				}
				logger.error('Failed to fetch room tasks', error, { store: 'roomTasks', action: 'fetchRoomTasks', roomId })
				throw error
			} finally {
				this._endRead()
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
			this._beginRead()
			this.error = null

			try {
				const aggregate = await api.post('/api/v1/room-tasks', payload)

				// Canonical assignee shape (CS-PLAN-066 D1): the aggregate
				// carries a Nextcloud user-ID array already.
				const roomTask = {
					...aggregate.roomTask,
					assignees: aggregate.assignees || [],
				}
				this.roomTasks.push(roomTask)
				this.onInstanceMutated()

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
				this._endRead()
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
			this._beginRead()
			this.error = null

			try {
				const aggregate = await api.post('/api/v1/room-tasks/from-template', payload)

				// Canonical assignee shape (CS-PLAN-066 D1).
				const roomTask = {
					...aggregate.roomTask,
					assignees: aggregate.assignees || [],
				}
				this.roomTasks.push(roomTask)
				this.onInstanceMutated()

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
				this._endRead()
			}
		},

		/**
		 * Fetch the whole house's room tasks in ONE request (CS-PLAN-067
		 * D1) — the batched read that replaces TaskManager's and
		 * SchedulingView's per-room loops. A full-house result REPLACES the
		 * instance set; a room-scoped result still updates only its room.
		 * The read is generation-guarded: a stale house response never
		 * lands, and it is also dropped when a room-scoped read started
		 * after it (the room response is the newer scoped truth and must
		 * not be undone by the overlapping house read).
		 * @param {boolean} activeOnly Whether to limit results to active tasks.
		 * @return {Promise<Array>} The complete viewer-visible house instance list.
		 */
		async fetchHouseRoomTasks(activeOnly = true) {
			const seq = ++this.houseRequestSeq
			const roomSeqsAtStart = { ...this.roomRequestSeqs }
			this._beginRead()
			this.error = null

			try {
				const data = await api.get('/api/v1/room-tasks', { params: { activeOnly } })

				if (seq !== this.houseRequestSeq) {
					logger.debug('Dropping superseded house-task read', { store: 'roomTasks', action: 'fetchHouseRoomTasks' })
					return this.roomTasks
				}

				const roomReadsRaced = Object.keys(roomSeqsAtStart)
					.some(roomId => (this.roomRequestSeqs[roomId] ?? 0) !== roomSeqsAtStart[roomId])
				if (roomReadsRaced) {
					logger.debug('Dropping house-task read superseded by a newer room read', { store: 'roomTasks', action: 'fetchHouseRoomTasks' })
					return this.roomTasks
				}

				this.roomTasks = data
				this.houseLoaded = true
				this.houseStale = false
				return data
			} catch (error) {
				if (seq === this.houseRequestSeq) {
					this.error = error.message
				}
				logger.error('Failed to fetch house room tasks', error, { store: 'roomTasks', action: 'fetchHouseRoomTasks' })
				throw error
			} finally {
				this._endRead()
			}
		},

		/**
		 * Replace the full set of assignees for a room task (multi-assign)
		 * @param {number} roomTaskId Room task identifier to update.
		 * @param {Array<string>} userIds Nextcloud user IDs to assign.
		 * @return {Promise<Array<string>>} The resulting assignee user IDs.
		 */
		async setAssignees(roomTaskId, userIds) {
			this._beginRead()
			this.error = null

			try {
				const data = await api.put(
					`/api/v1/room-tasks/${roomTaskId}/assignees`,
					{ userIds },
				)

				// Canonical user-ID array (CS-PLAN-066 D1): the response IS
				// the persisted set — no client-side union.
				const assigneeIds = data || []
				const roomTaskIndex = this.roomTasks.findIndex(rt => rt.id === roomTaskId)
				if (roomTaskIndex >= 0) {
					this.roomTasks[roomTaskIndex] = {
						...this.roomTasks[roomTaskIndex],
						assignees: assigneeIds,
					}
				}
				this.onInstanceMutated()
				return assigneeIds
			} catch (error) {
				this.error = error.message
				logger.error('Failed to set assignees', error, { store: 'roomTasks', action: 'setAssignees', roomTaskId })
				throw error
			} finally {
				this._endRead()
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

			this._beginRead()
			this.error = null

			try {
				const data = await api.put(`/api/v1/room-tasks/${roomTaskId}/due-date`, { nextDue })

				const roomTaskIndex = this.roomTasks.findIndex(rt => rt.id === roomTaskId)
				if (roomTaskIndex >= 0) {
				// The due-date response carries the serialized entity only —
				// keep the cached canonical assignees when the payload omits
				// them, so assignee chips and My Day "mine" filtering stay
				// intact until the next enriched read (RF-058-09).
					this.roomTasks[roomTaskIndex] = {
						...this.roomTasks[roomTaskIndex],
						...data,
						...(data.assignees === undefined
							? { assignees: this.roomTasks[roomTaskIndex].assignees }
							: {}),
					}
				}
				this.onInstanceMutated()
			} catch (error) {
				this.error = error.message
				logger.error('Failed to update room task due date', error, { store: 'roomTasks', action: 'updateRoomTaskDueDate', roomTaskId })
				throw error
			} finally {
				this._endRead()
			}
		},

		/**
		 * Complete the SHOWN occurrence of a room task (CS-PLAN-072 WP1):
		 * one transactional aggregate carrying the occurrence receipt
		 * contract — `commandId` (fresh client UUID per logical command),
		 * `occurrenceId`, `expectedVersion` and `selectionToken` resolved
		 * from the cached row's occurrence block. The server owns work
		 * time and target effort; a 409 means the shown target moved on —
		 * derived projections are refreshed so the next attempt uses the
		 * current target. The response reconciles every owning store
		 * BEFORE any celebration event is emitted (decision D10), and
		 * replays (`replay: true`) reconcile without repeating effects.
		 * @param {number} roomTaskId Room task identifier to complete.
		 * @return {Promise<object>} The completion aggregate
		 */
		async completeRoomTask(roomTaskId) {
			this._beginRead()
			this.error = null

			try {
				const target = this._completionTargetFor(roomTaskId)
				if (!target) {
					const error = new Error('The task changed since it was displayed. Reload and try again.')
					error.code = 'STALE_TARGET'
					throw error
				}

				// RF-072-WP1-07: one logical command per shown target — a
				// retry of the same (roomTaskId, occurrenceId, expectedVersion)
				// reuses the pending command UUID so an uncertain first
				// response replays the original receipt instead of 409ing on
				// a new command against the now-fulfilled target.
				const commandKey = `${roomTaskId}:${target.occurrenceId}:${target.expectedVersion}`
				let commandId = this.pendingCommands[commandKey]
				if (!commandId) {
					commandId = generateCommandId()
					this.pendingCommands[commandKey] = commandId
				}

				try {
					const data = await api.post(`/api/v1/room-tasks/${roomTaskId}/complete`, {
						commandId,
						occurrenceId: target.occurrenceId,
						expectedVersion: target.expectedVersion,
						selectionToken: target.selectionToken,
					})

					delete this.pendingCommands[commandKey]

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

					// CS-PLAN-066 §3.4: the computed unlock reaches the existing
					// event exactly once per successful completion response —
					// after the titles store was invalidated so consumers never
					// read a stale unlock set, and independent of any post-commit
					// notification failure server-side (rewards.newTitle is
					// explicit and nullable). A failed titles re-sync is a stale
					// optional read, never a completion failure (D3): the event
					// still fires, the titles store reconciles on its next fetch.
					if (rewards?.newTitle) {
						try {
							await useTitlesStore().invalidate()
						} catch (titlesError) {
							logger.warn('Titles re-sync after unlock failed; store stays stale until next fetch', titlesError, { store: 'roomTasks', action: 'completeRoomTask', roomTaskId })
						}
						emit('chorestrike:title-unlocked', {
							pack: rewards.newTitle.pack,
							labelKey: rewards.newTitle.labelKey,
							tierIndex: rewards.newTitle.tierIndex,
						})
					}

					this.refreshDerivedProjections()

					return data
				} catch (error) {
					// Definitive responses (any real HTTP status < 500) close the
					// command: the target moved on (409) or the request was
					// rejected, so the next attempt carries fresh inputs and a
					// NEW command UUID. An UNCERTAIN failure — no response at all
					// or a 5xx, where the server may still have committed —
					// KEEPS the pending UUID so the retry replays the original
					// receipt (RF-072-WP1-07).
					if (error?.status === undefined || error?.status >= 500) {
						logger.warn('Completion command stays pending for retry', error, { store: 'roomTasks', action: 'completeRoomTask', roomTaskId, commandKey })
					} else {
						delete this.pendingCommands[commandKey]
					}
					throw error
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to complete room task', error, { store: 'roomTasks', action: 'completeRoomTask', roomTaskId })
				// A 409 (stale selection/version/target) means the shown
				// target is gone: refresh the derived projections so the
				// next attempt carries the current target.
				if (error?.status === 409) {
					this.refreshDerivedProjections()
				}
				throw error
			} finally {
				this._endRead()
			}
		},

		/**
		 * Resolve the shown completion target of a room task from any
		 * cached slice (CS-PLAN-072 WP1): the row's `occurrence` block
		 * carrying the public occurrence key, expected version and
		 * selection token. Returns null when no occurrence target is
		 * cached (stale UI state — caller surfaces a reload hint).
		 * @param {number} roomTaskId Room task identifier.
		 * @return {?object} `{ occurrenceId, expectedVersion, selectionToken }`
		 */
		_completionTargetFor(roomTaskId) {
			const sources = [
				this.dailyTasks,
				this.dueTodayTasks,
				this.overdueTasks,
				this.roomTasks,
			]
			for (const slice of sources) {
				const row = slice.find(item => (item.roomTask?.id ?? item.id) === roomTaskId)
				const occurrence = row?.occurrence ?? row?.roomTask?.occurrence
				if (occurrence?.occurrenceId && occurrence?.selectionToken) {
					return occurrence
				}
			}
			return null
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
			this._beginRead()
			this.error = null

			try {
				const data = await api.post(`/api/v1/room-tasks/${roomTaskId}/undo`)

				const roomTask = this.reconcileCompletionPayload(roomTaskId, data)
				emit('chorestrike:task-uncompleted', { roomTaskId, roomTask })

				this.refreshDerivedProjections()

				return roomTask
			} catch (error) {
				this.error = error.message
				logger.error('Failed to undo room task completion', error, { store: 'roomTasks', action: 'undoRoomTaskCompletion', roomTaskId })
				throw error
			} finally {
				this._endRead()
			}
		},

		/**
		 * Reconcile every owning store from ONE authoritative
		 * completion/undo payload (decision D10) before any event fires:
		 * roomTasks + dailyTasks/due/overdue slices, the rooms store
		 * (cleanliness), the returned definition, the game store (weekly
		 * goal), and — when the payload's totals describe the current user —
		 * the user progression state and the shared coin wallet.
		 * @param {number} roomTaskId Room task the payload belongs to.
		 * @param {object} data `{ roomTask, room, task, rewards, undone? }`
		 * @return {?object} Reconciled room task (assignees preserved).
		 */
		reconcileCompletionPayload(roomTaskId, data) {
			let reconciled = null
			if (data.roomTask) {
				const cached = this.roomTasks.find(rt => rt.id === roomTaskId)
					?? this.dailyTasks.find(item => item.roomTask?.id === roomTaskId)?.roomTask
					?? this.dueTodayTasks.find(item => item.roomTask?.id === roomTaskId)?.roomTask
					?? this.overdueTasks.find(item => item.roomTask?.id === roomTaskId)?.roomTask
				// The aggregate's serialized room task carries no assignee
				// relation — preserve the cached membership so assignee
				// chips and the Today "mine" filter survive the reconcile.
				reconciled = {
					...data.roomTask,
					assignees: cached?.assignees ?? data.roomTask.assignees ?? [],
				}
				this.replaceRoomTaskInState(roomTaskId, reconciled)
			}

			// CS-PLAN-066 §3.2: the returned definition belongs to the
			// definitions store — merged by its separate ID, never left
			// stale in useTasksStore.
			if (data.task?.id) {
				const tasksStore = useTasksStore()
				const taskIndex = tasksStore.tasks.findIndex(t => t.id === data.task.id)
				if (taskIndex >= 0) {
					tasksStore.tasks[taskIndex] = data.task
				} else {
					tasksStore.tasks.push(data.task)
				}
			}

			if (data.room) {
				useRoomsStore().reconcileRoom(data.room)
			}

			// CS-PLAN-072 WP1: the fresh selection target rides along the
			// completion/undo response — update every cached row's
			// occurrence block so an immediate follow-up attempt carries
			// the CURRENT target instead of the fulfilled one.
			if ('occurrence' in data) {
				this._replaceOccurrenceInState(roomTaskId, data.occurrence)
			}

			const rewards = data.rewards
			if (rewards) {
				if (rewards.weeklyGoal) {
					// CS-PLAN-066 (D5): a completion response is a confirmed
					// transition — it may celebrate, but only a same-week
					// false→true flip after hydration (the game store tracks
					// week identity).
					useGameStore().setWeeklyGoal(rewards.weeklyGoal, { celebrateOnTransition: true })
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

			for (const slice of ['dueTodayTasks', 'overdueTasks']) {
				const sliceIndex = this[slice].findIndex(item => item.roomTask?.id === roomTaskId)
				if (sliceIndex >= 0) {
					this[slice][sliceIndex] = {
						...this[slice][sliceIndex],
						roomTask,
					}
				}
			}
		},

		/**
		 * Replace the occurrence block of a room task in every cached
		 * slice (CS-PLAN-072 WP1): completion/undo responses carry the
		 * fresh canonical target; flat roomTask rows carry their block
		 * inside the roomTask entity.
		 * @param {number} roomTaskId Room task identifier.
		 * @param {?object} occurrence Fresh occurrence block (nullable).
		 */
		_replaceOccurrenceInState(roomTaskId, occurrence) {
			const index = this.roomTasks.findIndex(rt => rt.id === roomTaskId)
			if (index >= 0) {
				this.roomTasks[index] = {
					...this.roomTasks[index],
					occurrence: occurrence ?? null,
				}
			}
			for (const slice of ['dailyTasks', 'dueTodayTasks', 'overdueTasks']) {
				const sliceIndex = this[slice].findIndex(item => item.roomTask?.id === roomTaskId)
				if (sliceIndex >= 0) {
					this[slice][sliceIndex] = {
						...this[slice][sliceIndex],
						occurrence: occurrence ?? null,
					}
				}
			}
		},

		/**
		 * Delete room task
		 * @param {number} roomTaskId Room task identifier to delete.
		 */
		async deleteRoomTask(roomTaskId) {
			this._beginRead()
			this.error = null

			try {
				await api.delete(`/api/v1/room-tasks/${roomTaskId}`)

				this.roomTasks = this.roomTasks.filter(rt => rt.id !== roomTaskId)
				this.dailyTasks = this.dailyTasks.filter(item => item.roomTask?.id !== roomTaskId)
				this.dueTodayTasks = this.dueTodayTasks.filter(item => item.roomTask?.id !== roomTaskId)
				this.overdueTasks = this.overdueTasks.filter(item => item.roomTask?.id !== roomTaskId)
				this.onInstanceMutated()
			} catch (error) {
				this.error = error.message
				logger.error('Failed to delete room task', error, { store: 'roomTasks', action: 'deleteRoomTask', roomTaskId })
				throw error
			} finally {
				this._endRead()
			}
		},

		/**
		 * Get overdue tasks (complete viewer-visible projection, CS-PLAN-066
		 * D2). Rows are enriched `{roomTask, task, room, assignees}` and the
		 * result is STORED — counters and Today rows read this slice.
		 * @param {?number} roomId Optional room identifier for filtering.
		 */
		async fetchOverdueTasks(roomId = null) {
			const seq = ++this.overdueRequestSeq
			this._beginRead()
			this.error = null

			try {
				const config = roomId ? { params: { roomId } } : {}
				const data = await api.get('/api/v1/room-tasks/overdue', config)

				if (seq !== this.overdueRequestSeq) {
					logger.debug('Dropping superseded overdue read', { store: 'roomTasks', action: 'fetchOverdueTasks', roomId })
					return this.overdueTasks
				}

				this.overdueTasks = data
				this.overdueLoaded = true
				this.overdueStale = false
				return data
			} catch (error) {
				if (seq === this.overdueRequestSeq) {
					this.error = error.message
				}
				logger.error('Failed to fetch overdue tasks', error, { store: 'roomTasks', action: 'fetchOverdueTasks', roomId })
				throw error
			} finally {
				this._endRead()
			}
		},

		/**
		 * Get tasks due today (complete viewer-visible projection, D2).
		 * @param {?number} roomId Optional room identifier for filtering.
		 */
		async fetchDueTodayTasks(roomId = null) {
			const seq = ++this.dueTodayRequestSeq
			this._beginRead()
			this.error = null

			try {
				const config = roomId ? { params: { roomId } } : {}
				const data = await api.get('/api/v1/room-tasks/due-today', config)

				if (seq !== this.dueTodayRequestSeq) {
					logger.debug('Dropping superseded due-today read', { store: 'roomTasks', action: 'fetchDueTodayTasks', roomId })
					return this.dueTodayTasks
				}

				this.dueTodayTasks = data
				this.dueTodayLoaded = true
				this.dueTodayStale = false
				return data
			} catch (error) {
				if (seq === this.dueTodayRequestSeq) {
					this.error = error.message
				}
				logger.error('Failed to fetch due today tasks', error, { store: 'roomTasks', action: 'fetchDueTodayTasks', roomId })
				throw error
			} finally {
				this._endRead()
			}
		},

		/**
		 * CS-PLAN-066 §3.2: an accepted instance mutation invalidates every
		 * loaded derived projection that depends on instance state (counts,
		 * daily list, range window). Loaded consumers are refreshed by the
		 * coordinated owner; unloaded ones stay unloaded.
		 */
		onInstanceMutated() {
			if (this.houseLoaded) {
				this.houseStale = true
			}
			if (this.dueTodayLoaded) {
				this.dueTodayStale = true
			}
			if (this.overdueLoaded) {
				this.overdueStale = true
			}
			this.refreshDerivedProjections()
		},

		/**
		 * The D3 coordinated derived-refresh owner. Refetches ONLY the
		 * projections that are currently loaded, exactly once per transition
		 * (no duplicated event-listener refetches). A failed refresh never
		 * rejects the already committed mutation: the affected projections
		 * stay marked stale, `refreshFailed` turns true, and the retry is a
		 * read-only re-run of this method.
		 * @return {Promise<boolean>} True when every attempted refresh succeeded.
		 */
		async refreshDerivedProjections() {
			if (this.countsRefreshing) {
				// A refresh is already in flight for this transition — one
				// owner per mutation, no duplicate refetches.
				return true
			}

			const attempts = []
			if (this.dueTodayLoaded) {
				attempts.push(() => this.fetchDueTodayTasks())
			}
			if (this.overdueLoaded) {
				attempts.push(() => this.fetchOverdueTasks())
			}
			if (attempts.length === 0) {
				return true
			}

			this.countsRefreshing = true
			try {
				// Mark the loaded projections stale up front — the fetches
				// clear their own flag on success, so a FAILED slice stays
				// stale while succeeded ones turn fresh again.
				if (this.dueTodayLoaded) {
					this.dueTodayStale = true
				}
				if (this.overdueLoaded) {
					this.overdueStale = true
				}

				const results = await Promise.all(attempts.map(attempt => attempt().then(
					() => true,
					() => false,
				)))

				const failed = results.some(success => !success)
				this.refreshFailed = failed
				return !failed
			} finally {
				this.countsRefreshing = false
			}
		},

		/**
		 * Get room task statistics
		 * @param {number} roomId Room identifier to load statistics for.
		 */
		async fetchRoomTaskStats(roomId) {
			this._beginRead()
			this.error = null

			try {
				const data = await api.get(`/api/v1/room-tasks/room/${roomId}/stats`)
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch room task stats', error, { store: 'roomTasks', action: 'fetchRoomTaskStats', roomId })
				throw error
			} finally {
				this._endRead()
			}
		},

		/**
		 * Bulk assign tasks to one house member in a single call
		 * (CR-SVC-61). Foreign/missing ids are skipped server-side; the
		 * response carries only the successfully assigned room tasks WITH
		 * their canonical persisted assignee sets (CS-PLAN-066 D1) — the
		 * client reconciles returned successes, never the requested set.
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

			this._beginRead()
			this.error = null

			try {
				const assigned = await api.post('/api/v1/room-tasks/bulk-assign', { roomTaskIds, userId })

				// Merge the persisted assignment into the cached slices so
				// assignee chips and counters update without a re-fetch.
				for (const roomTask of assigned || []) {
					this.replaceRoomTaskInState(roomTask.id, {
						...roomTask,
						assignees: roomTask.assignees || [],
					})
				}
				this.onInstanceMutated()

				return assigned || []
			} catch (error) {
				this.error = error.message
				logger.error('Failed to bulk assign tasks', error, { store: 'roomTasks', action: 'bulkAssignTasks', userId })
				throw error
			} finally {
				this._endRead()
			}
		},

		/**
		 * Fetch filtered daily tasks for the current user.
		 * Child users receive a filtered view based on assignment
		 * eligibility (D4), weekdayAvailability and maxDailyTasks.
		 * @return {Promise<Array>} Filtered daily tasks with task and room details.
		 */
		async fetchFilteredDailyTasks() {
			this._beginRead()
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
				if (seq === this.dailyRequestSeq) {
					this.error = error.message
				}
				logger.error('Failed to fetch filtered daily tasks', error, { store: 'roomTasks', action: 'fetchFilteredDailyTasks' })
				throw error
			} finally {
				this._endRead()
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
				if (seq === this.rangeRequestSeq) {
					this.error = error.message
				}
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
			this.pendingReads = 0
			this.error = null
			this.rangeTasks = { scheduled: [], completed: [], from: null, to: null }
			this.dailyTasks = []
			this.dueTodayTasks = []
			this.overdueTasks = []
			this.dueTodayLoaded = false
			this.overdueLoaded = false
			this.dueTodayStale = false
			this.overdueStale = false
			this.houseLoaded = false
			this.houseStale = false
			this.refreshFailed = false
			this.countsRefreshing = false
			this.pendingCommands = {}
			this.roomRequestSeqs = {}
			// Invalidate in-flight reads so a stale response cannot land
			// after the reset (house/user change).
			this.dailyRequestSeq++
			this.rangeRequestSeq++
			this.dueTodayRequestSeq++
			this.overdueRequestSeq++
			this.houseRequestSeq++
		},
	},
})
