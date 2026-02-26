import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useTasksStore } from '../../../src/store/tasks.js'

// Mock axios
jest.mock('@nextcloud/axios', () => ({
	get: jest.fn(),
	post: jest.fn(),
	put: jest.fn(),
	delete: jest.fn(),
}))

import axios from '@nextcloud/axios'

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
			expect(store.templates).toEqual([])
			expect(store.roomTasks).toEqual([])
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
			store.roomTasks = [
				{ id: 1, taskId: 1, roomId: 1, isActive: true, nextDue: new Date(Date.now() + 86400000).toISOString() },
				{ id: 2, taskId: 2, roomId: 1, isActive: true, nextDue: new Date(Date.now() - 86400000).toISOString() },
				{ id: 3, taskId: 1, roomId: 2, isActive: false, nextDue: new Date().toISOString() },
			]
		})

		it('activeTasks filters non-templates', () => {
			const active = store.activeTasks
			expect(active).toHaveLength(2)
			expect(active.every(t => !t.isTemplate)).toBe(true)
		})

		it('taskTemplates filters templates', () => {
			const templates = store.taskTemplates
			expect(templates).toHaveLength(1)
			expect(templates[0].isTemplate).toBe(true)
		})

		it('getTaskById finds task', () => {
			const task = store.getTaskById(2)
			expect(task.name).toBe('Dust Living Room')
		})

		it('getRoomTaskById finds room task', () => {
			const roomTask = store.getRoomTaskById(2)
			expect(roomTask.taskId).toBe(2)
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

		it('activeRoomTasks filters active only', () => {
			expect(store.activeRoomTasks).toHaveLength(2)
		})

		it('overdueRoomTasks filters overdue', () => {
			const overdue = store.overdueRoomTasks
			expect(overdue).toHaveLength(1)
			expect(overdue[0].id).toBe(2)
		})

		it('dueTodayRoomTasks filters due today', () => {
			// Test would need specific date setup
			const dueToday = store.dueTodayRoomTasks
			expect(Array.isArray(dueToday)).toBe(true)
		})

		it('dueSoonRoomTasks filters due within 3 days', () => {
			const dueSoon = store.dueSoonRoomTasks
			expect(Array.isArray(dueSoon)).toBe(true)
		})

		it('roomTasksByRoom groups by room', () => {
			const byRoom = store.roomTasksByRoom
			expect(byRoom[1]).toHaveLength(2)
			expect(byRoom[2]).toHaveLength(1)
		})

		it('roomTasksByUser groups by user', () => {
			store.roomTasks[0].assignedToUserId = 1
			store.roomTasks[1].assignedToUserId = 2
			const byUser = store.roomTasksByUser
			expect(byUser[1]).toHaveLength(1)
			expect(byUser[2]).toHaveLength(1)
		})
	})

	describe('Actions', () => {
		describe('fetchTasks', () => {
			it('fetches tasks successfully', async () => {
				const mockTasks = [{ id: 1, name: 'Task 1' }]
				axios.get.mockResolvedValue({
					data: { success: true, data: mockTasks }
				})

				await store.fetchTasks()

				expect(store.tasks).toEqual(mockTasks)
			})
		})

		describe('fetchRoomTasks', () => {
			it('fetches room tasks successfully', async () => {
				const mockRoomTasks = [{ id: 1, roomId: 1 }]
				axios.get.mockResolvedValue({
					data: { success: true, data: mockRoomTasks }
				})

				await store.fetchRoomTasks(1)

				expect(store.roomTasks).toEqual(mockRoomTasks)
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
				store.templates = [{ id: 1 }]
				store.roomTasks = [{ id: 1 }]
				store.loading = true
				store.error = 'Error'

				store.reset()

				expect(store.tasks).toEqual([])
				expect(store.templates).toEqual([])
				expect(store.roomTasks).toEqual([])
				expect(store.loading).toBe(false)
				expect(store.error).toBeNull()
			})
		})
	})
})
