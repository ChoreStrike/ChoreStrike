/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, beforeEach, jest } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useRoomTasksStore } from '../../../src/store/roomTasks.js'
import { useHouseStore } from '../../../src/store/house.js'
import { useSchedulingStore } from '../../../src/store/scheduling.js'
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

		// CS-PLAN-057: due-today/overdue bucket in the house timezone (UTC
		// while no house is loaded) and guard the missing-nextDue epoch.
		describe('totalDueTodayTasks / totalOverdueTasks', () => {
			let houseStore

			beforeEach(() => {
				houseStore = useHouseStore()
				jest.useFakeTimers()
			})

			afterEach(() => {
				jest.useRealTimers()
			})

			it('counts tasks whose house-local day key is today', () => {
				jest.setSystemTime(Date.UTC(2026, 7, 30, 10, 0, 0))
				houseStore.house = { id: 1, timezone: 'UTC' }
				store.roomTasks = [
					{ id: 1, isActive: true, nextDue: '2026-08-30T12:00:00Z' },
					{ id: 2, isActive: true, nextDue: '2026-08-31T12:00:00Z' },
					{ id: 3, isActive: false, nextDue: '2026-08-30T12:00:00Z' },
				]

				expect(store.totalDueTodayTasks).toBe(1)
			})

			it('honors the house timezone around local midnight', () => {
				jest.setSystemTime(Date.UTC(2026, 6, 14, 22, 30, 0))
				// Now is 22:30 UTC on the 14th = 00:30 on the 15th in Berlin.
				// A task due 15th 00:30 UTC (02:30 Berlin) is due today for the
				// Berlin house but still "tomorrow" for a UTC house.
				store.roomTasks = [
					{ id: 1, isActive: true, nextDue: '2026-07-15T00:30:00Z' },
				]

				houseStore.house = { id: 1, timezone: 'Europe/Berlin' }
				expect(store.totalDueTodayTasks).toBe(1)

				houseStore.house = { id: 1, timezone: 'UTC' }
				expect(store.totalDueTodayTasks).toBe(0)
			})

			it('treats a missing nextDue as never due (epoch guard, CR-P4-06)', () => {
				jest.setSystemTime(Date.UTC(2026, 7, 30, 10, 0, 0))
				houseStore.house = { id: 1, timezone: 'UTC' }
				store.roomTasks = [
					{ id: 1, isActive: true, nextDue: null },
					{ id: 2, isActive: true },
					{ id: 3, isActive: true, nextDue: '2026-08-30T12:00:00Z' },
				]

				expect(store.totalDueTodayTasks).toBe(1)
				// The null nextDue must not count as 1970-epoch overdue either.
				expect(store.totalOverdueTasks).toBe(0)
			})

			it('counts overdue tasks by instant comparison', () => {
				jest.setSystemTime(Date.UTC(2026, 7, 30, 10, 0, 0))
				houseStore.house = { id: 1, timezone: 'UTC' }
				store.roomTasks = [
					{ id: 1, isActive: true, nextDue: '2026-08-01T12:00:00Z' },
					{ id: 2, isActive: true, nextDue: '2026-09-01T12:00:00Z' },
					{ id: 3, isActive: false, nextDue: '2026-08-01T12:00:00Z' },
				]

				expect(store.totalOverdueTasks).toBe(1)
			})
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

		// CS-PLAN-063: the former createRoomTask action was folded into
		// createTaskAggregate (covered above).

		describe('bulkAssignTasks (CS-PLAN-042, CR-SVC-61)', () => {
			it('posts one bulk call and merges the assignment into cached tasks', async () => {
				store.roomTasks = [
					{ id: 1, assignees: [] },
					{ id: 2, assignees: [] },
				]
				api.post.mockResolvedValue([
					{ id: 1, assignees: [] },
				])

				const assigned = await store.bulkAssignTasks([1, 2], 'user1')

				expect(api.post).toHaveBeenCalledWith('/api/v1/room-tasks/bulk-assign', {
					roomTaskIds: [1, 2],
					userId: 'user1',
				})
				expect(assigned).toEqual([{ id: 1, assignees: [] }])
				// The assigned task's chip state gains the target member.
				expect(store.roomTasks[0].assignees).toEqual(['user1'])
				// The skipped task stays untouched.
				expect(store.roomTasks[1].assignees).toEqual([])
			})

			it('does not duplicate an existing assignee', async () => {
				store.roomTasks = [{ id: 1, assignees: ['user1'] }]
				api.post.mockResolvedValue([{ id: 1, assignees: ['user1'] }])

				await store.bulkAssignTasks([1], 'user1')

				expect(store.roomTasks[0].assignees).toEqual(['user1'])
			})

			it('throws for empty userId without calling the API', async () => {
				await expect(store.bulkAssignTasks([1], '  ')).rejects.toThrow('User ID is required')
				expect(api.post).not.toHaveBeenCalled()
			})

			it('throws when roomTaskIds is not an array', async () => {
				await expect(store.bulkAssignTasks(5, 'user1')).rejects.toThrow('Room task IDs must be an array')
				expect(api.post).not.toHaveBeenCalled()
			})

			it('propagates API errors', async () => {
				api.post.mockRejectedValue(new Error('boom'))

				await expect(store.bulkAssignTasks([1], 'user1')).rejects.toThrow('boom')
				expect(store.error).toBe('boom')
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

		describe('reactivateRoomTask', () => {
			it('reactivates task', async () => {
				store.roomTasks = [{ id: 1, isActive: false }]
				api.post.mockResolvedValue({ id: 1, isActive: true })

				const result = await store.reactivateRoomTask(1)

				expect(store.roomTasks[0].isActive).toBe(true)
				expect(result.isActive).toBe(true)
			})
		})

		describe('uncompleteRoomTask (CS-PLAN-041)', () => {
			it('calls the room-task uncomplete endpoint and updates the slices', async () => {
				store.roomTasks = [{ id: 1, isActive: false, lastCompleted: '2026-08-22T10:00:00Z' }]
				store.dailyTasks = [{ roomTask: { id: 1, isActive: false }, task: { id: 5 }, room: null }]
				api.put.mockResolvedValue({ id: 1, isActive: false, lastCompleted: null })

				const result = await store.uncompleteRoomTask(1)

				expect(api.put).toHaveBeenCalledWith('/api/v1/tasks/1/uncomplete')
				expect(result.lastCompleted).toBeNull()
				expect(store.roomTasks[0].lastCompleted).toBeNull()
				expect(store.dailyTasks[0].roomTask.lastCompleted).toBeNull()
			})

			it('propagates failures', async () => {
				api.put.mockRejectedValue(new Error('Offline'))

				await expect(store.uncompleteRoomTask(1)).rejects.toThrow('Offline')
				expect(store.error).toBe('Offline')
			})
		})

		describe('undoRoomTaskCompletion (CS-PLAN-041)', () => {
			it('restores an inactive one-time task via uncomplete + reactivate', async () => {
				store.roomTasks = [{ id: 7, isActive: false, lastCompleted: '2026-08-22T10:00:00Z' }]
				api.put.mockResolvedValue({ id: 7, isActive: false, lastCompleted: null })
				api.post.mockResolvedValue({ id: 7, isActive: true, lastCompleted: null })

				const result = await store.undoRoomTaskCompletion(7)

				expect(api.put).toHaveBeenCalledWith('/api/v1/tasks/7/uncomplete')
				expect(api.post).toHaveBeenCalledWith('/api/v1/room-tasks/7/reactivate')
				expect(result.isActive).toBe(true)
				expect(store.roomTasks[0].isActive).toBe(true)
			})

			it('skips reactivation when the task is still active after uncomplete', async () => {
				store.roomTasks = [{ id: 8, isActive: true }]
				api.put.mockResolvedValue({ id: 8, isActive: true, lastCompleted: null })

				await store.undoRoomTaskCompletion(8)

				expect(api.post).not.toHaveBeenCalled()
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
		it('fetches filtered daily tasks into the dedicated dailyTasks slice', async () => {
			const mockTasks = [
				{ roomTask: { id: 1, roomId: 10 }, task: { id: 1, name: 'Task 1' }, room: { id: 10, name: 'Kitchen' } },
				{ roomTask: { id: 2, roomId: 10 }, task: { id: 2, name: 'Task 2' }, room: { id: 10, name: 'Kitchen' } },
			]
			api.get.mockResolvedValue(mockTasks)

			const result = await store.fetchFilteredDailyTasks()

			expect(result).toEqual(mockTasks)
			expect(store.dailyTasks).toHaveLength(2)
			expect(store.dailyTasks[0].roomTask.id).toBe(1)
		})

		it('keeps the shared roomTasks list untouched (no Today bleed)', async () => {
			store.roomTasks = [{ id: 5, name: 'Room task' }]
			const mockTasks = [
				{ roomTask: { id: 1, name: 'Today task' }, task: { id: 1 }, room: { id: 10 } },
			]
			api.get.mockResolvedValue(mockTasks)

			await store.fetchFilteredDailyTasks()

			expect(store.roomTasks).toHaveLength(1)
			expect(store.roomTasks[0].id).toBe(5)
		})

		it('throws error on failed request', async () => {
			api.get.mockRejectedValue(new Error('API error'))

			await expect(store.fetchFilteredDailyTasks()).rejects.toThrow('API error')
		})
	})

	describe('createTaskAggregate (CS-PLAN-063 §3.5)', () => {
		it('posts ONE aggregate request, updates both stores, and returns the aggregate', async () => {
			api.post.mockResolvedValue({
				task: { id: 7, name: 'Dishes' },
				roomTask: { id: 42, roomId: 3, taskId: 7 },
				assignees: [{ userId: 'mom' }, { userId: 'dad' }],
			})
			store.roomTasks = []

			const tasksStore = useTasksStore()
			const result = await store.createTaskAggregate({ roomId: 3, task: { name: 'Dishes' }, assigneeUserIds: ['mom', 'dad'] })

			// One request, no follow-up PUT (the chain is gone).
			expect(api.post).toHaveBeenCalledTimes(1)
			expect(api.post).toHaveBeenCalledWith('/api/v1/room-tasks', { roomId: 3, task: { name: 'Dishes' }, assigneeUserIds: ['mom', 'dad'] })
			expect(api.put).not.toHaveBeenCalled()

			expect(result.task.id).toBe(7)
			expect(result.roomTask.id).toBe(42)
			expect(store.roomTasks).toHaveLength(1)
			expect(store.roomTasks[0].id).toBe(42)
			expect(store.roomTasks[0].assignees).toEqual(['mom', 'dad'])
			expect(tasksStore.getTaskById(7)).toBeTruthy()
		})

		it('does not duplicate an already-cached definition', async () => {
			api.post.mockResolvedValue({
				task: { id: 7, name: 'Dishes' },
				roomTask: { id: 42, roomId: 3, taskId: 7 },
				assignees: [],
			})

			const tasksStore = useTasksStore()
			tasksStore.tasks.push({ id: 7, name: 'Dishes' })

			await store.createTaskAggregate({ roomId: 3, task: { name: 'Dishes' }, assigneeUserIds: [] })

			expect(tasksStore.getTaskById(7)).toBeTruthy()
			expect(tasksStore.tasks.filter(t => t.id === 7)).toHaveLength(1)
		})

		it('propagates failures (nothing persisted server-side on error)', async () => {
			api.post.mockRejectedValue(new Error('Validation failed'))

			await expect(store.createTaskAggregate({ roomId: 3, task: { name: '' } })).rejects.toThrow('Validation failed')
			expect(store.roomTasks).toHaveLength(0)
		})
	})

		describe('createFromTemplate (CS-PLAN-063 D1 aggregate shape)', () => {
		it('joins the room task, maps assignees, and caches the definition', async () => {
			api.post.mockResolvedValue({
				task: { id: 9, name: 'Bathroom blitz' },
				roomTask: { id: 43, roomId: 3, taskId: 9 },
				assignees: [{ userId: 'mom' }],
			})
			store.roomTasks = []

			const tasksStore = useTasksStore()

			const result = await store.createFromTemplate({ roomId: 3, templateId: 'bathroom-blitz' })

			expect(api.post).toHaveBeenCalledTimes(1)
			expect(api.post).toHaveBeenCalledWith('/api/v1/room-tasks/from-template', { roomId: 3, templateId: 'bathroom-blitz' })
			expect(result.task.id).toBe(9)
			expect(store.roomTasks).toHaveLength(1)
			expect(store.roomTasks[0].id).toBe(43)
			expect(store.roomTasks[0].assignees).toEqual(['mom'])
			expect(tasksStore.getTaskById(9)).toBeTruthy()
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