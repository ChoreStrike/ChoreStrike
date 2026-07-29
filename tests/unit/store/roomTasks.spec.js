/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, beforeEach, jest } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useRoomTasksStore } from '../../../src/store/roomTasks.js'
import { useSchedulingStore } from '../../../src/store/scheduling.js'

jest.mock('../../../src/services/api.js', () => ({
	api: {
		get: jest.fn(),
		post: jest.fn(),
		put: jest.fn(),
		delete: jest.fn(),
	},
}))

import { api } from '../../../src/services/api.js'

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
				{ id: 3 },
			]
			const byUser = store.roomTasksByUser
			expect(byUser.user1).toHaveLength(1)
			expect(byUser.unassigned).toHaveLength(1)
		})

		it('unassignedRoomTasks returns unassigned active tasks', () => {
			store.roomTasks = [
				{ id: 1, isActive: true, assignedToUserId: 'user1' },
				{ id: 2, isActive: true },
				{ id: 3, isActive: false },
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
				api.get.mockResolvedValue(mockTasks)

				await store.fetchRoomTasks(10)

				expect(store.roomTasks).toEqual(mockTasks)
			})
		})

		describe('createRoomTask', () => {
			it('creates room task', async () => {
				const newTask = { id: 1, name: 'New Task' }
				api.post.mockResolvedValue(newTask)

				const result = await store.createRoomTask({ name: 'New Task' })

				expect(result).toEqual(newTask)
				expect(store.roomTasks).toContainEqual(newTask)
			})
		})

		describe('assignRoomTask', () => {
			it('assigns task to user', async () => {
				store.roomTasks = [{ id: 1, assignedToUserId: null }]
				api.post.mockResolvedValue({ id: 1, assignedToUserId: 'user1' })

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
				api.post.mockResolvedValue({ id: 1, assignedToUserId: null })

				await store.unassignRoomTask(1)

				expect(store.roomTasks[0].assignedToUserId).toBeNull()
			})
		})

		describe('fetchAssignees', () => {
			it('returns assignee user ids', async () => {
				api.get.mockResolvedValue(['user1', 'user2'])

				const result = await store.fetchAssignees(1)

				expect(api.get).toHaveBeenCalledWith('/api/v1/room-tasks/1/assignees')
				expect(result).toEqual(['user1', 'user2'])
			})
		})

		describe('setAssignees', () => {
			it('replaces assignees and updates state', async () => {
				store.roomTasks = [{ id: 1, assignees: [] }]
				api.put.mockResolvedValue([{ userId: 'user1' }, { userId: 'user2' }])

				const result = await store.setAssignees(1, ['user1', 'user2'])

				expect(api.put).toHaveBeenCalledWith(
					'/api/v1/room-tasks/1/assignees',
					{ userIds: ['user1', 'user2'] },
				)
				expect(result).toEqual(['user1', 'user2'])
				expect(store.roomTasks[0].assignees).toEqual(['user1', 'user2'])
			})

			it('throws on API failure', async () => {
				api.put.mockRejectedValue(new Error('Not a member'))

				await expect(store.setAssignees(1, ['intruder'])).rejects.toThrow('Not a member')
			})
		})

		describe('deactivateRoomTask', () => {
			it('deactivates task', async () => {
				store.roomTasks = [{ id: 1, isActive: true }]
				api.post.mockResolvedValue({ id: 1, isActive: false })

				await store.deactivateRoomTask(1)

				expect(store.roomTasks[0].isActive).toBe(false)
			})
		})

		describe('reactivateRoomTask', () => {
			it('reactivates task', async () => {
				store.roomTasks = [{ id: 1, isActive: false }]
				api.post.mockResolvedValue({ id: 1, isActive: true })

				await store.reactivateRoomTask(1)

				expect(store.roomTasks[0].isActive).toBe(true)
			})
		})

		describe('completeRoomTask', () => {
			it('completes task', async () => {
				store.roomTasks = [{ id: 1, isActive: true }]
				api.post.mockResolvedValue({ roomTask: { id: 1, isActive: false } })

				const result = await store.completeRoomTask(1, 'user1')

				expect(result.roomTask.isActive).toBe(false)
			})

		it('registers consumed energy in scheduling store when rewards include energyCost', async () => {
			const schedulingStore = useSchedulingStore()
			schedulingStore.todayConsumedEnergy = 2
			store.roomTasks = [{ id: 1, isActive: true }]
			api.post.mockResolvedValue({
				roomTask: { id: 1, isActive: false },
				rewards: { energyCost: 3 },
			})

			await store.completeRoomTask(1)

			expect(schedulingStore.todayConsumedEnergy).toBe(5)
		})

		it('propagates server weekly goal snapshot to the game store', async () => {
			const { useGameStore } = await import('../../../src/store/game.js')
			const gameStore = useGameStore()

			store.roomTasks = [{ id: 1, isActive: true }]
			api.post.mockResolvedValue({
				roomTask: { id: 1, isActive: false },
				rewards: {
					energyCost: 3,
					weeklyGoal: {
						dueCount: 5,
						completedCount: 4,
						percent: 80,
						targetPercent: 80,
						achieved: true,
						weekStart: '2026-07-13T00:00:00+00:00',
						weekEnd: '2026-07-19T23:59:59+00:00',
					},
				},
			})

			await store.completeRoomTask(1)

			expect(gameStore.householdProgress.weeklyGoal.dueCount).toBe(5)
			expect(gameStore.householdProgress.weeklyGoal.completedCount).toBe(4)
			expect(gameStore.householdProgress.weeklyGoal.percent).toBe(80)
			expect(gameStore.householdProgress.weeklyGoal.achieved).toBe(true)
		})
	})

		describe('deleteRoomTask', () => {
			it('deletes task', async () => {
				store.roomTasks = [{ id: 1 }, { id: 2 }]
				api.delete.mockResolvedValue(undefined)

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

		describe('fetchFilteredDailyTasks', () => {
			it('fetches filtered daily tasks and stores them', async () => {
				const mockTasks = [
					{ roomTask: { id: 1, roomId: 10 }, task: { id: 1, name: 'Task 1' }, room: { id: 10, name: 'Kitchen' } },
					{ roomTask: { id: 2, roomId: 10 }, task: { id: 2, name: 'Task 2' }, room: { id: 10, name: 'Kitchen' } },
				]
				api.get.mockResolvedValue(mockTasks)

				const result = await store.fetchFilteredDailyTasks()

				expect(result).toEqual(mockTasks)
				expect(store.roomTasks).toHaveLength(2)
				expect(store.roomTasks[0].id).toBe(1)
			})

			it('updates existing tasks instead of duplicating', async () => {
				store.roomTasks = [{ id: 1, name: 'Old Task' }]
				const mockTasks = [
					{ roomTask: { id: 1, name: 'Updated Task' }, task: { id: 1 }, room: { id: 10 } },
				]
				api.get.mockResolvedValue(mockTasks)

				await store.fetchFilteredDailyTasks()

				expect(store.roomTasks).toHaveLength(1)
				expect(store.roomTasks[0].name).toBe('Updated Task')
			})

			it('throws error on failed request', async () => {
				api.get.mockRejectedValue(new Error('API error'))

				await expect(store.fetchFilteredDailyTasks()).rejects.toThrow('API error')
			})
		})

		describe('fetchRangeTasks', () => {
			it('calls the range endpoint and stores result', async () => {
				const mockData = {
					scheduled: [{ roomTask: { id: 1, nextDue: '2026-07-28' }, task: { name: 'T' }, room: { name: 'R' }, flexWindow: {} }],
					completed: [{ roomTaskId: 2, taskName: 'Done', roomName: 'R', completedAt: '2026-07-29' }],
				}
				api.get.mockResolvedValue(mockData)

				const result = await store.fetchRangeTasks('2026-07-28', '2026-08-03')

				expect(api.get).toHaveBeenCalledWith('/api/v1/room-tasks/range', { params: { from: '2026-07-28', to: '2026-08-03' } })
				expect(result).toEqual(mockData)
				expect(store.rangeTasks.scheduled).toHaveLength(1)
				expect(store.rangeTasks.completed).toHaveLength(1)
				expect(store.rangeTasks.from).toBe('2026-07-28')
				expect(store.rangeTasks.to).toBe('2026-08-03')
			})

			it('sets error and rethrows on failure', async () => {
				api.get.mockRejectedValue(new Error('Network'))

				await expect(store.fetchRangeTasks('2026-07-28', '2026-08-03')).rejects.toThrow('Network')
				expect(store.error).toBe('Network')
			})
		})

		describe('rangeTasksByDay', () => {
			it('groups scheduled by nextDue and completed by completedAt', () => {
				store.rangeTasks = {
					scheduled: [
						{ roomTask: { id: 1, nextDue: '2026-07-28T10:00:00' }, task: { name: 'A' }, room: { name: 'R' } },
						{ roomTask: { id: 2, nextDue: '2026-07-29T08:00:00' }, task: { name: 'B' }, room: { name: 'R' } },
					],
					completed: [
						{ roomTaskId: 3, taskName: 'C', roomName: 'R', completedAt: '2026-07-28T14:00:00' },
					],
					from: '2026-07-28',
					to: '2026-08-03',
				}

				const byDay = store.rangeTasksByDay
				expect(byDay['2026-07-28'].scheduled).toHaveLength(1)
				expect(byDay['2026-07-28'].completed).toHaveLength(1)
				expect(byDay['2026-07-29'].scheduled).toHaveLength(1)
			})

		it('returns empty object for empty range', () => {
			store.rangeTasks = { scheduled: [], completed: [], from: null, to: null }
			expect(store.rangeTasksByDay).toEqual({})
		})

		it('expands recurring tasks across their occurrences', () => {
			store.rangeTasks = {
				scheduled: [
					{
						roomTask: { id: 1, nextDue: '2026-07-29T10:00:00' },
						task: { name: 'Dishes', recurrenceMode: 'recurring' },
						room: { name: 'Kitchen' },
						flexWindow: {},
						occurrences: ['2026-07-29', '2026-07-30', '2026-07-31'],
					},
				],
				completed: [],
				from: '2026-07-29',
				to: '2026-08-04',
			}

			const byDay = store.rangeTasksByDay
			expect(byDay['2026-07-29'].scheduled).toHaveLength(1)
			expect(byDay['2026-07-30'].scheduled).toHaveLength(1)
			expect(byDay['2026-07-31'].scheduled).toHaveLength(1)
			// Each bucketed item carries the occurrence date for overdue calc.
			expect(byDay['2026-07-30'].scheduled[0].dueDate).toBe('2026-07-30')
			expect(byDay['2026-07-30'].scheduled[0].task.name).toBe('Dishes')
		})

		it('falls back to nextDue when occurrences are absent', () => {
			store.rangeTasks = {
				scheduled: [
					{ roomTask: { id: 1, nextDue: '2026-07-29T10:00:00' }, task: { name: 'A' }, room: { name: 'R' } },
				],
				completed: [],
				from: null,
				to: null,
			}

			const byDay = store.rangeTasksByDay
			expect(byDay['2026-07-29'].scheduled).toHaveLength(1)
		})
		})

		describe('reset clears rangeTasks', () => {
			it('resets rangeTasks to initial state', () => {
				store.rangeTasks = { scheduled: [{ id: 1 }], completed: [{ id: 2 }], from: '2026-01-01', to: '2026-01-07' }
				store.reset()
				expect(store.rangeTasks).toEqual({ scheduled: [], completed: [], from: null, to: null })
			})
		})
	})
})