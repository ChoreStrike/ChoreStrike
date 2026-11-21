/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { beforeEach, describe, expect, it, jest } from '@jest/globals'
import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

jest.mock('@/utils/logger', () => ({
	logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() },
}))

jest.mock('../../../src/services/api.js', () => ({
	api: { get: jest.fn(), post: jest.fn(), put: jest.fn() },
}))

let mockRoomTasksStore
let mockChecklistStore
jest.mock('../../../src/store/roomTasks.js', () => ({
	useRoomTasksStore: () => mockRoomTasksStore,
}))
jest.mock('../../../src/store/checklist.js', () => ({
	useChecklistStore: () => mockChecklistStore,
}))

import { api } from '../../../src/services/api.js'
import { useTaskFormStore } from '../../../src/store/taskForm.js'
import { logger } from '../../../src/utils/logger.js'

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

/**
 * The two writes of the one form (CS-PLAN-091 WP-B6, FEAT-UX-040): one
 * request each, and the answers reconcile the room-task, definition and
 * sub-task caches.
 */
describe('taskForm store', () => {
	let store

	const refusal = (status, code) => Object.assign(new Error(code), { status, code })
	const fields = { roomId: 3, task: { name: 'Dishes' }, assigneeUserIds: ['mom'], checklistItems: [{ id: 4, label: 'Rinse' }], sleeping: false }
	const answer = {
		task: { id: 7, name: 'Dishes' },
		roomTask: { id: 42, roomId: 3, taskId: 7 },
		assignees: ['mom'],
		checklistItems: [{ id: 4, label: 'Rinse' }],
		cycle: { cycleKey: 'c9', expectedVersion: 2, state: 'open' },
		replay: false,
	}

	beforeEach(() => {
		jest.clearAllMocks()
		setActivePinia(createPinia())
		mockRoomTasksStore = {
			addCreatedRoomTask: jest.fn(),
			applySavedRoomTask: jest.fn(),
			refreshDerivedProjections: jest.fn(),
		}
		mockChecklistStore = {
			progress: {},
			fetchTaskItems: jest.fn().mockResolvedValue([]),
			fetchRoomTaskProgress: jest.fn().mockResolvedValue([]),
			setTaskItems: jest.fn(),
		}
		store = useTaskFormStore()
	})

	describe('create', () => {
		it('posts the whole form once, adds the room task and reads its sub-tasks', async () => {
			const aggregate = { task: { id: 7 }, roomTask: { id: 42 }, assignees: ['mom'] }
			api.post.mockResolvedValue(aggregate)

			await expect(store.create(fields)).resolves.toBe(aggregate)

			expect(api.post).toHaveBeenCalledTimes(1)
			expect(api.post).toHaveBeenCalledWith('/api/v1/room-tasks', fields)
			expect(mockRoomTasksStore.addCreatedRoomTask).toHaveBeenCalledWith(aggregate)
			expect(mockChecklistStore.fetchTaskItems).toHaveBeenCalledWith(7)
			expect(mockChecklistStore.setTaskItems).not.toHaveBeenCalled()
		})

		it('asks nothing after a task without sub-tasks: it has none', async () => {
			api.post.mockResolvedValue({ task: { id: 7 }, roomTask: { id: 42 }, assignees: [] })

			await store.create({ ...fields, checklistItems: [] })

			expect(mockChecklistStore.fetchTaskItems).not.toHaveBeenCalled()
			expect(mockChecklistStore.setTaskItems).toHaveBeenCalledWith(7, [])
		})

		it('keeps the creation when the sub-task read fails', async () => {
			api.post.mockResolvedValue({ task: { id: 7 }, roomTask: { id: 42 }, assignees: [] })
			mockChecklistStore.fetchTaskItems.mockRejectedValue(new Error('offline'))

			await expect(store.create(fields)).resolves.toMatchObject({ roomTask: { id: 42 } })
			await flushPromises()

			expect(mockRoomTasksStore.addCreatedRoomTask).toHaveBeenCalled()
		})

		it('logs and hands a refusal on without touching a cache', async () => {
			const refused = refusal(400, 'VALIDATION_ERROR')
			api.post.mockRejectedValue(refused)

			await expect(store.create(fields)).rejects.toBe(refused)

			expect(logger.error).toHaveBeenCalledWith('Failed to create task', refused, { store: 'taskForm', action: 'create', roomId: 3 })
			expect(mockRoomTasksStore.addCreatedRoomTask).not.toHaveBeenCalled()
			expect(mockChecklistStore.fetchTaskItems).not.toHaveBeenCalled()
		})
	})

	describe('save', () => {
		it('puts the form as one command with the shown version and reconciles the answer', async () => {
			api.put.mockResolvedValue(answer)

			await expect(store.save(42, 1, fields)).resolves.toBe(answer)

			expect(api.put).toHaveBeenCalledWith('/api/v1/room-tasks/42', { commandId: expect.stringMatching(UUID), expectedVersion: 1, ...fields })
			expect(mockRoomTasksStore.applySavedRoomTask).toHaveBeenCalledWith(answer)
			expect(mockChecklistStore.setTaskItems).toHaveBeenCalledWith(7, answer.checklistItems)
			// No row of this task was open: nobody shows its ticks.
			expect(mockChecklistStore.fetchRoomTaskProgress).not.toHaveBeenCalled()
			expect(store.pendingCommands).toEqual({})
		})

		it('reads the ticks of an open row again: a split moved them to the copied sub-tasks', async () => {
			mockChecklistStore.progress = { 42: [{ checklistItemId: 4, isChecked: true }] }
			mockChecklistStore.fetchRoomTaskProgress.mockRejectedValueOnce(new Error('offline'))
			api.put.mockResolvedValue({ ...answer, task: { id: 8, name: 'Dishes' }, checklistItems: [{ id: 9, label: 'Rinse' }] })

			await expect(store.save(42, 1, { ...fields, scope: 'thisRoom' })).resolves.toMatchObject({ task: { id: 8 } })
			await flushPromises()

			expect(mockChecklistStore.setTaskItems).toHaveBeenCalledWith(8, [{ id: 9, label: 'Rinse' }])
			expect(mockChecklistStore.fetchRoomTaskProgress).toHaveBeenCalledWith(42)
		})

		it('repeats an uncertain save with its UUID and a definite failure with a new one', async () => {
			api.put.mockRejectedValueOnce(refusal(undefined, null))
				.mockRejectedValueOnce(refusal(503, 'SERVICE_UNAVAILABLE'))
				.mockRejectedValueOnce(refusal(400, 'VALIDATION_ERROR'))
				.mockResolvedValueOnce(answer)

			await expect(store.save(42, 1, fields)).rejects.toMatchObject({ status: undefined })
			await expect(store.save(42, 1, fields)).rejects.toMatchObject({ status: 503 })
			await expect(store.save(42, 1, fields)).rejects.toMatchObject({ code: 'VALIDATION_ERROR' })
			await store.save(42, 1, fields)

			const ids = api.put.mock.calls.map(([, body]) => body.commandId)
			expect(ids[1]).toBe(ids[0])
			expect(ids[2]).toBe(ids[0])
			expect(ids[3]).not.toBe(ids[0])
			expect(store.pendingCommands).toEqual({})
		})

		it('sends other fields or another version as another command', async () => {
			api.put.mockRejectedValue(refusal(undefined, null))

			await expect(store.save(42, 1, fields)).rejects.toBeDefined()
			await expect(store.save(42, 1, { ...fields, scope: 'allRooms' })).rejects.toBeDefined()
			await expect(store.save(42, 2, fields)).rejects.toBeDefined()

			const ids = api.put.mock.calls.map(([, body]) => body.commandId)
			expect(new Set(ids).size).toBe(3)
			expect(api.put.mock.calls[1][1].scope).toBe('allRooms')
		})

		it('hands a scope question on silently and without rereading the rows', async () => {
			const question = refusal(409, 'SCOPE_REQUIRED')
			api.put.mockRejectedValue(question)

			await expect(store.save(42, 1, fields)).rejects.toBe(question)

			expect(logger.error).not.toHaveBeenCalled()
			expect(mockRoomTasksStore.refreshDerivedProjections).not.toHaveBeenCalled()
			expect(mockRoomTasksStore.applySavedRoomTask).not.toHaveBeenCalled()
		})

		it('logs a conflict and rereads the rows for the next attempt', async () => {
			const conflict = refusal(409, 'STALE_TARGET')
			api.put.mockRejectedValue(conflict)

			await expect(store.save(42, 1, fields)).rejects.toBe(conflict)

			expect(logger.error).toHaveBeenCalledWith('Failed to save task', conflict, { store: 'taskForm', action: 'save', roomTaskId: 42 })
			expect(mockRoomTasksStore.refreshDerivedProjections).toHaveBeenCalledWith({ rows: true })
		})

		it('rereads nothing after another refusal', async () => {
			api.put.mockRejectedValue(refusal(403, 'FORBIDDEN'))

			await expect(store.save(42, 1, fields)).rejects.toMatchObject({ code: 'FORBIDDEN' })

			expect(logger.error).toHaveBeenCalled()
			expect(mockRoomTasksStore.refreshDerivedProjections).not.toHaveBeenCalled()
		})
	})

	it('forgets the pending commands of the previous household on reset', async () => {
		api.put.mockRejectedValue(refusal(undefined, null))
		await expect(store.save(42, 1, fields)).rejects.toBeDefined()
		expect(Object.keys(store.pendingCommands)).toHaveLength(1)

		store.reset()

		expect(store.pendingCommands).toEqual({})
	})
})
