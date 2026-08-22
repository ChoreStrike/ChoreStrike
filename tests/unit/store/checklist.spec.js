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

		it('getCompletionPercent calculates percentage', () => {
			store.progress = {
				10: [
					{ id: 1, checklistItemId: 5, isChecked: true },
					{ id: 2, checklistItemId: 6, isChecked: false },
					{ id: 3, checklistItemId: 7, isChecked: true },
				],
			}
			expect(store.getCompletionPercent(10)).toBe(67)
		})

		it('getCompletionPercent returns 0 for empty progress', () => {
			expect(store.getCompletionPercent(999)).toBe(0)
		})

		it('isItemCompleted finds completed item', () => {
			store.progress = {
				10: [{ id: 1, checklistItemId: 5, isChecked: true }],
			}
			expect(store.isItemCompleted(10, 5)).toBe(true)
		})

		it('isItemCompleted returns false for incomplete item', () => {
			store.progress = {
				10: [{ id: 1, checklistItemId: 5, isChecked: false }],
			}
			expect(store.isItemCompleted(10, 5)).toBe(false)
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

		it('addItem posts the label contract and appends the item', async () => {
			const created = { id: 9, taskId: 5, label: 'Dust shelf', sortOrder: 0 }
			api.post.mockResolvedValue(created)

			await store.addItem(5, { label: 'Dust shelf' })

			expect(api.post).toHaveBeenCalledWith('/api/v1/checklists/task/5', { label: 'Dust shelf', sortOrder: undefined })
			expect(store.items[5]).toContainEqual(created)
		})

		it('addItem rejects a blank label without calling the API', async () => {
			await expect(store.addItem(5, { label: '   ' })).rejects.toThrow('Checklist item label is required')
			expect(api.post).not.toHaveBeenCalled()
		})

		it('updateItem puts the new label and patches the cached item', async () => {
			const updated = { id: 1, taskId: 5, label: 'Renamed', sortOrder: 0 }
			api.put.mockResolvedValue(updated)
			store.items = { 5: [{ id: 1, taskId: 5, label: 'Old', sortOrder: 0 }] }

			await store.updateItem(1, 'Renamed')

			expect(api.put).toHaveBeenCalledWith('/api/v1/checklists/item/1', { label: 'Renamed' })
			expect(store.items[5][0].label).toBe('Renamed')
		})

		it('deleteItem removes the item from the task cache', async () => {
			api.delete.mockResolvedValue()
			store.items = { 5: [{ id: 1, label: 'A' }, { id: 2, label: 'B' }] }

			await store.deleteItem(1, 5)

			expect(api.delete).toHaveBeenCalledWith('/api/v1/checklists/item/1')
			expect(store.items[5]).toEqual([{ id: 2, label: 'B' }])
		})

		it('toggleItem upserts the returned progress row by checklistItemId', async () => {
			const row = { id: 3, roomTaskId: 10, checklistItemId: 5, isChecked: true }
			api.post.mockResolvedValue({ progress: row, stats: {} })
			store.progress = { 10: [{ id: 3, roomTaskId: 10, checklistItemId: 5, isChecked: false }] }

			await store.toggleItem(10, 5)

			expect(api.post).toHaveBeenCalledWith('/api/v1/checklists/toggle/10/5')
			expect(store.progress[10]).toEqual([row])
		})

		it('toggleItem appends a row that did not exist yet', async () => {
			const row = { id: 4, roomTaskId: 10, checklistItemId: 6, isChecked: true }
			api.post.mockResolvedValue({ progress: row, stats: {} })

			await store.toggleItem(10, 6)

			expect(store.progress[10]).toEqual([row])
		})

		it('reorderItems sends the itemOrders map and reorders the local list', async () => {
			api.post.mockResolvedValue()
			store.items = {
				5: [
					{ id: 2, label: 'Second', sortOrder: 1 },
					{ id: 1, label: 'First', sortOrder: 0 },
				],
			}

			await store.reorderItems(5, [1, 2])

			expect(api.post).toHaveBeenCalledWith('/api/v1/checklists/reorder', {
				itemOrders: { 1: 0, 2: 1 },
			})
			expect(store.items[5].map(item => item.id)).toEqual([1, 2])
		})

		it('initializeProgress passes the taskId param and tolerates the payload-less success response', async () => {
			// The endpoint returns only { success, message } — the api wrapper
			// unwraps that to `undefined`, so the cache must reset without
			// reading a nonexistent `items` property.
			api.post.mockResolvedValue(undefined)
			store.progress = { 10: [{ id: 1, checklistItemId: 5, isChecked: false }] }

			await store.initializeProgress(10, 7)

			expect(api.post).toHaveBeenCalledWith(
				'/api/v1/checklists/initialize/10',
				null,
				{ params: { taskId: 7 } },
			)
			expect(store.progress[10]).toEqual([])
		})

		it('reset clears all state', () => {
			store.items = { 5: [{ id: 1 }] }
			store.progress = { 10: [{ id: 1 }] }
			store.loading = true
			store.error = 'something'

			store.reset()

			expect(store.items).toEqual({})
			expect(store.progress).toEqual({})
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
