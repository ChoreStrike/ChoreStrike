/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, beforeEach, jest } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useRoomTasksStore } from '../../../src/store/roomTasks.js'
import { useSchedulingStore } from '../../../src/store/scheduling.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: {
		get: jest.fn(),
		post: jest.fn(),
		put: jest.fn(),
		delete: jest.fn(),
	},
}))

import axios from '@nextcloud/axios'

describe('RoomTasks Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useRoomTasksStore()
		jest.clearAllMocks()
	})

	describe('Initial State', () => {
		it('has correct initial state', () => {
			expect(store.roomTasks).toEqual([])
			expect(store.loading).toBe(false)
			expect(store.error).toBeNull()
		})
	})

	describe('Getters', () => {
		it('getRoomTaskById finds task by id', () => {
			store.roomTasks = [
				{ id: 1, name: 'Task 1' },
				{ id: 2, name: 'Task 2' },
			]
			const task = store.getRoomTaskById(2)
			expect(task.name).toBe('Task 2')
		})

		it('activeRoomTasks returns only active tasks', () => {
			store.roomTasks = [
				{ id: 1, isActive: true },
				{ id: 2, isActive: false },
				{ id: 3, isActive: true },
			]
			expect(store.activeRoomTasks).toHaveLength(2)
		})

		it('inactiveRoomTasks returns only inactive tasks', () => {
			store.roomTasks = [
				{ id: 1, isActive: true },
				{ id: 2, isActive: false },
			]
			expect(store.inactiveRoomTasks).toHaveLength(1)
		})

		it('roomTasksByRoom groups by roomId', () => {
			store.roomTasks = [
				{ id: 1, roomId: 10 },
				{ id: 2, roomId: 20 },
				{ id: 3, roomId: 10 },
			]
			const byRoom = store.roomTasksByRoom
			expect(byRoom[10]).toHaveLength(2)
			expect(byRoom[20]).toHaveLength(1)
		})

		it('roomTasksByUser groups by assigned user', () => {
			store.roomTasks = [
				{ id: 1, assignedToUserId: 'user1' },
				{ id: 2, assignedToUserId: 'user2' },
				{ id: 3 }, // unassigned
			]
			const byUser = store.roomTasksByUser
			expect(byUser.user1).toHaveLength(1)
			expect(byUser.unassigned).toHaveLength(1)
		})

		it('unassignedRoomTasks returns unassigned active tasks', () => {
			store.roomTasks = [
				{ id: 1, isActive: true, assignedToUserId: 'user1' },
				{ id: 2, isActive: true }, // unassigned
				{ id: 3, isActive: false }, // inactive
			]
			expect(store.unassignedRoomTasks).toHaveLength(1)
			expect(store.unassignedRoomTasks[0].id).toBe(2)
		})

		it('assignedRoomTasks returns assigned active tasks', () => {
			store.roomTasks = [
				{ id: 1, isActive: true, assignedToUserId: 'user1' },
				{ id: 2, isActive: true },
			]
			expect(store.assignedRoomTasks).toHaveLength(1)
		})

		it('totalActiveTasks returns count', () => {
			store.roomTasks = [
				{ id: 1, isActive: true },
				{ id: 2, isActive: true },
				{ id: 3, isActive: false },
			]
			expect(store.totalActiveTasks).toBe(2)
		})
	})

	describe('Actions', () => {
		describe('fetchRoomTasks', () => {
			it('fetches room tasks', async () => {
				const mockTasks = [{ id: 1, roomId: 10 }]
				axios.get.mockResolvedValue({
					data: { success: true, data: mockTasks }
				})

				await store.fetchRoomTasks(10)

				expect(store.roomTasks).toEqual(mockTasks)
			})
		})

		describe('createRoomTask', () => {
			it('creates room task', async () => {
				const newTask = { id: 1, name: 'New Task' }
				axios.post.mockResolvedValue({
					data: { success: true, data: newTask }
				})

				const result = await store.createRoomTask({ name: 'New Task' })

				expect(result).toEqual(newTask)
				expect(store.roomTasks).toContainEqual(newTask)
			})
		})

		describe('assignRoomTask', () => {
			it('assigns task to user', async () => {
				store.roomTasks = [{ id: 1, assignedToUserId: null }]
				axios.post.mockResolvedValue({
					data: { success: true, data: { id: 1, assignedToUserId: 'user1' } }
				})

				await store.assignRoomTask(1, 'user1')

				expect(store.roomTasks[0].assignedToUserId).toBe('user1')
			})

			it('throws error for empty userId', async () => {
				await expect(store.assignRoomTask(1, '')).rejects.toThrow('User ID is required')
			})
		})

		describe('unassignRoomTask', () => {
			it('unassigns task', async () => {
				store.roomTasks = [{ id: 1, assignedToUserId: 'user1' }]
				axios.post.mockResolvedValue({
					data: { success: true, data: { id: 1, assignedToUserId: null } }
				})

				await store.unassignRoomTask(1)

				expect(store.roomTasks[0].assignedToUserId).toBeNull()
			})
		})

		describe('deactivateRoomTask', () => {
			it('deactivates task', async () => {
				store.roomTasks = [{ id: 1, isActive: true }]
				axios.post.mockResolvedValue({
					data: { success: true, data: { id: 1, isActive: false } }
				})

				await store.deactivateRoomTask(1)

				expect(store.roomTasks[0].isActive).toBe(false)
			})
		})

		describe('reactivateRoomTask', () => {
			it('reactivates task', async () => {
				store.roomTasks = [{ id: 1, isActive: false }]
				axios.post.mockResolvedValue({
					data: { success: true, data: { id: 1, isActive: true } }
				})

				await store.reactivateRoomTask(1)

				expect(store.roomTasks[0].isActive).toBe(true)
			})
		})

		describe('completeRoomTask', () => {
			it('completes task', async () => {
				store.roomTasks = [{ id: 1, isActive: true }]
				axios.post.mockResolvedValue({
					data: { success: true, data: { roomTask: { id: 1, isActive: false } } }
				})

				const result = await store.completeRoomTask(1, 'user1')

				expect(result.roomTask.isActive).toBe(false)
			})

			it('registers consumed energy in scheduling store when rewards include energyCost', async () => {
				const schedulingStore = useSchedulingStore()
				schedulingStore.todayConsumedEnergy = 2
				store.roomTasks = [{ id: 1, isActive: true }]
				axios.post.mockResolvedValue({
					data: {
						success: true,
						data: {
							roomTask: { id: 1, isActive: false },
							rewards: { energyCost: 3 },
						},
					},
				})

				await store.completeRoomTask(1)

				expect(schedulingStore.todayConsumedEnergy).toBe(5)
			})
		})

		describe('deleteRoomTask', () => {
			it('deletes task', async () => {
				store.roomTasks = [{ id: 1 }, { id: 2 }]
				axios.delete.mockResolvedValue({
					data: { success: true }
				})

				await store.deleteRoomTask(1)

				expect(store.roomTasks).toHaveLength(1)
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
			it('resets state', () => {
				store.roomTasks = [{ id: 1 }]
				store.loading = true
				store.error = 'Error'

				store.reset()

				expect(store.roomTasks).toEqual([])
				expect(store.loading).toBe(false)
				expect(store.error).toBeNull()
			})
		})
	})
})
