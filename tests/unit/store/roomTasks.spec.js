/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, beforeEach, jest } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useRoomTasksStore } from '../../../src/store/roomTasks.js'
import { useHouseStore } from '../../../src/store/house.js'
import { useSchedulingStore } from '../../../src/store/scheduling.js'
import { useRoomsStore } from '../../../src/store/rooms.js'
import { useUserStore } from '../../../src/store/user.js'
import { useMinigameStore } from '../../../src/store/minigame.js'
import { useTasksStore } from '../../../src/store/tasks.js'
import { useTitlesStore } from '../../../src/store/titles.js'

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

// CS-PLAN-064 §3.5 aggregate shapes: completion totals describe the
// current user; completion undo with completerUserId describes the
// completer (the two differ on an owner undo of a child's completion).
const completionFixture = () => ({
	roomTask: { id: 1, roomId: 10, isActive: true, lastCompleted: '2026-09-06T10:00:00+00:00' },
	room: { id: 10, cleanliness: 100 },
	task: { id: 5 },
	rewards: {
		coins: 54,
		totalCoins: 64,
		totalContribution: 43,
		currentStreak: 2,
		longestStreak: 5,
		streakFreezesRemaining: 1,
		contributionScore: 3,
		energyCost: 3,
	},
})

const undoFixture = (completerUserId, roomTaskId = 7) => ({
	roomTask: { id: roomTaskId, roomId: 10, isActive: true, lastCompleted: null },
	room: { id: 10, cleanliness: 70 },
	task: { id: 5 },
	rewards: {
		coins: -54,
		totalCoins: 10,
		totalContribution: 40,
		currentStreak: 1,
		longestStreak: 4,
		streakFreezesRemaining: 1,
		completerUserId,
	},
	undone: true,
})

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

		// CS-PLAN-066 (D2): the counters read the DEDICATED due-today/overdue
		// projections — complete viewer-visible endpoint results (enriched
		// rows). Day bucketing/eligibility is server-owned; the store only
		// filters defensively on isActive.
		describe('totalDueTodayTasks / totalOverdueTasks (D2 count projections)', () => {
			it('counts the dedicated projection slices, not the room list', () => {
				store.roomTasks = [
					{ id: 1, roomId: 10, isActive: true, nextDue: '2026-08-30T12:00:00Z' },
					{ id: 2, roomId: 20, isActive: true, nextDue: '2026-08-30T12:00:00Z' },
				]
				store.dueTodayTasks = [
					{ roomTask: { id: 1, isActive: true } },
					{ roomTask: { id: 3, isActive: true } },
				]
				store.overdueTasks = [
					{ roomTask: { id: 2, isActive: true } },
				]

				expect(store.totalDueTodayTasks).toBe(2)
				expect(store.totalOverdueTasks).toBe(1)
			})

			it('a scoped room load can never masquerade as a count source', async () => {
				store.dueTodayTasks = [{ roomTask: { id: 1, isActive: true } }]
				api.get.mockResolvedValue([{ id: 9, roomId: 10, isActive: true, nextDue: '2026-08-30T12:00:00Z' }])

				await store.fetchRoomTasks(10)

				expect(store.totalDueTodayTasks).toBe(1)
			})

			it('the child-limited daily list can never overwrite count sources', async () => {
				store.dueTodayTasks = [{ roomTask: { id: 1, isActive: true } }]
				store.overdueTasks = [{ roomTask: { id: 2, isActive: true } }]
				api.get.mockResolvedValue([{ roomTask: { id: 1, isActive: true } }])

				await store.fetchFilteredDailyTasks()

				expect(store.totalDueTodayTasks).toBe(1)
				expect(store.totalOverdueTasks).toBe(1)
			})

			it('inactive rows are not counted between refreshes', () => {
				store.dueTodayTasks = [
					{ roomTask: { id: 1, isActive: false } },
					{ roomTask: { id: 3, isActive: true } },
				]
				store.overdueTasks = [{ roomTask: { id: 2, isActive: false } }]

				expect(store.totalDueTodayTasks).toBe(1)
				expect(store.totalOverdueTasks).toBe(0)
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

		describe('bulkAssignTasks (CS-PLAN-042, CR-SVC-61, CS-PLAN-066 D1)', () => {
			it('posts one bulk call and reconciles the returned successes only', async () => {
				store.roomTasks = [
					{ id: 1, assignees: [] },
					{ id: 2, assignees: [] },
				]
				// Canonical D1 shape: returned successes carry their PERSISTED
				// assignee set (user-ID array) — never the requested set.
				api.post.mockResolvedValue([
					{ id: 1, assignees: ['user1'] },
				])

				const assigned = await store.bulkAssignTasks([1, 2], 'user1')

				expect(api.post).toHaveBeenCalledWith('/api/v1/room-tasks/bulk-assign', {
					roomTaskIds: [1, 2],
					userId: 'user1',
				})
				expect(assigned).toEqual([{ id: 1, assignees: ['user1'] }])
				// The returned success reconciles the cached chip state.
				expect(store.roomTasks[0].assignees).toEqual(['user1'])
				// The skipped task stays untouched — no client-assumed success.
				expect(store.roomTasks[1].assignees).toEqual([])
			})

			it('reconciles only the server-persisted assignee set (no local union)', async () => {
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
			it('replaces assignees from the canonical user-ID response (D1)', async () => {
				store.roomTasks = [{ id: 1, assignees: [] }]
				// CS-PLAN-066 D1: the PUT response IS the persisted user-ID set.
				api.put.mockResolvedValue(['user1', 'user2'])

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

		describe('pruned split-undo seam (CS-PLAN-064 D5)', () => {
			it('no longer exposes uncompleteRoomTask or reactivateRoomTask', () => {
				expect(store.uncompleteRoomTask).toBeUndefined()
				expect(store.reactivateRoomTask).toBeUndefined()
			})
		})

		describe('completeRoomTask', () => {
			it('completes task', async () => {
				store.roomTasks = [{ id: 1, isActive: true }]
				api.post.mockResolvedValue({ roomTask: { id: 1, isActive: false } })

				const result = await store.completeRoomTask(1, 'user1')

				expect(result.roomTask.isActive).toBe(false)
			})

			it('reconciles every owning store BEFORE emitting task-completed (D10)', async () => {
				const { emit } = await import('@nextcloud/event-bus')
				const roomsStore = useRoomsStore()
				const userStore = useUserStore()
				const minigameStore = useMinigameStore()

				store.roomTasks = [{ id: 1, roomId: 10, isActive: true, assignees: ['mom'] }]
				roomsStore.rooms = [{ id: 10, cleanliness: 70 }]
				userStore.currentUser = { nextcloudUserId: 'mom', coins: 10, contributionScore: 40, currentStreak: 1, longestStreak: 4, streakFreezes: 1 }
				minigameStore.currentCoins = 10

				const stateAtEmit = {}
				emit.mockImplementation((event) => {
					if (event === 'chorestrike:task-completed') {
						stateAtEmit.roomTask = store.roomTasks[0]
						stateAtEmit.cleanliness = roomsStore.rooms[0].cleanliness
						stateAtEmit.coins = userStore.currentUser.coins
						stateAtEmit.wallet = minigameStore.currentCoins
					}
				})
				api.post.mockResolvedValue(completionFixture())

				await store.completeRoomTask(1)

				// At the moment the celebration event fires, every owning
				// store already carries the authoritative response values —
				// celebrations can no longer run on stale caches.
				expect(Object.keys(stateAtEmit)).toHaveLength(4)
				expect(stateAtEmit.roomTask.isActive).toBe(true)
				expect(stateAtEmit.cleanliness).toBe(100)
				expect(stateAtEmit.coins).toBe(64)
				expect(stateAtEmit.wallet).toBe(64)
				// The aggregate payload omits the assignee relation; the
				// cached membership must survive the reconcile.
				expect(stateAtEmit.roomTask.assignees).toEqual(['mom'])
				emit.mockReset()
			})

			it('reconciles the dailyTasks slice and gates user side effects on completer identity', async () => {
				const userStore = useUserStore()
				const minigameStore = useMinigameStore()
				userStore.currentUser = { nextcloudUserId: 'mom', coins: 10 }
				minigameStore.currentCoins = 10
				store.roomTasks = [{ id: 1, assignees: ['mom'] }]
				store.dailyTasks = [{ roomTask: { id: 1, isActive: true }, task: { id: 5 }, room: null }]
				api.post.mockResolvedValue(completionFixture())

				await store.completeRoomTask(1)

				// A completion's totals always describe the current user.
				expect(store.dailyTasks[0].roomTask.lastCompleted).toBe('2026-09-06T10:00:00+00:00')
				expect(userStore.currentUser.coins).toBe(64)
				expect(minigameStore.currentCoins).toBe(64)

				// An owner undoing a CHILD's completion restores the child's
				// state — the actor's own wallet and user row stay untouched.
				api.post.mockResolvedValue(undoFixture('teen', 1))

				await store.undoRoomTaskCompletion(1)

				expect(userStore.currentUser.coins).toBe(64)
				expect(minigameStore.currentCoins).toBe(64)
			})

		it('does not touch the removed scheduling energy counter (D7)', async () => {
			const schedulingStore = useSchedulingStore()
			store.roomTasks = [{ id: 1, isActive: true }]
			api.post.mockResolvedValue({
				roomTask: { id: 1, isActive: false },
				rewards: { energyCost: 3 },
			})

			await store.completeRoomTask(1)

			// CS-PLAN-065 (decision D7): the writer-only todayConsumedEnergy
			// cluster is gone — completion capacity is derived server-side.
			expect(schedulingStore).not.toHaveProperty('todayConsumedEnergy')
			expect(schedulingStore).not.toHaveProperty('registerTodayEnergyConsumption')
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

		describe('undoRoomTaskCompletion (CS-PLAN-064: one undo aggregate)', () => {
			it('posts ONE undo request, applies the authoritative payload, and emits task-uncompleted', async () => {
				const { emit } = await import('@nextcloud/event-bus')
				const roomsStore = useRoomsStore()
				const userStore = useUserStore()
				const minigameStore = useMinigameStore()

				store.roomTasks = [{ id: 7, roomId: 10, isActive: false, lastCompleted: '2026-09-06T10:00:00+00:00', assignees: ['mom'] }]
				store.dailyTasks = [{ roomTask: { id: 7, isActive: false }, task: { id: 5 }, room: null }]
				roomsStore.rooms = [{ id: 10, cleanliness: 100 }]
				userStore.currentUser = { nextcloudUserId: 'mom', coins: 64, contributionScore: 43, currentStreak: 2, longestStreak: 5, streakFreezes: 1 }
				minigameStore.currentCoins = 64
				api.post.mockResolvedValue(undoFixture('mom'))

				const result = await store.undoRoomTaskCompletion(7)

				// One request — the split uncomplete+reactivate chain is gone.
				expect(api.post).toHaveBeenCalledTimes(1)
				expect(api.post).toHaveBeenCalledWith('/api/v1/room-tasks/7/undo')
				expect(api.put).not.toHaveBeenCalled()

				// The restored server state reconciles every owning store…
				expect(store.roomTasks[0].isActive).toBe(true)
				expect(store.roomTasks[0].lastCompleted).toBeNull()
				expect(store.roomTasks[0].assignees).toEqual(['mom'])
				expect(store.dailyTasks[0].roomTask.isActive).toBe(true)
				expect(roomsStore.rooms[0].cleanliness).toBe(70)
				expect(userStore.currentUser.coins).toBe(10)
				expect(minigameStore.currentCoins).toBe(10)

				// …and the TodayView restore contract carries the final row.
				const uncompleted = emit.mock.calls.find(([event]) => event === 'chorestrike:task-uncompleted')
				expect(uncompleted).toBeDefined()
				expect(uncompleted[1].roomTaskId).toBe(7)
				expect(uncompleted[1].roomTask.isActive).toBe(true)
				expect(result.isActive).toBe(true)
			})

			it('treats the no-op undo (undone: false, rewards null) as a zero side-effect success', async () => {
				const roomsStore = useRoomsStore()
				const userStore = useUserStore()
				userStore.currentUser = { nextcloudUserId: 'mom', coins: 64 }
				store.roomTasks = [{ id: 8, roomId: 10, isActive: true }]
				roomsStore.rooms = [{ id: 10, cleanliness: 70 }]
				api.post.mockResolvedValue({
					roomTask: { id: 8, isActive: true, lastCompleted: null },
					room: { id: 10, cleanliness: 70 },
					task: { id: 5 },
					rewards: null,
					undone: false,
				})

				const result = await store.undoRoomTaskCompletion(8)

				expect(result.id).toBe(8)
				expect(roomsStore.rooms[0].cleanliness).toBe(70)
				expect(userStore.currentUser.coins).toBe(64)
			})

			it('propagates failures', async () => {
				api.post.mockRejectedValue(new Error('Offline'))

				await expect(store.undoRoomTaskCompletion(1)).rejects.toThrow('Offline')
				expect(store.error).toBe('Offline')
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

		it('a superseded older daily response never overwrites the newer result (§3.4)', async () => {
			let resolveFirst
			api.get.mockImplementationOnce(() => new Promise((resolve) => { resolveFirst = resolve }))
			api.get.mockResolvedValueOnce([{ roomTask: { id: 2 } }])

			const first = store.fetchFilteredDailyTasks()
			await store.fetchFilteredDailyTasks()
			resolveFirst([{ roomTask: { id: 1 } }])
			await first

			expect(store.dailyTasks).toEqual([{ roomTask: { id: 2 } }])
		})

		it('embedded assignees ride the payload (D4: My Day keeps canonical sets)', async () => {
			const mockTasks = [
				{ roomTask: { id: 1 }, task: { id: 1 }, room: { id: 10 }, assignees: ['dad'] },
			]
			api.get.mockResolvedValue(mockTasks)

			await store.fetchFilteredDailyTasks()

			expect(store.dailyTasks[0].assignees).toEqual(['dad'])
		})
	})

	describe('createTaskAggregate (CS-PLAN-063 §3.5, CS-PLAN-066 D1)', () => {
		it('posts ONE aggregate request, updates both stores, and returns the aggregate', async () => {
			api.post.mockResolvedValue({
				task: { id: 7, name: 'Dishes' },
				roomTask: { id: 42, roomId: 3, taskId: 7 },
				// CS-PLAN-066 D1: canonical unique, ordered user-ID array.
				assignees: ['mom', 'dad'],
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

		describe('createFromTemplate (CS-PLAN-063 D1 aggregate shape, CS-PLAN-066 D1 assignees)', () => {
		it('joins the room task, stores the canonical assignee set, and caches the definition', async () => {
			api.post.mockResolvedValue({
				task: { id: 9, name: 'Bathroom blitz' },
				roomTask: { id: 43, roomId: 3, taskId: 9 },
				assignees: ['mom'],
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

			it('a superseded older range response never overwrites the newer result (§3.4)', async () => {
				let resolveFirst
				api.get.mockImplementationOnce(() => new Promise((resolve) => { resolveFirst = resolve }))
				api.get.mockResolvedValueOnce({ scheduled: [{ roomTask: { id: 9 } }], completed: [] })

				const first = store.fetchRangeTasks('2026-07-28', '2026-08-03')
				await store.fetchRangeTasks('2026-07-28', '2026-08-03')
				resolveFirst({ scheduled: [{ roomTask: { id: 1 } }], completed: [] })
				await first

				expect(store.rangeTasks.scheduled).toEqual([{ roomTask: { id: 9 } }])
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

	// CS-PLAN-066: canonical payloads, D2 count projections, D3 coordinated
	// reconciliation, D5 title event, and §3.3 request generations.
	describe('WP7 — canonical state (CS-PLAN-066)', () => {
		describe('count projections (D2)', () => {
			it('fetchDueTodayTasks stores the complete viewer-visible enriched result', async () => {
				const rows = [
					{ roomTask: { id: 1, isActive: true }, task: { id: 1 }, room: { id: 10 }, assignees: ['mom'] },
				]
				api.get.mockResolvedValue(rows)

				const result = await store.fetchDueTodayTasks()

				expect(api.get).toHaveBeenCalledWith('/api/v1/room-tasks/due-today', {})
				expect(result).toEqual(rows)
				expect(store.dueTodayTasks).toEqual(rows)
				expect(store.dueTodayLoaded).toBe(true)
				expect(store.dueTodayStale).toBe(false)
			})

			it('fetchOverdueTasks stores the complete viewer-visible enriched result', async () => {
				const rows = [
					{ roomTask: { id: 2, isActive: true }, task: { id: 2 }, room: { id: 10 }, assignees: [] },
				]
				api.get.mockResolvedValue(rows)

				await store.fetchOverdueTasks()

				expect(store.overdueTasks).toEqual(rows)
				expect(store.overdueLoaded).toBe(true)
			})

			it('a superseded older count response never overwrites the newer one', async () => {
				let resolveFirst
				api.get.mockImplementationOnce(() => new Promise((resolve) => { resolveFirst = resolve }))
				api.get.mockResolvedValueOnce([{ roomTask: { id: 2, isActive: true } }])

				const first = store.fetchDueTodayTasks()
				await store.fetchDueTodayTasks()
				resolveFirst([{ roomTask: { id: 1, isActive: true } }])
				await first

				expect(store.dueTodayTasks).toEqual([{ roomTask: { id: 2, isActive: true } }])
			})
		})

		describe('per-room request generations (§3.3)', () => {
			it('valid parallel sibling room loads both land', async () => {
				api.get.mockImplementation((_url, config) => {
					const roomId = config.params.roomId
					return Promise.resolve([{ id: roomId * 100, roomId, isActive: true }])
				})

				await Promise.all([store.fetchRoomTasks(1), store.fetchRoomTasks(2)])

				expect(store.roomTasks.map(rt => rt.id).sort()).toEqual([100, 200])
			})

			it('a superseded same-room response never overwrites the newer one', async () => {
				let resolveFirst
				api.get.mockImplementationOnce(() => new Promise((resolve) => { resolveFirst = resolve }))
				api.get.mockResolvedValueOnce([{ id: 2, roomId: 10 }])

				const first = store.fetchRoomTasks(10)
				await store.fetchRoomTasks(10)
				resolveFirst([{ id: 1, roomId: 10 }])
				await first

				expect(store.roomTasks.map(rt => rt.id)).toEqual([2])
			})

			it('reset during an in-flight read discards the late result', async () => {
				let resolveFetch
				api.get.mockImplementation(() => new Promise((resolve) => { resolveFetch = resolve }))

				const pending = store.fetchRoomTasks(10)
				store.reset()
				resolveFetch([{ id: 1, roomId: 10 }])
				await pending

				expect(store.roomTasks).toEqual([])
			})

			it('a finishing read only clears loading when it is the last in-flight one', async () => {
				let resolveSecond
				api.get.mockResolvedValueOnce([{ id: 1, roomId: 1 }])
				api.get.mockImplementationOnce(() => new Promise((resolve) => { resolveSecond = resolve }))

				const first = store.fetchRoomTasks(1)
				const second = store.fetchRoomTasks(2)

				await first
				// The second read is still in flight — loading must stay on.
				expect(store.loading).toBe(true)

				resolveSecond([{ id: 2, roomId: 2 }])
				await second
				expect(store.loading).toBe(false)
			})
		})

		describe('completion title handoff (§3.4)', () => {
			it('passes the computed newTitle to the existing event exactly once after the titles store re-sync', async () => {
				const { emit } = await import('@nextcloud/event-bus')
				const titlesStore = useTitlesStore()
				titlesStore.lastHouseUserId = 5
				const invalidateSpy = jest.spyOn(titlesStore, 'invalidate').mockResolvedValue(undefined)

				const eventOrder = []
				emit.mockImplementation((event) => {
					if (event === 'chorestrike:title-unlocked') {
						eventOrder.push('title-unlocked')
					}
				})
				invalidateSpy.mockImplementation(async () => {
					eventOrder.push('invalidate')
				})

				store.roomTasks = [{ id: 1, isActive: true }]
				api.post.mockResolvedValue({
					roomTask: { id: 1, isActive: false },
					rewards: { coins: 5, newTitle: { pack: 'sparkling', labelKey: 'titles.sparkling.bronze', tierIndex: 0 } },
				})

				await store.completeRoomTask(1)

				expect(invalidateSpy).toHaveBeenCalledTimes(1)
				const titleEvents = emit.mock.calls.filter(([event]) => event === 'chorestrike:title-unlocked')
				expect(titleEvents).toHaveLength(1)
				expect(titleEvents[0][1]).toEqual({
					pack: 'sparkling',
					labelKey: 'titles.sparkling.bronze',
					tierIndex: 0,
				})
				// Titles store re-sync lands BEFORE consumers read it.
				expect(eventOrder).toEqual(['invalidate', 'title-unlocked'])
				emit.mockReset()
			})

			it('a titles re-sync failure never fails the committed completion (D3)', async () => {
				const titlesStore = useTitlesStore()
				titlesStore.lastHouseUserId = 5
				jest.spyOn(titlesStore, 'invalidate').mockRejectedValue(new Error('offline'))

				store.roomTasks = [{ id: 1, isActive: true }]
				api.post.mockResolvedValue({
					roomTask: { id: 1, isActive: false },
					rewards: { coins: 5, newTitle: { pack: 'p', labelKey: 'k', tierIndex: 1 } },
				})

				const data = await store.completeRoomTask(1)
				expect(data.roomTask.isActive).toBe(false)
			})

			it('a null newTitle emits no unlock event', async () => {
				const { emit } = await import('@nextcloud/event-bus')
				store.roomTasks = [{ id: 1, isActive: true }]
				api.post.mockResolvedValue({
					roomTask: { id: 1, isActive: false },
					rewards: { coins: 5, newTitle: null },
				})

				await store.completeRoomTask(1)

				expect(emit.mock.calls.filter(([event]) => event === 'chorestrike:title-unlocked')).toHaveLength(0)
				emit.mockReset()
			})
		})

		describe('definition reconciliation (§3.2)', () => {
			it('the returned definition merges into the definitions store by its separate ID', async () => {
				const tasksStore = useTasksStore()
				tasksStore.tasks = [{ id: 5, name: 'Old name' }]
				store.roomTasks = [{ id: 1, taskId: 5, isActive: true }]
				api.post.mockResolvedValue(completionFixture())

				await store.completeRoomTask(1)

				expect(tasksStore.tasks).toHaveLength(1)
				expect(tasksStore.getTaskById(5)).toEqual({ id: 5 })
			})
		})

		describe('coordinated derived refresh (D3)', () => {
			it('refreshes only the LOADED projections and reports the result', async () => {
				store.dueTodayLoaded = true
				store.overdueLoaded = false
				api.get.mockResolvedValue([])

				const ok = await store.refreshDerivedProjections()

				expect(ok).toBe(true)
				expect(api.get).toHaveBeenCalledTimes(1)
				expect(api.get).toHaveBeenCalledWith('/api/v1/room-tasks/due-today', {})
			})

			it('a refresh failure keeps the committed action, marks projections stale, and offers a refresh-only retry', async () => {
				store.roomTasks = [{ id: 1, isActive: true, assignees: ['mom'] }]
				store.dueTodayLoaded = true
				store.overdueLoaded = true
				api.post.mockResolvedValue({ roomTask: { id: 1, isActive: false }, rewards: { coins: 5 } })
				api.get.mockRejectedValueOnce(new Error('offline'))
				api.get.mockResolvedValueOnce([])

				const ok = await store.completeRoomTask(1).then(() => true, () => false)

				// The committed completion stays successful…
				expect(ok).toBe(true)
				expect(store.roomTasks[0].isActive).toBe(false)
				// …the failed projection is marked stale…
				expect(store.dueTodayStale).toBe(true)
				// …and the retry is a read-only re-run (no write replay).
				api.get.mockResolvedValue([])
				const retried = await store.refreshDerivedProjections()
				expect(retried).toBe(true)
				expect(store.refreshFailed).toBe(false)
				expect(api.post).toHaveBeenCalledTimes(1)
			})

			it('an instance mutation invalidates loaded counts and refreshes them', async () => {
				store.dueTodayLoaded = true
				store.dueTodayTasks = [{ roomTask: { id: 1, isActive: true } }]
				store.overdueLoaded = false
				api.delete.mockResolvedValue(undefined)
				api.get.mockResolvedValue([])

				await store.deleteRoomTask(1)

				expect(api.get).toHaveBeenCalledWith('/api/v1/room-tasks/due-today', {})
				expect(store.refreshFailed).toBe(false)
			})
		})
	})
})