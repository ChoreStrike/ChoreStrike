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

// WP3 §3.6 aggregate shapes: the completion carries the frozen work
// evidence, the participants and the caller's OWN wallet; the undo totals
// describe the completer (null wallet on an owner undo of a child's work).
const completionFixture = () => ({
	completionId: 91,
	roomTask: { id: 1, roomId: 10, isActive: true, lastCompleted: '2026-09-06T10:00:00+00:00' },
	room: { id: 10, cleanliness: 100 },
	task: { id: 5 },
	rewards: {
		completion: { id: 91, version: 0, allocationRevision: 1, totalEnergy: 3, completedAt: '2026-09-06T10:00:00+00:00', undoneAt: null },
		participants: [{ userId: 'mom', sharePct: 100 }],
		wallet: { bookedMinor: 6400, reservedMinor: 0, availableMinor: 6400, fundingHold: false },
		contributionMinor: 4300,
		cleanlinessChange: 30,
		energyCost: 3,
		priority: 2,
	},
	replay: false,
})

const undoFixture = (completerUserId, roomTaskId = 7) => ({
	completionId: 92,
	roomTask: { id: roomTaskId, roomId: 10, isActive: true, lastCompleted: null },
	room: { id: 10, cleanliness: 70 },
	task: { id: 5 },
	rewards: {
		completion: { id: 92, version: 1, allocationRevision: 1, totalEnergy: 3, completedAt: '2026-09-06T10:00:00+00:00', undoneAt: '2026-09-06T11:00:00+00:00' },
		participants: [{ userId: completerUserId, sharePct: 100 }],
		wallet: completerUserId === 'mom'
			? { bookedMinor: 4000, reservedMinor: 0, availableMinor: 4000, fundingHold: false }
			: null,
		contributionMinor: completerUserId === 'mom' ? 4000 : null,
		cleanlinessChange: -30,
		energyCost: 3,
		priority: 2,
	},
	undone: true,
})

