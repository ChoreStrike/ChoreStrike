import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useTasksStore } from '../../../src/store/tasks.js'

jest.mock('../../../src/services/api.js', () => ({
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

import { api } from '../../../src/services/api.js'

describe('Tasks Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useTasksStore()
		jest.clearAllMocks()
	})

	describe('State', () => {
		it('has correct initial state', () => {
			expect(store.tasks).toEqual([])
			expect(store.loading).toBe(false)
			expect(store.error).toBeNull()
		})
	})

	describe('Getters', () => {
		beforeEach(() => {
			store.tasks = [
				{ id: 1, name: 'Clean Kitchen', priority: 3, category: 'cleaning', isTemplate: false },
				{ id: 2, name: 'Dust Living Room', priority: 2, category: 'cleaning', isTemplate: false },
				{ id: 3, name: 'Weekly Template', priority: 1, category: 'maintenance', isTemplate: true },
			]
		})

		it('activeTasks returns all tasks (templates live in the setup store)', () => {
			const active = store.activeTasks
			expect(active).toHaveLength(3)
		})

		it('getTaskById finds task', () => {
			const task = store.getTaskById(2)
			expect(task.name).toBe('Dust Living Room')
		})

		it('tasksByCategory groups correctly', () => {
			const byCat = store.tasksByCategory
			expect(byCat['cleaning']).toHaveLength(2)
			expect(byCat['maintenance']).toHaveLength(1)
		})

		it('highPriorityTasks filters priority 3', () => {
			expect(store.highPriorityTasks).toHaveLength(1)
			expect(store.highPriorityTasks[0].name).toBe('Clean Kitchen')
		})

		it('mediumPriorityTasks filters priority 2', () => {
			expect(store.mediumPriorityTasks).toHaveLength(1)
			expect(store.mediumPriorityTasks[0].name).toBe('Dust Living Room')
		})

		it('lowPriorityTasks filters priority 1', () => {
			expect(store.lowPriorityTasks).toHaveLength(1)
			expect(store.lowPriorityTasks[0].name).toBe('Weekly Template')
		})
	})

	describe('Actions', () => {
		describe('fetchTasks', () => {
			it('fetches tasks successfully', async () => {
				const mockTasks = [{ id: 1, name: 'Task 1' }]
				api.get.mockResolvedValue(mockTasks)

				await store.fetchTasks()

				expect(store.tasks).toEqual(mockTasks)
			})

			it('throws on failure', async () => {
				api.get.mockRejectedValue(new Error('Failed to fetch tasks'))

				await expect(store.fetchTasks()).rejects.toThrow('Failed to fetch tasks')
			})
		})

		describe('clearError', () => {
			it('clears error', () => {
				store.error = 'Some error'
				store.clearError()
				expect(store.error).toBeNull()
			})
		})

		describe('reset', () => {
			it('resets all state', () => {
				store.tasks = [{ id: 1 }]
				store.loading = true
				store.error = 'Error'

				store.reset()

				expect(store.tasks).toEqual([])
				expect(store.loading).toBe(false)
				expect(store.error).toBeNull()
			})
		})
	})

	describe('Room-task dedup regression', () => {
		it('useTasksStore no longer exposes roomTasks state', () => {
			expect(store).not.toHaveProperty('roomTasks')
		})

		it('useTasksStore no longer exposes room-task getters', () => {
			expect(store).not.toHaveProperty('getRoomTaskById')
			expect(store).not.toHaveProperty('activeRoomTasks')
			expect(store).not.toHaveProperty('overdueRoomTasks')
			expect(store).not.toHaveProperty('dueTodayRoomTasks')
			expect(store).not.toHaveProperty('dueSoonRoomTasks')
			expect(store).not.toHaveProperty('roomTasksByRoom')
			expect(store).not.toHaveProperty('roomTasksByUser')
		})

		it('useTasksStore no longer exposes fetchRoomTasks action', () => {
			expect(typeof store.fetchRoomTasks).not.toBe('function')
		})
	})
})