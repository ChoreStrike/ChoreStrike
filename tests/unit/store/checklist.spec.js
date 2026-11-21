import { setActivePinia, createPinia } from 'pinia'
import { useChecklistStore } from '@/store/checklist'

jest.mock('@/services/api', () => ({
	api: {
		get: jest.fn(),
		post: jest.fn(),
		put: jest.fn(),
		delete: jest.fn(),
	},
}))

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

const { api } = require('@/services/api')

describe('checklist store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useChecklistStore()
		jest.clearAllMocks()
	})

	describe('initial state', () => {
		it('starts with empty items and progress', () => {
			expect(store.items).toEqual({})
			expect(store.progress).toEqual({})
			expect(store.progressMeta).toEqual({})
			expect(store.loading).toBe(false)
			expect(store.error).toBeNull()
		})
	})

	describe('getters', () => {
		it('getTaskItems returns items for task', () => {
			store.items = { 5: [{ id: 1, label: 'Step 1' }] }
			expect(store.getTaskItems(5)).toEqual([{ id: 1, label: 'Step 1' }])
		})

		it('getTaskItems returns empty array for unknown task', () => {
			expect(store.getTaskItems(999)).toEqual([])
		})

		it('getRoomTaskProgress returns progress for room task', () => {
			store.progress = { 10: [{ id: 1, isChecked: true }] }
			expect(store.getRoomTaskProgress(10)).toEqual([{ id: 1, isChecked: true }])
		})

		it('getRoomTaskProgress returns empty array for unknown room task', () => {
			expect(store.getRoomTaskProgress(999)).toEqual([])
		})

	})

	describe('actions', () => {
		it('fetchTaskItems loads items from API', async () => {
			const items = [{ id: 1, label: 'Step 1' }]
			api.get.mockResolvedValue(items)

			await store.fetchTaskItems(5)

			expect(api.get).toHaveBeenCalledWith('/api/v1/checklists/task/5')
			expect(store.items[5]).toEqual(items)
			expect(store.loading).toBe(false)
		})

		it('fetchTaskItems sets error on failure', async () => {
			api.get.mockRejectedValue(new Error('Network error'))

			await expect(store.fetchTaskItems(5)).rejects.toThrow('Network error')

			expect(store.error).toBe('Network error')
			expect(store.loading).toBe(false)
		})

		// --- Backend contract (CS-PLAN-042, UX-009) ---

		it('fetchRoomTaskProgress caches the items list from the {items, stats} envelope', async () => {
			const rows = [{ id: 1, checklistItemId: 5, isChecked: false }]
			api.get.mockResolvedValue({ items: rows, stats: { total: 1 } })

			await store.fetchRoomTaskProgress(10)

			expect(api.get).toHaveBeenCalledWith('/api/v1/checklists/progress/10')
			expect(store.progress[10]).toEqual(rows)
		})

		it('fetchRoomTaskProgress caches the cycle key + max revision (ADR-021 decision 3)', async () => {
			const rows = [
				{ id: 1, checklistItemId: 5, isChecked: false, revision: 3 },
				{ id: 2, checklistItemId: 6, isChecked: true, revision: 1 },
			]
			api.get.mockResolvedValue({ items: rows, stats: {}, cycle: { cycleKey: 'c72' } })

			await store.fetchRoomTaskProgress(10)

			expect(store.progressMeta[10]).toEqual({ cycleKey: 'c72', revision: 3 })
		})

		it('toggleItem upserts the returned progress row by checklistItemId', async () => {
			const row = { id: 3, roomTaskId: 10, checklistItemId: 5, isChecked: true }
			api.post.mockResolvedValue({ progress: row, stats: {} })
			store.progress = { 10: [{ id: 3, roomTaskId: 10, checklistItemId: 5, isChecked: false }] }

			await store.toggleItem(10, 5)

			expect(api.post).toHaveBeenCalledWith('/api/v1/checklists/toggle/10/5', { cycleKey: null, expectedRevision: null })
			expect(store.progress[10]).toEqual([row])
		})

		it('toggleItem sends the cached cycle key + expectedRevision (ADR-021 decision 3)', async () => {
			const row = { id: 3, roomTaskId: 10, checklistItemId: 5, isChecked: true, revision: 4 }
			api.post.mockResolvedValue({ progress: row, stats: {}, cycle: { cycleKey: 'c72' } })
			store.progress = { 10: [{ id: 3, roomTaskId: 10, checklistItemId: 5, isChecked: false, revision: 3 }] }
			store.progressMeta = { 10: { cycleKey: 'c72', revision: 3 } }

			await store.toggleItem(10, 5)

			expect(api.post).toHaveBeenCalledWith('/api/v1/checklists/toggle/10/5', { cycleKey: 'c72', expectedRevision: 3 })
			// The response refreshes the cached meta from the upserted rows.
			expect(store.progressMeta[10]).toEqual({ cycleKey: 'c72', revision: 4 })
		})

		it('toggleItem refetches the progress on a 409 (closed cycle or stale revision)', async () => {
			const stale = new Error('stale')
			stale.status = 409
			api.post.mockRejectedValue(stale)
			const refetchSpy = jest.spyOn(store, 'fetchRoomTaskProgress').mockResolvedValue([])

			await expect(store.toggleItem(10, 5)).rejects.toBe(stale)
			expect(refetchSpy).toHaveBeenCalledWith(10)
		})

		it('toggleItem appends a row that did not exist yet', async () => {
			const row = { id: 4, roomTaskId: 10, checklistItemId: 6, isChecked: true }
			api.post.mockResolvedValue({ progress: row, stats: {} })

			await store.toggleItem(10, 6)

			expect(store.progress[10]).toEqual([row])
		})

		// CS-PLAN-091 WP-B6: the one form saves the sub-tasks with the task.
		it('setTaskItems replaces the cached sub-tasks of a task with the answered ones', () => {
			store.items = { 5: [{ id: 1, label: 'Old' }], 6: [{ id: 3, label: 'Other' }] }

			store.setTaskItems(5, [{ id: 2, label: 'New' }, { id: 1, label: 'Old' }])
			expect(store.items[5].map(item => item.id)).toEqual([2, 1])
			expect(store.items[6]).toEqual([{ id: 3, label: 'Other' }])

			store.setTaskItems(5, undefined)
			expect(store.items[5]).toEqual([])
			expect(api.post).not.toHaveBeenCalled()
		})

		it('reset clears all state', () => {
			store.items = { 5: [{ id: 1 }] }
			store.progress = { 10: [{ id: 1 }] }
			store.progressMeta = { 10: { cycleKey: 'c72', revision: 3 } }
			store.loading = true
			store.error = 'something'

			store.reset()

			expect(store.items).toEqual({})
			expect(store.progress).toEqual({})
			expect(store.progressMeta).toEqual({})
			expect(store.loading).toBe(false)
			expect(store.error).toBeNull()
		})

		it('clearError resets error only', () => {
			store.error = 'something'
			store.items = { 5: [{ id: 1 }] }

			store.clearError()

			expect(store.error).toBeNull()
			expect(store.items).toEqual({ 5: [{ id: 1 }] })
		})
	})
})
