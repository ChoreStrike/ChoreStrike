/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { beforeEach, describe, expect, it, jest } from '@jest/globals'
import { createPinia, setActivePinia } from 'pinia'

jest.mock('@/utils/logger', () => ({
	logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() },
}))

jest.mock('../../../src/services/api.js', () => ({
	api: { get: jest.fn(), post: jest.fn(), put: jest.fn() },
}))

let mockRoomTasksStore
jest.mock('../../../src/store/roomTasks.js', () => ({
	useRoomTasksStore: () => mockRoomTasksStore,
}))

import { api } from '../../../src/services/api.js'
import { useTaskCycleStore } from '../../../src/store/taskCycle.js'

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

/**
 * Cross-layer contract of the cycle commands besides the completion
 * (CS-PLAN-091 WP-B3, ADR-021 decisions 6 and 9): the receipts they send,
 * the fresh cycle they reconcile and the history they keep current.
 */
describe('taskCycle store', () => {
	let store

	const refusal = (status, code) => Object.assign(new Error(code), { status, code })

	beforeEach(() => {
		jest.clearAllMocks()
		setActivePinia(createPinia())
		mockRoomTasksStore = {
			_completionTargetFor: jest.fn(() => ({ cycleKey: 'c88', expectedVersion: 4, state: 'open' })),
			reconcileCompletionPayload: jest.fn(),
			refreshDerivedProjections: jest.fn(),
			undoRoomTaskCompletion: jest.fn().mockResolvedValue({}),
		}
		store = useTaskCycleStore()
	})

	describe('postpone', () => {
		it('sends the shown cycle as a fresh command and reconciles the answer', async () => {
			const answer = { roomTask: { id: 7 }, cycle: { cycleKey: 'c88', expectedVersion: 5, postponeCount: 1 }, replay: false }
			api.post.mockResolvedValue(answer)

			await expect(store.postpone(7)).resolves.toBe(answer)

			expect(api.post).toHaveBeenCalledWith('/api/v1/room-tasks/7/postpone', {
				commandId: expect.stringMatching(UUID),
				cycleKey: 'c88',
				expectedVersion: 4,
			})
			expect(mockRoomTasksStore.reconcileCompletionPayload).toHaveBeenCalledWith(7, answer)
			expect(mockRoomTasksStore.refreshDerivedProjections).toHaveBeenCalledWith()
			expect(store.isPostponing(7)).toBe(false)
		})

		it('ignores a second press while one is in flight', async () => {
			let release
			api.post.mockReturnValue(new Promise((resolve) => {
				release = resolve
			}))

			const first = store.postpone(7)
			expect(store.isPostponing(7)).toBe(true)
			await expect(store.postpone(7)).resolves.toBeNull()
			release({ cycle: null })
			await first

			expect(api.post).toHaveBeenCalledTimes(1)
		})

		it.each([[null], [{ state: 'bonus', expectedVersion: 2 }]])('sends nothing without a cycle to postpone: %j', async (target) => {
			mockRoomTasksStore._completionTargetFor.mockReturnValue(target)

			await expect(store.postpone(7)).rejects.toMatchObject({ code: 'STALE_TARGET' })

			expect(api.post).not.toHaveBeenCalled()
		})

		it('repeats an uncertain command with its UUID and a refused one with a new UUID', async () => {
			api.post.mockRejectedValueOnce(new TypeError('Failed to fetch'))
			await expect(store.postpone(7)).rejects.toThrow('Failed to fetch')
			api.post.mockRejectedValueOnce(refusal(409, 'STALE_TARGET'))
			await expect(store.postpone(7)).rejects.toMatchObject({ code: 'STALE_TARGET' })
			api.post.mockResolvedValueOnce({ cycle: null })
			await store.postpone(7)

			const sent = api.post.mock.calls.map(([, body]) => body.commandId)
			expect(sent[1]).toBe(sent[0])
			expect(sent[2]).not.toBe(sent[1])
			expect(store.pendingCommands).toEqual({})
		})

		it('reads the rows again after a refusal and hands its code on', async () => {
			api.post.mockRejectedValue(refusal(409, 'NOT_POSTPONABLE'))

			await expect(store.postpone(7)).rejects.toMatchObject({ code: 'NOT_POSTPONABLE' })

			expect(mockRoomTasksStore.refreshDerivedProjections).toHaveBeenCalledWith({ rows: true })
			expect(mockRoomTasksStore.reconcileCompletionPayload).not.toHaveBeenCalled()
			expect(store.isPostponing(7)).toBe(false)
		})
	})

	describe('history', () => {
		const entries = [{ id: 42, version: 1, workDate: '2026-11-16', canCorrect: true }]

		it('keeps the entries of a task, newest first as the server sends them', async () => {
			api.get.mockResolvedValue({ completions: entries })
			expect(store.historyOf(7)).toBeNull()

			await expect(store.fetchHistory(7)).resolves.toEqual(entries)

			expect(api.get).toHaveBeenCalledWith('/api/v1/room-tasks/7/history')
			expect(store.historyOf(7)).toEqual(entries)
		})

		it('hands a failed read on', async () => {
			api.get.mockRejectedValue(refusal(404, 'RESOURCE_NOT_FOUND'))

			await expect(store.fetchHistory(7)).rejects.toMatchObject({ status: 404 })
			expect(store.historyOf(7)).toBeNull()
		})

		it('drops a read that lands after a reset', async () => {
			let release
			api.get.mockReturnValue(new Promise((resolve) => {
				release = resolve
			}))
			const read = store.fetchHistory(7)
			store.reset()
			release({ completions: entries })
			await read

			expect(store.historyOf(7)).toBeNull()
		})

		it('re-dates an entry with its shown version and takes the fresh cycle', async () => {
			const answer = { roomTask: { id: 7 }, cycle: { cycleKey: 'c42', expectedVersion: 6 } }
			api.put.mockResolvedValue(answer)
			api.get.mockResolvedValue({ completions: [{ ...entries[0], workDate: '2026-11-14', version: 2 }] })

			await store.changeDate(7, entries[0], '2026-11-14')

			expect(api.put).toHaveBeenCalledWith('/api/v1/completions/42/date', {
				commandId: expect.stringMatching(UUID),
				expectedVersion: 1,
				date: '2026-11-14',
			})
			expect(mockRoomTasksStore.reconcileCompletionPayload).toHaveBeenCalledWith(7, answer)
			expect(store.historyOf(7)[0]).toMatchObject({ workDate: '2026-11-14', version: 2 })
		})

		it('reads the history again after a refused date, so the entry shows what the server holds', async () => {
			api.put.mockRejectedValue(refusal(400, 'VALIDATION_ERROR'))
			api.get.mockResolvedValue({ completions: entries })

			await expect(store.changeDate(7, entries[0], '2099-01-01')).rejects.toMatchObject({ code: 'VALIDATION_ERROR' })

			expect(mockRoomTasksStore.reconcileCompletionPayload).not.toHaveBeenCalled()
			expect(api.get).toHaveBeenCalledWith('/api/v1/room-tasks/7/history')
		})

		it('repeats a date change whose answer was lost with the same UUID', async () => {
			api.put.mockRejectedValueOnce(refusal(503, 'SERVICE_UNAVAILABLE'))
			api.put.mockResolvedValueOnce({ cycle: null })
			api.get.mockResolvedValue({ completions: entries })

			await expect(store.changeDate(7, entries[0], '2026-11-14')).rejects.toMatchObject({ status: 503 })
			await store.changeDate(7, entries[0], '2026-11-14')

			const [first, second] = api.put.mock.calls.map(([, body]) => body.commandId)
			expect(second).toBe(first)
			expect(store.pendingCommands).toEqual({})
		})

		it('deletes an entry through the targeted undo and reads the history again', async () => {
			api.get.mockResolvedValue({ completions: [] })

			await store.deleteEntry(7, entries[0])

			expect(mockRoomTasksStore.undoRoomTaskCompletion).toHaveBeenCalledWith(42, 1)
			expect(store.historyOf(7)).toEqual([])
		})
	})

	it('forgets everything of the previous household on a reset', async () => {
		api.get.mockResolvedValue({ completions: [{ id: 1 }] })
		await store.fetchHistory(7)

		store.reset()

		expect(store.histories).toEqual({})
		expect(store.postponingIds).toEqual({})
		expect(store.pendingCommands).toEqual({})
	})
})