// CS-PLAN-072 WP1: the shown occurrence block a cached row must carry for
// completeRoomTask to resolve a receipt target.
const occurrenceFixture = (overrides = {}) => ({
	occurrenceId: '5:1',
	expectedVersion: 0,
	selectionToken: 'sel-token',
	...overrides,
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
		describe('WP2 client contract (CS-PLAN-073, RF-073-WP2-12/13)', () => {
			it('keeps paused/suspended agreed rows and their full occurrence block verbatim', async () => {
				const rows = [
					{
						id: 1,
						roomId: 10,
						isActive: true,
						occurrence: occurrenceFixture({
							suspended: true,
							suspensionEvidence: [{ pauseGroupId: 4, revision: 2 }],
							workDate: null,
							workZone: null,
						}),
					},
					{
						id: 2,
						roomId: 10,
						isActive: true,
						occurrence: occurrenceFixture({
							occurrenceId: '5:2',
							workDate: '2026-10-06',
							workZone: 'Europe/Berlin',
						}),
					},
				]
				api.get.mockResolvedValue(rows)

				await store.fetchRoomTasks(10)

				// Paused/overloaded agreed rows are never dropped or
				// rewritten client-side — the rendered DTO travels verbatim.
				expect(store.roomTasks).toEqual(rows)
				expect(store.roomTasks.filter((row) => row.occurrence?.suspended)).toHaveLength(1)
				expect(store.roomTasks[1].occurrence.workDate).toBe('2026-10-06')
				expect(store.roomTasks[1].occurrence.workZone).toBe('Europe/Berlin')
			})
		})

		describe('fetchHouseRoomTasks (CS-PLAN-067 D1)', () => {
			it('requests the house-wide list without a roomId and REPLACES the instance set', async () => {
				store.roomTasks = [{ id: 1, roomId: 10 }]
				const houseTasks = [{ id: 1, roomId: 10 }, { id: 2, roomId: 20 }]
				api.get.mockResolvedValue(houseTasks)

				const result = await store.fetchHouseRoomTasks()

				expect(api.get).toHaveBeenCalledWith('/api/v1/room-tasks', { params: { activeOnly: true } })
				expect(store.roomTasks).toEqual(houseTasks)
				expect(result).toEqual(houseTasks)
				expect(store.houseLoaded).toBe(true)
				expect(store.houseStale).toBe(false)
			})

			it('drops a superseded house read without touching the set', async () => {
				// First read hangs; second read resolves with newer data.
				let releaseFirst
				api.get.mockImplementationOnce(() => new Promise(resolve => { releaseFirst = resolve }))
				api.get.mockResolvedValueOnce([{ id: 2 }])
				const first = store.fetchHouseRoomTasks()
				const second = store.fetchHouseRoomTasks()
				await second

				releaseFirst([{ id: 1 }])
				await first

				// The stale first response must not overwrite the accepted result.
				expect(store.roomTasks).toEqual([{ id: 2 }])
			})

			it('marks the house projection stale on an instance mutation', async () => {
				store.houseLoaded = true
				store.houseStale = false

				store.onInstanceMutated()

				expect(store.houseStale).toBe(true)
			})
		})

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

		// CS-PLAN-067 §3.4: the unused fetchAssignees action was removed —
		// rows already carry their canonical assignee arrays (CS-PLAN-066
		// D1); no production consumer needed the per-task endpoint read.

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

		describe('updateRoomTaskDueDate (RF-058-09)', () => {
			it('preserves cached assignees when the response omits them', async () => {
				store.roomTasks = [{
					id: 7,
					nextDue: '2030-05-09T00:00:00Z',
					assignees: ['user1', 'user2'],
				}]
				// The due-date response is the serialized entity only — no
				// `assignees` key.
				api.put.mockResolvedValue({ id: 7, nextDue: '2030-05-10T00:00:00Z' })

				await store.updateRoomTaskDueDate(7, '2030-05-10')

				expect(api.put).toHaveBeenCalledWith(
					'/api/v1/room-tasks/7/due-date',
					{ nextDue: '2030-05-10' },
				)
				expect(store.roomTasks[0].nextDue).toBe('2030-05-10T00:00:00Z')
				expect(store.roomTasks[0].assignees).toEqual(['user1', 'user2'])
			})

			it('adopts response assignees when the payload carries them', async () => {
				store.roomTasks = [{ id: 7, assignees: ['user1'] }]
				api.put.mockResolvedValue({ id: 7, assignees: ['user2'] })

				await store.updateRoomTaskDueDate(7, '2030-05-10')

				expect(store.roomTasks[0].assignees).toEqual(['user2'])
			})
		})

		describe('completeRoomTask', () => {
			it('completes task', async () => {
				store.roomTasks = [{ id: 1, isActive: true, occurrence: occurrenceFixture() }]
				api.post.mockResolvedValue({ roomTask: { id: 1, isActive: false } })

				const result = await store.completeRoomTask(1, 'user1')

				expect(result.roomTask.isActive).toBe(false)
			})

			it('joins a concurrent completion of the same room task instead of sending twice (CS-PLAN-084 WP-E)', async () => {
				store.roomTasks = [{ id: 1, isActive: true, occurrence: occurrenceFixture() }]
				let respond
				api.post.mockImplementation(() => new Promise(resolve => { respond = resolve }))

				const first = store.completeRoomTask(1)
				const second = store.completeRoomTask(1)
				expect(store.isCompleting(1)).toBe(true)
				expect(store.isCompleting(2)).toBe(false)

				await Promise.resolve()
				respond({ roomTask: { id: 1, isActive: false } })
				const [firstResult, secondResult] = await Promise.all([first, second])

				expect(api.post).toHaveBeenCalledTimes(1)
				expect(secondResult).toBe(firstResult)
				expect(store.isCompleting(1)).toBe(false)
			})

			it('clears the in-flight mark after a failed completion so a retry can run', async () => {
				store.roomTasks = [{ id: 1, isActive: true, occurrence: occurrenceFixture() }]
				const rejected = new Error('conflict')
				rejected.status = 409
				api.post.mockRejectedValueOnce(rejected)

				await expect(store.completeRoomTask(1)).rejects.toThrow('conflict')

				expect(store.isCompleting(1)).toBe(false)
				expect(store.completingIds).toEqual({})
			})

			it('reset() releases an in-flight completion so a new session can complete', async () => {
				store.roomTasks = [{ id: 1, isActive: true, occurrence: occurrenceFixture() }]
				let failStale
				api.post.mockImplementationOnce(() => new Promise((resolve, reject) => { failStale = reject }))
				const stale = store.completeRoomTask(1).catch(() => null)
				expect(store.isCompleting(1)).toBe(true)

				store.reset()
				expect(store.isCompleting(1)).toBe(false)

				store.roomTasks = [{ id: 1, isActive: true, occurrence: occurrenceFixture() }]
				let respond
				api.post.mockImplementationOnce(() => new Promise(resolve => { respond = resolve }))
				const fresh = store.completeRoomTask(1)
				await Promise.resolve()
				// The stale request settling must not clear the fresh one's mark.
				failStale(new Error('aborted'))
				await stale
				expect(store.isCompleting(1)).toBe(true)

				respond({ roomTask: { id: 1, isActive: false } })
				await fresh
				expect(api.post).toHaveBeenCalledTimes(2)
				expect(store.isCompleting(1)).toBe(false)
			})

			it('sends the occurrence receipt with a fresh commandId (CS-PLAN-072 WP1)', async () => {
				store.roomTasks = [{ id: 1, isActive: true, occurrence: occurrenceFixture({ occurrenceId: '9:3', expectedVersion: 2, selectionToken: 'tok-abc' }) }]
				api.post.mockResolvedValue({ roomTask: { id: 1, isActive: false } })

				await store.completeRoomTask(1)

				const [url, body] = api.post.mock.calls[0]
				expect(url).toBe('/api/v1/room-tasks/1/complete')
				expect(body.occurrenceId).toBe('9:3')
				expect(body.expectedVersion).toBe(2)
				expect(body.selectionToken).toBe('tok-abc')
				expect(body.commandId).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i)
			})

			it('generates a NEW commandId per logical completion command', async () => {
				store.roomTasks = [{ id: 1, isActive: true, occurrence: occurrenceFixture() }]
				// The real aggregate always returns a fresh selection block,
				// so the cached row keeps a resolvable target between calls.
				api.post.mockResolvedValue({ roomTask: { id: 1, isActive: true }, occurrence: occurrenceFixture() })

				await store.completeRoomTask(1)
				await store.completeRoomTask(1)

				const first = api.post.mock.calls[0][1].commandId
				const second = api.post.mock.calls[1][1].commandId
				expect(first).not.toBe(second)
			})

			it('reuses the SAME commandId when an uncertain-response retry hits the same target (RF-072-WP1-07)', async () => {
				store.roomTasks = [{ id: 1, isActive: true, occurrence: occurrenceFixture() }]
				// First attempt: network failure with NO status — the server may
				// still have committed, the response is uncertain.
				api.post.mockRejectedValueOnce(new Error('network unreachable'))
				api.post.mockResolvedValueOnce({ roomTask: { id: 1, isActive: false } })

				await store.completeRoomTask(1).then(() => null, () => null)
				await store.completeRoomTask(1)

				expect(api.post).toHaveBeenCalledTimes(2)
				expect(api.post.mock.calls[1][1].commandId).toBe(api.post.mock.calls[0][1].commandId)
			})

			it('keeps the pending commandId on a 5xx response and replays it on retry', async () => {
				store.roomTasks = [{ id: 1, isActive: true, occurrence: occurrenceFixture() }]
				const serverError = new Error('internal error')
				serverError.status = 503
				api.post.mockRejectedValueOnce(serverError)
				api.post.mockResolvedValueOnce({ roomTask: { id: 1, isActive: false } })

				await store.completeRoomTask(1).then(() => null, () => null)
				await store.completeRoomTask(1)

				expect(api.post.mock.calls[1][1].commandId).toBe(api.post.mock.calls[0][1].commandId)
			})

			it('clears the pending commandId after a definitive success', async () => {
				store.roomTasks = [{ id: 1, isActive: true, occurrence: occurrenceFixture() }]
				api.post.mockResolvedValue({ roomTask: { id: 1, isActive: true }, occurrence: occurrenceFixture() })

				await store.completeRoomTask(1)
				expect(store.pendingCommands).toEqual({})

				// The retry of a DIFFERENT logical command gets a fresh UUID.
				const first = api.post.mock.calls[0][1].commandId
				await store.completeRoomTask(1)
				expect(api.post.mock.calls[1][1].commandId).not.toBe(first)
			})

			it('clears the pending commandId on a definitive 409 so the next attempt carries a new command', async () => {
				store.roomTasks = [{ id: 1, isActive: true, occurrence: occurrenceFixture() }]
				const conflict = new Error('conflict')
				conflict.status = 409
				api.post.mockRejectedValue(conflict)
				jest.spyOn(store, 'refreshDerivedProjections').mockResolvedValue(undefined)

				await store.completeRoomTask(1).then(() => null, () => null)
				expect(store.pendingCommands).toEqual({})
			})

			it('reset() discards pending commands', async () => {
				store.roomTasks = [{ id: 1, isActive: true, occurrence: occurrenceFixture() }]
				api.post.mockRejectedValue(new Error('offline'))

				await store.completeRoomTask(1).then(() => null, () => null)
				expect(Object.keys(store.pendingCommands)).toHaveLength(1)

				store.reset()
				expect(store.pendingCommands).toEqual({})
			})

			it('throws STALE_TARGET without a request when no occurrence block is cached', async () => {
				store.roomTasks = [{ id: 1, isActive: true }]
				api.post.mockClear()

				await expect(store.completeRoomTask(1)).rejects.toMatchObject({ code: 'STALE_TARGET' })
				expect(api.post).not.toHaveBeenCalled()
			})

			it('refreshes derived projections on a 409 stale-target conflict', async () => {
				store.roomTasks = [{ id: 1, isActive: true, occurrence: occurrenceFixture() }]
				const conflict = new Error('The task changed since it was displayed. Reload and try again.')
				conflict.status = 409
				api.post.mockRejectedValue(conflict)
				const refreshSpy = jest.spyOn(store, 'refreshDerivedProjections').mockResolvedValue(undefined)

				await expect(store.completeRoomTask(1)).rejects.toBe(conflict)
				expect(refreshSpy).toHaveBeenCalled()
			})

			it('stores the fresh occurrence block from the completion response', async () => {
				store.roomTasks = [{ id: 1, isActive: true, occurrence: occurrenceFixture() }]
				store.dailyTasks = [{ roomTask: { id: 1, isActive: true }, task: { id: 5 }, room: null, occurrence: occurrenceFixture() }]
				api.post.mockResolvedValue({
					roomTask: { id: 1, isActive: true },
					occurrence: occurrenceFixture({ occurrenceId: '5:2', expectedVersion: 0, selectionToken: 'next-tok' }),
				})

				await store.completeRoomTask(1)

				expect(store.roomTasks[0].occurrence.occurrenceId).toBe('5:2')
				expect(store.dailyTasks[0].occurrence.selectionToken).toBe('next-tok')
			})

		it('reconciles every owning store BEFORE emitting task-completed (D10)', async () => {
			const { emit } = await import('@nextcloud/event-bus')
			const roomsStore = useRoomsStore()
			const userStore = useUserStore()

			store.roomTasks = [{ id: 1, roomId: 10, isActive: true, assignees: ['mom'], occurrence: occurrenceFixture() }]
			roomsStore.rooms = [{ id: 10, cleanliness: 70 }]
			userStore.currentUser = { nextcloudUserId: 'mom', contributionMinor: 4000, wallet: null }

			const stateAtEmit = {}
			emit.mockImplementation((event) => {
				if (event === 'chorestrike:task-completed') {
					stateAtEmit.roomTask = store.roomTasks[0]
					stateAtEmit.cleanliness = roomsStore.rooms[0].cleanliness
					stateAtEmit.contribution = userStore.currentUser.contributionMinor
					stateAtEmit.wallet = userStore.currentUser.wallet
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
			// WP3 §3.6: the own-wallet block and the derived contribution
			// minor total describe the current user.
			expect(stateAtEmit.contribution).toBe(4300)
			expect(stateAtEmit.wallet.bookedMinor).toBe(6400)
			// The aggregate payload omits the assignee relation; the
			// cached membership must survive the reconcile.
			expect(stateAtEmit.roomTask.assignees).toEqual(['mom'])
			emit.mockReset()
		})

		it('reconciles the dailyTasks slice and gates user side effects on completer identity', async () => {
			const userStore = useUserStore()
			userStore.currentUser = { nextcloudUserId: 'mom', contributionMinor: 4000, wallet: null }
			store.roomTasks = [{ id: 1, assignees: ['mom'], occurrence: occurrenceFixture() }]
			store.dailyTasks = [{ roomTask: { id: 1, isActive: true }, task: { id: 5 }, room: null }]
			api.post.mockResolvedValue(completionFixture())

			await store.completeRoomTask(1)

			// A completion's wallet/contribution totals always describe the
			// current user (WP3: exact minor units, no coin integers).
			expect(store.dailyTasks[0].roomTask.lastCompleted).toBe('2026-09-06T10:00:00+00:00')
			expect(userStore.currentUser.contributionMinor).toBe(4300)
			expect(userStore.currentUser.wallet.bookedMinor).toBe(6400)

			// An owner undoing a CHILD's completion restores the child's
			// state — the actor's own wallet and user row stay untouched
			// (the completer-describing payload carries a null own wallet).
			api.post.mockResolvedValue(undoFixture('teen', 1))

			await store.undoRoomTaskCompletion(92, 1)

			expect(userStore.currentUser.contributionMinor).toBe(4300)
			expect(userStore.currentUser.wallet.bookedMinor).toBe(6400)
		})

		it('does not touch the removed scheduling energy counter (D7)', async () => {
			const schedulingStore = useSchedulingStore()
			store.roomTasks = [{ id: 1, isActive: true, occurrence: occurrenceFixture() }]
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

				store.roomTasks = [{ id: 1, isActive: true, occurrence: occurrenceFixture() }]
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

	describe('undoRoomTaskCompletion (WP3 §3.4: targeted correction)', () => {
		it('posts ONE undo request, applies the authoritative payload, and emits task-uncompleted', async () => {
			const { emit } = await import('@nextcloud/event-bus')
			const roomsStore = useRoomsStore()
			const userStore = useUserStore()

			store.roomTasks = [{ id: 7, roomId: 10, isActive: false, lastCompleted: '2026-09-06T10:00:00+00:00', assignees: ['mom'] }]
			store.dailyTasks = [{ roomTask: { id: 7, isActive: false }, task: { id: 5 }, room: null }]
			roomsStore.rooms = [{ id: 10, cleanliness: 100 }]
			userStore.currentUser = { nextcloudUserId: 'mom', contributionMinor: 4300, wallet: { bookedMinor: 6400, availableMinor: 6400 } }
			api.post.mockResolvedValue(undoFixture('mom'))

			const result = await store.undoRoomTaskCompletion(92, 1)

			// One request — the targeted correction with its receipt.
			expect(api.post).toHaveBeenCalledTimes(1)
			expect(api.post).toHaveBeenCalledWith('/api/v1/completions/92/undo', {
				commandId: expect.any(String),
				expectedVersion: 1,
				reasonCode: null,
			})
			expect(api.put).not.toHaveBeenCalled()

			// The restored server state reconciles every owning store…
			expect(store.roomTasks[0].isActive).toBe(true)
			expect(store.roomTasks[0].lastCompleted).toBeNull()
			expect(store.roomTasks[0].assignees).toEqual(['mom'])
			expect(store.dailyTasks[0].roomTask.isActive).toBe(true)
			expect(roomsStore.rooms[0].cleanliness).toBe(70)
			expect(userStore.currentUser.contributionMinor).toBe(4000)
			expect(userStore.currentUser.wallet.bookedMinor).toBe(4000)

			// …and the TodayView restore contract carries the final row.
			const uncompleted = emit.mock.calls.find(([event]) => event === 'chorestrike:task-uncompleted')
			expect(uncompleted).toBeDefined()
			expect(uncompleted[1].roomTaskId).toBe(7)
			expect(uncompleted[1].roomTask.isActive).toBe(true)
			expect(result.roomTask.isActive).toBe(true)
		})

		it('treats the no-op undo (undone: false, null own wallet) as a zero side-effect success', async () => {
			const roomsStore = useRoomsStore()
			const userStore = useUserStore()
			userStore.currentUser = { nextcloudUserId: 'mom', contributionMinor: 4300, wallet: { bookedMinor: 6400 } }
			store.roomTasks = [{ id: 8, roomId: 10, isActive: true }]
			roomsStore.rooms = [{ id: 10, cleanliness: 70 }]
			api.post.mockResolvedValue({
				completionId: 93,
				roomTask: { id: 8, isActive: true, lastCompleted: null },
				room: { id: 10, cleanliness: 70 },
				task: { id: 5 },
				rewards: {
					completion: { id: 93, version: 0, totalEnergy: 3, undoneAt: '2026-09-06T11:00:00+00:00' },
					wallet: null,
					contributionMinor: null,
				},
				undone: false,
			})

			const result = await store.undoRoomTaskCompletion(93, 0)

			expect(result.roomTask.id).toBe(8)
			expect(roomsStore.rooms[0].cleanliness).toBe(70)
			expect(userStore.currentUser.contributionMinor).toBe(4300)
		})

			it('propagates failures', async () => {
				api.post.mockRejectedValue(new Error('Offline'))

				await expect(store.undoRoomTaskCompletion(1)).rejects.toThrow('Offline')
				expect(store.error).toBe('Offline')
			})
		})

			describe('WP3 §3.6 completion correction reads/writes', () => {
			it('fetchCompletion reads the viewer-safe completion model', async () => {
				const model = {
					id: 92,
					version: 1,
					allocationRevision: 2,
					canCorrect: true,
					allocation: [{ userId: 'mom', displayName: 'Mom', sharePct: 100, energyMinor: 300, coinMinor: 3000 }],
					history: [],
				}
				api.get.mockResolvedValue(model)

				const result = await store.fetchCompletion(92)

				expect(api.get).toHaveBeenCalledWith('/api/v1/completions/92')
				expect(result).toEqual(model)
			})

			it('attributeCompletion sends the receipt-corrected split and reconciles the payload', async () => {
				const userStore = useUserStore()
				userStore.currentUser = { nextcloudUserId: 'mom', contributionMinor: 3000, wallet: { bookedMinor: 3000 } }
				store.roomTasks = [{ id: 7, roomId: 10, isActive: true, assignees: ['mom'] }]
				api.put.mockResolvedValue({
					completionId: 92,
					roomTask: { id: 7, isActive: true, lastCompleted: '2026-09-06T10:00:00+00:00' },
					rewards: {
						completion: { id: 92, version: 2, allocationRevision: 3, totalEnergy: 3 },
						wallet: { bookedMinor: 1500, reservedMinor: 0, availableMinor: 1500, fundingHold: false },
						contributionMinor: 1500,
					},
				})

				const result = await store.attributeCompletion(92, {
					participants: [{ userId: 'mom', sharePct: 50 }, { userId: 'dad', sharePct: 50 }],
					expectedVersion: 1,
					reasonCode: 'wrong_split',
				})

				expect(api.put).toHaveBeenCalledTimes(1)
				expect(api.put).toHaveBeenCalledWith('/api/v1/completions/92/attribution', {
					commandId: expect.any(String),
					expectedVersion: 1,
					participants: [{ userId: 'mom', sharePct: 50 }, { userId: 'dad', sharePct: 50 }],
					allocationReviewed: true,
					reasonCode: 'wrong_split',
				})

				// The completed state never flips; the own wallet re-derives
				// from the corrected payload.
				expect(result.completionId).toBe(92)
				expect(store.roomTasks[0].isActive).toBe(true)
				expect(userStore.currentUser.contributionMinor).toBe(1500)
			})

			it('attributeCompletion propagates a 409 without mutating state (draft stays with the caller)', async () => {
				const conflict = new Error('The completion changed since it was displayed')
				conflict.status = 409
				api.put.mockRejectedValue(conflict)
				store.roomTasks = [{ id: 7, isActive: true }]

				await expect(store.attributeCompletion(92, {
					participants: [{ userId: 'mom', sharePct: 100 }],
					expectedVersion: 1,
				})).rejects.toThrow('changed since it was displayed')

				expect(store.error).toBe('The completion changed since it was displayed')
				expect(store.roomTasks[0].isActive).toBe(true)
				expect(store.pendingAttributions).toEqual({})
			})

			it('replays the same attribution UUID and frozen payload after a lost response and a refetch', async () => {
				const participants = [{ userId: 'mom', sharePct: 60 }, { userId: 'dad', sharePct: 40 }]
				const offline = new Error('response lost after commit')
				api.put.mockRejectedValueOnce(offline)
				api.get.mockResolvedValueOnce({ id: 92, version: 2, allocationRevision: 3 })
				api.put.mockResolvedValueOnce({ completionId: 92, replay: true })

				await expect(store.attributeCompletion(92, { participants, expectedVersion: 1 })).rejects.toBe(offline)
				participants[0].sharePct = 99
				await store.fetchCompletion(92)
				await store.attributeCompletion(92, {
					participants: [{ userId: 'mom', sharePct: 60 }, { userId: 'dad', sharePct: 40 }],
					expectedVersion: 1,
				})

				const first = api.put.mock.calls[0][1]
				const replay = api.put.mock.calls[1][1]
				expect(first.commandId).toMatch(/^[0-9a-f-]{36}$/i)
				expect(replay).toEqual(first)
				expect(store.pendingAttributions).toEqual({})
			})

			it('blocks a changed draft while an uncertain attribution command is pending', async () => {
				api.put.mockRejectedValueOnce(Object.assign(new Error('server unavailable'), { status: 503 }))
				await expect(store.attributeCompletion(92, {
					participants: [{ userId: 'mom', sharePct: 100 }], expectedVersion: 1,
				})).rejects.toThrow('server unavailable')
				await expect(store.attributeCompletion(92, {
					participants: [{ userId: 'dad', sharePct: 100 }], expectedVersion: 2,
				})).rejects.toMatchObject({ code: 'PENDING_ATTRIBUTION' })
				expect(api.put).toHaveBeenCalledTimes(1)
				store.reset()
				expect(store.pendingAttributions).toEqual({})
			})
		})

		describe('deleteRoomTask', () => {
			it('deletes task', async () => {
				store.roomTasks = [{ id: 1 }, { id: 2 }]
				api.delete.mockResolvedValue(undefined)

				await store.deleteRoomTask(1)

				expect(store.roomTasks).toHaveLength(1)
			})

			it('also removes a deleted finished task from the inactive read', async () => {
				store.inactiveRoomTasks = [{ id: 3, isActive: false }, { id: 4, isActive: false }]
				api.delete.mockResolvedValue(undefined)

				await store.deleteRoomTask(3)

				expect(store.finishedRoomTasks.map(row => row.id)).toEqual([4])
			})
		})

		describe('fetchInactiveHouseRoomTasks (CS-PLAN-084 D-084-07)', () => {
			it('reads with activeOnly=false and keeps only finished rows apart from the active set', async () => {
				const active = [{ id: 1, isActive: true }]
				store.roomTasks = active
				api.get.mockResolvedValue([{ id: 1, isActive: true }, { id: 5, isActive: false }])

				const result = await store.fetchInactiveHouseRoomTasks()

				expect(api.get).toHaveBeenCalledWith('/api/v1/room-tasks', { params: { activeOnly: false } })
				expect(result).toEqual([{ id: 5, isActive: false }])
				expect(store.roomTasks).toEqual(active)
				expect(store.inactiveRoomTasks).toEqual([{ id: 5, isActive: false }])
				expect(store.houseLoaded).toBe(false)
			})

			it('drops a superseded read and rethrows failures', async () => {
				let releaseFirst
				api.get.mockImplementationOnce(() => new Promise(resolve => { releaseFirst = resolve }))
				api.get.mockResolvedValueOnce([{ id: 6, isActive: false }])
				const first = store.fetchInactiveHouseRoomTasks()
				await store.fetchInactiveHouseRoomTasks()
				releaseFirst([{ id: 5, isActive: false }])
				await first
				expect(store.inactiveRoomTasks).toEqual([{ id: 6, isActive: false }])

				api.get.mockRejectedValueOnce(new Error('down'))
				await expect(store.fetchInactiveHouseRoomTasks()).rejects.toThrow('down')
				expect(store.inactiveRoomTasks).toEqual([{ id: 6, isActive: false }])
			})

			it('lists rows finished since the read first, once, and clears on reset', () => {
				store.roomTasks = [{ id: 1, isActive: true }, { id: 2, isActive: false, nextDue: 'new' }]
				store.inactiveRoomTasks = [{ id: 2, isActive: false, nextDue: 'old' }, { id: 3, isActive: false }]

				expect(store.finishedRoomTasks).toEqual([
					{ id: 2, isActive: false, nextDue: 'new' },
					{ id: 3, isActive: false },
				])

				store.reset()
				expect(store.inactiveRoomTasks).toEqual([])
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

				store.roomTasks = [{ id: 1, isActive: true, occurrence: occurrenceFixture() }]
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

				store.roomTasks = [{ id: 1, isActive: true, occurrence: occurrenceFixture() }]
				api.post.mockResolvedValue({
					roomTask: { id: 1, isActive: false },
					rewards: { coins: 5, newTitle: { pack: 'p', labelKey: 'k', tierIndex: 1 } },
				})

				const data = await store.completeRoomTask(1)
				expect(data.roomTask.isActive).toBe(false)
			})

			it('a null newTitle emits no unlock event', async () => {
				const { emit } = await import('@nextcloud/event-bus')
				store.roomTasks = [{ id: 1, isActive: true, occurrence: occurrenceFixture() }]
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
				store.roomTasks = [{ id: 1, taskId: 5, isActive: true, occurrence: occurrenceFixture() }]
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
				store.roomTasks = [{ id: 1, isActive: true, assignees: ['mom'], occurrence: occurrenceFixture() }]
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
