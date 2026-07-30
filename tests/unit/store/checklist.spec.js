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
			store.progress = { 10: [{ id: 1, isCompleted: true }] }
			expect(store.getRoomTaskProgress(10)).toEqual([{ id: 1, isCompleted: true }])
		})

		it('getRoomTaskProgress returns empty array for unknown room task', () => {
			expect(store.getRoomTaskProgress(999)).toEqual([])
		})

		it('getCompletionPercent calculates percentage', () => {
			store.progress = {
				10: [
					{ id: 1, isCompleted: true },
					{ id: 2, isCompleted: false },
					{ id: 3, isCompleted: true },
				],
			}
			expect(store.getCompletionPercent(10)).toBe(67)
		})

		it('getCompletionPercent returns 0 for empty progress', () => {
			expect(store.getCompletionPercent(999)).toBe(0)
		})

		it('isItemCompleted finds completed item', () => {
			store.progress = {
				10: [{ id: 1, itemId: 5, isCompleted: true }],
			}
			expect(store.isItemCompleted(10, 5)).toBe(true)
		})

		it('isItemCompleted returns false for incomplete item', () => {
			store.progress = {
				10: [{ id: 1, itemId: 5, isCompleted: false }],
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
