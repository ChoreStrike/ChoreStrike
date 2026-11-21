/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { api } from '../services/api.js'
import { rememberCommand, settleCommand } from '../utils/commandId.js'
import { logger } from '../utils/logger.js'
import { useChecklistStore } from './checklist.js'
import { useRoomTasksStore } from './roomTasks.js'

/**
 * The two writes of the one form (CS-PLAN-091 WP-B6, FEAT-UX-040): a new
 * task and a save of an existing one, each one atomic request, so a
 * refusal never leaves the form half applied. The answers reconcile the
 * room-task, definition and sub-task caches.
 */
export const useTaskFormStore = defineStore('taskForm', {
	state: () => ({
		/** @type {Object<string, string>} Command key → the UUID an uncertain failure keeps for its retry. */
		pendingCommands: {},
	}),

	actions: {
		/**
		 * Create a task: definition, sub-tasks, room, assignees, first date
		 * and sleep in one request (`POST /room-tasks`).
		 * @param {object} payload The form's fields: `{ roomId, task, assigneeUserIds, checklistItems, firstDate?, sleeping?, wakeOn? }`.
		 * @return {Promise<object>} The creation aggregate `{ task, roomTask, assignees }`.
		 */
		async create(payload) {
			try {
				const aggregate = await api.post('/api/v1/room-tasks', payload)
				useRoomTasksStore().addCreatedRoomTask(aggregate)
				if (aggregate.task?.id && (payload?.checklistItems ?? []).length === 0) {
					// A task without sub-tasks needs no read to say so.
					useChecklistStore().setTaskItems(aggregate.task.id, [])
				} else if (aggregate.task?.id) {
					// The creation does not answer the sub-tasks' ids; the store logs a failed read.
					useChecklistStore().fetchTaskItems(aggregate.task.id).catch(() => {})
				}
				return aggregate
			} catch (error) {
				logger.error('Failed to create task', error, { store: 'taskForm', action: 'create', roomId: payload?.roomId })
				throw error
			}
		},

		/**
		 * Save the form on an existing task (`PUT /room-tasks/{id}`) with the
		 * cycle version the form was opened with, never a fresher one: a
		 * task that changed meanwhile answers `409 STALE_TARGET` and the
		 * member opens the form again. A definition several rooms share
		 * answers `409 SCOPE_REQUIRED` until the caller names a scope.
		 * @param {number} roomTaskId The room task.
		 * @param {number} expectedVersion The cycle version the form showed.
		 * @param {object} fields The form's fields, `scope` included when chosen.
		 * @return {Promise<object>} `{ task, roomTask, assignees, checklistItems, cycle, replay }`
		 */
		async save(roomTaskId, expectedVersion, fields) {
			const commandKey = `save:${roomTaskId}:${expectedVersion}:${JSON.stringify(fields)}`
			const { commandId, pending } = rememberCommand(this.pendingCommands, commandKey)
			this.pendingCommands = pending
			try {
				const data = await api.put(`/api/v1/room-tasks/${roomTaskId}`, { commandId, expectedVersion, ...fields })
				this.pendingCommands = settleCommand(this.pendingCommands, commandKey)
				useRoomTasksStore().applySavedRoomTask(data)
				const checklistStore = useChecklistStore()
				checklistStore.setTaskItems(data.task.id, data.checklistItems)
				if (checklistStore.progress[roomTaskId]) {
					// A split moved the ticks to the copied sub-tasks, a changed list dropped
					// some: an open row reads them again (the store logs a failed read).
					checklistStore.fetchRoomTaskProgress(roomTaskId).catch(() => {})
				}
				return data
			} catch (error) {
				this.pendingCommands = settleCommand(this.pendingCommands, commandKey, error)
				if (error?.code !== 'SCOPE_REQUIRED') {
					logger.error('Failed to save task', error, { store: 'taskForm', action: 'save', roomTaskId })
				}
				if (error?.status === 409 && error?.code !== 'SCOPE_REQUIRED') {
					// The task moved on: the rows read again for the next attempt.
					useRoomTasksStore().refreshDerivedProjections({ rows: true })
				}
				throw error
			}
		},

		/** Identity or house switch: drop the commands of the previous household. */
		reset() {
			this.pendingCommands = {}
		},
	},
})
