/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, beforeEach, jest } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useRoomTasksStore } from '../../../src/store/roomTasks.js'
import { useRoomsStore } from '../../../src/store/rooms.js'
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

/**
 * Workflow request budgets at the REAL store boundary (CS-PLAN-067 §3.5
 * D5): the full Pinia stores run against a mocked API boundary and the
 * test asserts the exact request SET of the optimized workflows — the
 * frontend counterpart of the backend WorkflowQueryBudgetTest.
 *
 * Pinned regression: TaskManager's initialization used to issue one
 * GET /room-tasks per room (an N+1 of N+2 total requests); since
 * CS-PLAN-067 D1 it issues exactly ONE house-wide list request.
 */
describe('workflow request budgets (CS-PLAN-067 §3.5)', () => {
	let roomTasksStore
	let roomsStore
	let tasksStore

	const houseTask = (id, roomId) => ({
		id,
		roomId,
		taskId: 100 + id,
		assignees: [],
		isActive: true,
		nextDue: '2030-01-01T00:00:00Z',
	})

	beforeEach(() => {
		setActivePinia(createPinia())
		roomTasksStore = useRoomTasksStore()
		roomsStore = useRoomsStore()
		tasksStore = useTasksStore()
		jest.clearAllMocks()
	})

	describe('TaskManager initialization', () => {
		it('loads the flat list with exactly ONE house-wide request', async () => {
			const houseTasks = [houseTask(1, 10), houseTask(2, 20), houseTask(3, 30)]
			api.get.mockImplementation((url) => {
				if (url === '/api/v1/room-tasks') {
					return Promise.resolve(houseTasks)
				}
				if (url === '/api/v1/floors/with-rooms') {
					return Promise.resolve([])
				}
				if (url === '/api/v1/tasks') {
					return Promise.resolve([])
				}
				if (url === '/api/v1/room-tasks/due-today') {
					return Promise.resolve([])
				}
				if (url === '/api/v1/room-tasks/overdue') {
					return Promise.resolve([])
				}
				return Promise.reject(new Error(`unexpected request: ${url}`))
			})

			// The TaskManager.initializeTasks request set (CS-PLAN-067 D1):
			// floors/rooms + definitions + the three count/list projections —
			// with the instance list loaded via the ONE batched house read.
			await Promise.all([
				roomsStore.fetchFloorsWithRooms(),
				tasksStore.fetchTasks(),
				roomTasksStore.fetchDueTodayTasks(),
				roomTasksStore.fetchOverdueTasks(),
				roomTasksStore.fetchHouseRoomTasks(),
			])

			const roomTaskListCalls = api.get.mock.calls.filter(([url]) => url === '/api/v1/room-tasks')
			expect(roomTaskListCalls).toHaveLength(1)
			expect(roomTaskListCalls[0][1]).toEqual({ params: { activeOnly: true } })

			// NO per-room instance fetch may appear anywhere in the init set:
			// every /api/v1/room-tasks call carries ONLY the activeOnly param
			// (a roomId param would be the old per-room N+1 shape).
			const perRoomCalls = api.get.mock.calls.filter(([, config]) => config?.params && 'roomId' in config.params)
			expect(perRoomCalls).toHaveLength(0)

			// The store holds the full house set.
			expect(roomTasksStore.roomTasks).toEqual(houseTasks)
			expect(roomTasksStore.houseLoaded).toBe(true)
		})
	})

	describe('My Day (filtered daily)', () => {
		it('fetches the enriched daily rows in exactly ONE request', async () => {
			api.get.mockResolvedValueOnce([])

			await roomTasksStore.fetchFilteredDailyTasks()

			expect(api.get).toHaveBeenCalledTimes(1)
			expect(api.get).toHaveBeenCalledWith('/api/v1/room-tasks/filtered-daily')
		})
	})

	describe('room-scoped reads stay available for RoomDetail', () => {
		it('a room fetch updates only its room slice of a loaded house set', async () => {
			api.get.mockImplementation((url, config) => {
				if (url === '/api/v1/room-tasks') {
					if (config?.params?.roomId === 20) {
						return Promise.resolve([houseTask(2, 20)])
					}
					return Promise.resolve([houseTask(1, 10), houseTask(2, 20)])
				}
				return Promise.reject(new Error(`unexpected request: ${url}`))
			})

			await roomTasksStore.fetchHouseRoomTasks()
			expect(roomTasksStore.roomTasks).toHaveLength(2)

			await roomTasksStore.fetchRoomTasks(20)

			// House set stays intact; only room 20's slice was replaced.
			expect(roomTasksStore.roomTasks.map(rt => rt.id).sort()).toEqual([1, 2])
		})
	})
})
