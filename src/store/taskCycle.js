/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { api } from '../services/api.js'
import { rememberCommand, settleCommand } from '../utils/commandId.js'
import { logger } from '../utils/logger.js'
import { useRoomTasksStore } from './roomTasks.js'

/**
 * What members do with a task's current cycle and its history besides
 * completing it (CS-PLAN-091 WP-B3 and WP-B4, ADR-021 decisions 6, 7 and
 * 9): postpone it, put the task to sleep or wake it, read the history,
 * re-date an entry and delete one. Nobody is asked; the recent activity
 * shows the postponements and the corrections.
 *
 * Every command answers the fresh `cycle` block, which the room-task store
 * reconciles into every cached row, so the next command carries the
 * current target. A refusal keeps its stable code for the caller's toast.
 */
export const useTaskCycleStore = defineStore('taskCycle', {
	state: () => ({
		/** @type {Object<number, object[]>} Room task id → history entries, newest first. */
		histories: {},
		/** @type {Object<number, boolean>} Room tasks with a postponement in flight. */
		postponingIds: {},
		/** @type {Object<string, string>} Command key → the UUID an uncertain failure keeps for its retry. */
		pendingCommands: {},
		/** Request generation — a superseded read never lands after a reset. */
		requestSeq: 0,
	}),

	getters: {
		historyOf: (state) => (roomTaskId) => state.histories[roomTaskId] ?? null,
		isPostponing: (state) => (roomTaskId) => state.postponingIds[roomTaskId] === true,
	},

	actions: {
		/**
		 * Postpone the cycle a row shows: its window moves by half the flex
		 * days, at most three times. One command per press; a second press
		 * while one is in flight is ignored.
		 * @param {number} roomTaskId The room task.
		 * @return {Promise<?object>} `{ roomTask, cycle, replay }`, or null for the ignored press.
		 */
		async postpone(roomTaskId) {
			if (this.postponingIds[roomTaskId]) {
				return null
			}
			this.postponingIds = { ...this.postponingIds, [roomTaskId]: true }
			try {
				return await this._runCycleCommand(roomTaskId, 'postpone', (target) => {
					if (!target.cycleKey) {
						return null
					}
					return {
						key: `${target.cycleKey}:${target.expectedVersion}`,
						send: (commandId) => api.post(`/api/v1/room-tasks/${roomTaskId}/postpone`, {
							commandId,
							cycleKey: target.cycleKey,
							expectedVersion: target.expectedVersion,
						}),
					}
				}, {})
			} finally {
				const rest = { ...this.postponingIds }
				delete rest[roomTaskId]
				this.postponingIds = rest
			}
		},

		/**
		 * Put a task to sleep, move its wake date, or wake it (adults and
		 * the owner; the server decides). A sleeping task has no window and
		 * is never overdue; it wakes with a fresh cycle.
		 * @param {number} roomTaskId The room task.
		 * @param {boolean} sleeping Asleep from now on, or awake again.
		 * @param {?string} [wakeOn] House-local day the task wakes on (`YYYY-MM-DD`), with `sleeping` only.
		 * @return {Promise<object>} `{ roomTask, cycle, replay }`
		 */
		setSleeping(roomTaskId, sleeping, wakeOn = null) {
			// The task leaves or joins the lists of what is due: the rows read again.
			return this._runCycleCommand(roomTaskId, 'sleep', (target) => ({
				key: `${target.expectedVersion}:${sleeping}:${wakeOn ?? ''}`,
				send: (commandId) => api.put(`/api/v1/room-tasks/${roomTaskId}/sleep`, {
					commandId,
					expectedVersion: target.expectedVersion,
					sleeping,
					...(sleeping && wakeOn ? { wakeOn } : {}),
				}),
			}), { rows: true })
		},

		/**
		 * The skeleton of a command on the cycle a row shows (RF-091-09):
		 * the shown target, one UUID per logical command, the answer
		 * reconciled into every cached row, and a refusal (409) that reads
		 * the rows again so the next attempt carries the current target.
		 * @param {number} roomTaskId The room task.
		 * @param {string} name The command, for its key and the log.
		 * @param {function(object): ?{key: string, send: function(string): Promise<object>}} build
		 *   The request for the shown target; null when the target cannot carry it.
		 * @param {object} refresh What the success reads again (the options of refreshDerivedProjections).
		 * @return {Promise<object>} The command's answer.
		 */
		async _runCycleCommand(roomTaskId, name, build, refresh) {
			const roomTasksStore = useRoomTasksStore()
			const target = roomTasksStore.completionTargetOf(roomTaskId)
			const request = target ? build(target) : null
			if (!request) {
				const error = new Error('The task changed since it was displayed. Reload and try again.')
				error.code = 'STALE_TARGET'
				throw error
			}
			const commandKey = `${name}:${roomTaskId}:${request.key}`
			try {
				const data = await request.send(this._commandIdFor(commandKey))
				this._settleCommand(commandKey)
				roomTasksStore.reconcileCompletionPayload(roomTaskId, data)
				roomTasksStore.refreshDerivedProjections(refresh)
				return data
			} catch (error) {
				this._settleCommand(commandKey, error)
				logger.error(`Failed to ${name} a room task`, error, { store: 'taskCycle', action: name, roomTaskId })
				if (error?.status === 409) {
					roomTasksStore.refreshDerivedProjections({ rows: true })
				}
				throw error
			}
		},

		/**
		 * Read the history of one task: its valid entries, newest first,
		 * with the viewer's correction rights.
		 * @param {number} roomTaskId The room task.
		 * @return {Promise<object[]>} The entries.
		 */
		async fetchHistory(roomTaskId) {
			const seq = this.requestSeq
			try {
				const data = await api.get(`/api/v1/room-tasks/${roomTaskId}/history`)
				if (seq === this.requestSeq) {
					this.histories = { ...this.histories, [roomTaskId]: data.completions ?? [] }
				}
				return data.completions ?? []
			} catch (error) {
				logger.error('Failed to fetch task history', error, { store: 'taskCycle', action: 'fetchHistory', roomTaskId })
				throw error
			}
		},

		/**
		 * Correct the day an entry was done on. The next window of the
		 * task may move with it; the fresh cycle rides back.
		 * @param {number} roomTaskId The entry's room task.
		 * @param {{id: number, version: number}} entry The entry as shown.
		 * @param {string} date The corrected house-local day (`YYYY-MM-DD`).
		 * @return {Promise<object>} The corrected completion state.
		 */
		async changeDate(roomTaskId, entry, date) {
			const commandKey = `date:${entry.id}:${entry.version}:${date}`
			try {
				const data = await api.put(`/api/v1/completions/${entry.id}/date`, {
					commandId: this._commandIdFor(commandKey),
					expectedVersion: entry.version,
					date,
				})
				this._settleCommand(commandKey)
				const roomTasksStore = useRoomTasksStore()
				roomTasksStore.reconcileCompletionPayload(roomTaskId, data)
				// An entry of a bonus task moved off today frees its people for the day.
				roomTasksStore.refreshDerivedProjections({ bonus: roomTasksStore.isBonusTask(roomTaskId) })
				return data
			} catch (error) {
				this._settleCommand(commandKey, error)
				logger.error('Failed to change the date of a completion', error, { store: 'taskCycle', action: 'changeDate', completionId: entry.id })
				throw error
			} finally {
				// Committed or refused, the list shows what the server holds now.
				await this.fetchHistory(roomTaskId).catch(() => {})
			}
		},

		/**
		 * Delete an entry: the targeted undo of the room-task store.
		 * @param {number} roomTaskId The entry's room task.
		 * @param {{id: number, version: number}} entry The entry as shown.
		 */
		async deleteEntry(roomTaskId, entry) {
			try {
				await useRoomTasksStore().undoRoomTaskCompletion(entry.id, entry.version)
			} finally {
				await this.fetchHistory(roomTaskId).catch(() => {})
			}
		},

		/**
		 * One UUID per logical command (CS-PLAN-072 WP1, RF-072-WP1-07): a
		 * retry of the same shown target reuses it, so the server replays
		 * the receipt of a command whose answer was lost.
		 * @param {string} commandKey The command and the target it was sent for.
		 * @return {string} The command UUID.
		 */
		_commandIdFor(commandKey) {
			const { commandId, pending } = rememberCommand(this.pendingCommands, commandKey)
			this.pendingCommands = pending
			return commandId
		},

		/**
		 * A definite answer (any status below 500) closes the command; an
		 * uncertain failure — no answer or a 5xx, where the server may have
		 * committed — keeps its UUID for the retry.
		 * @param {string} commandKey The command and its target.
		 * @param {?object} [error] The failure, absent on success.
		 */
		_settleCommand(commandKey, error = null) {
			this.pendingCommands = settleCommand(this.pendingCommands, commandKey, error)
		},

		/** Identity or house switch: drop everything of the previous household. */
		reset() {
			this.requestSeq++
			this.histories = {}
			this.postponingIds = {}
			this.pendingCommands = {}
		},
	},
})
