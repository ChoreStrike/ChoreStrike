/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, beforeEach, jest } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useSchedulingStore } from '../../../src/store/scheduling.js'
import { useRoomTasksStore } from '../../../src/store/roomTasks.js'
import { useTasksStore } from '../../../src/store/tasks.js'
import { useHouseStore } from '../../../src/store/house.js'
import { addDaysToKey, todayKeyInZone, weekdayOfKey } from '../../../src/utils/dateKeys.js'

jest.mock('../../../src/services/api.js', () => ({
	api: {
		get: jest.fn(),
		put: jest.fn(),
		post: jest.fn(),
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

describe('Scheduling Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSchedulingStore()
		jest.clearAllMocks()
	})

	describe('Initial State', () => {
		it('has correct initial state', () => {
			expect(store.memberAvailability).toEqual([])
			expect(store.loading).toBe(false)
			expect(store.error).toBeNull()
			expect(store.energyBudget.monday).toBe(10)
			expect(store.weekdayAvailability.monday).toBe(true)
			// CS-PLAN-065: the reader-less energy counter is gone (D7); the
			// plan aggregate starts idle (D2).
			expect(store).not.toHaveProperty('todayConsumedEnergy')
			expect(store.planStatus).toBe('idle')
			expect(store.planPreview).toBeNull()
			expect(store.maxDailyTasks).toBe(Infinity)
		})
	})

	describe('Getters', () => {
		it('totalWeeklyEnergy sums energy budget', () => {
			store.energyBudget = {
				monday: 10, tuesday: 10, wednesday: 10,
				thursday: 10, friday: 10, saturday: 15, sunday: 15,
			}
			expect(store.totalWeeklyEnergy).toBe(80)
		})

	})

	describe('Planned Energy & Overcapacity (CS-PLAN-040)', () => {
		// CS-PLAN-057: the store buckets in the house timezone (UTC while no
		// house is loaded, which is the deterministic default in these specs).
		// Helpers compute keys and fixtures against the same zone so the pins
		// are host-timezone independent.
		const dateKeyInDays = (offset) => addDaysToKey(todayKeyInZone('UTC'), offset)

		const weekdayNameOf = (dateKey) => weekdayOfKey(dateKey)

		const seedTaskDueOn = (roomTasksStore, tasksStore, { roomTaskId, taskId, name, energyCost, priority, offset }) => {
			tasksStore.tasks.push({ id: taskId, name, energyCost, priority })
			roomTasksStore.roomTasks.push({
				id: roomTaskId,
				taskId,
				roomId: 1,
				isActive: true,
				nextDue: `${dateKeyInDays(offset)}T12:00:00Z`,
			})
		}

		beforeEach(() => {
			useRoomTasksStore().$reset()
			useTasksStore().$reset()
			useHouseStore().$reset()
		})

		it('getCapacityForDay uses member totals when member availability is loaded', () => {
			store.memberAvailability = [
				{ userId: 'a', weekdayAvailability: { monday: true }, energyBudget: { monday: 8 } },
				{ userId: 'b', weekdayAvailability: { monday: true }, energyBudget: { monday: 5 } },
			]
			expect(store.getCapacityForDay('monday')).toBe(13)
		})

		it('getCapacityForDay falls back to own budget with weekend 15', () => {
			store.memberAvailability = []
			store.energyBudget = { monday: 6 }
			store.weekdayAvailability = { monday: true }

			expect(store.getCapacityForDay('monday')).toBe(6)
			expect(store.getCapacityForDay('saturday')).toBe(15)
			expect(store.getCapacityForDay('unknown-day')).toBe(10)
		})

		it('plannedEnergyForDay sums energy costs of active room tasks due that day', () => {
			const roomTasksStore = useRoomTasksStore()
			const tasksStore = useTasksStore()

			seedTaskDueOn(roomTasksStore, tasksStore, { roomTaskId: 1, taskId: 11, name: 'A', energyCost: 4, priority: 2, offset: 1 })
			seedTaskDueOn(roomTasksStore, tasksStore, { roomTaskId: 2, taskId: 12, name: 'B', energyCost: 3, priority: 3, offset: 1 })
			seedTaskDueOn(roomTasksStore, tasksStore, { roomTaskId: 3, taskId: 13, name: 'Inactive', energyCost: 9, priority: 1, offset: 1 })
			// Deactivate one of the three tasks.
			roomTasksStore.roomTasks[2].isActive = false
			seedTaskDueOn(roomTasksStore, tasksStore, { roomTaskId: 4, taskId: 14, name: 'Other day', energyCost: 7, priority: 2, offset: 2 })

			const targetKey = dateKeyInDays(1)
			expect(store.plannedEnergyForDay(targetKey)).toBe(7)
			expect(store.plannedEnergyForDay(dateKeyInDays(2))).toBe(7)
		})

		it('overcapacityDays is empty when planned stays under capacity', () => {
			const roomTasksStore = useRoomTasksStore()
			const tasksStore = useTasksStore()

			seedTaskDueOn(roomTasksStore, tasksStore, { roomTaskId: 1, taskId: 11, name: 'Small', energyCost: 2, priority: 2, offset: 1 })

			expect(store.overcapacityDays).toEqual([])
		})

		it('overcapacityDays lists deferred tasks lowest priority first', () => {
			const roomTasksStore = useRoomTasksStore()
			const tasksStore = useTasksStore()

			// Capacity for the day (no member data → own budget fallback).
			const offset = 1
			const dayName = weekdayNameOf(dateKeyInDays(offset))
			store.memberAvailability = []
			store.weekdayAvailability[dayName] = true
			store.energyBudget[dayName] = 2

			seedTaskDueOn(roomTasksStore, tasksStore, { roomTaskId: 1, taskId: 11, name: 'Low prio big', energyCost: 4, priority: 1, offset })
			seedTaskDueOn(roomTasksStore, tasksStore, { roomTaskId: 2, taskId: 12, name: 'High prio small', energyCost: 2, priority: 3, offset })
			seedTaskDueOn(roomTasksStore, tasksStore, { roomTaskId: 3, taskId: 13, name: 'Medium prio big', energyCost: 5, priority: 2, offset })

			const overloaded = store.overcapacityDays

			expect(overloaded).toHaveLength(1)
			const day = overloaded[0]
			expect(day.planned).toBe(11)
			expect(day.capacity).toBe(2)
			// Locked policy: explain which tasks are deferred FIRST —
			// lowest priority first.
			expect(day.deferred.map(task => task.title)).toEqual(['Low prio big', 'Medium prio big'])
		})
	})

	describe('Actions', () => {
		describe('fetchUserPreferences', () => {
			it('fetches preferences successfully (D7: no consumed-energy field)', async () => {
				const prefs = {
					energyBudget: { monday: 20 },
					weekdayAvailability: { monday: false },
					maxDailyTasks: null,
				}
				api.get.mockResolvedValue(prefs)

				await store.fetchUserPreferences()

				expect(store.energyBudget.monday).toBe(20)
				expect(store.weekdayAvailability.monday).toBe(false)
				// Decision D5: null (unset) means unlimited.
				expect(store.maxDailyTasks).toBe(Infinity)
			})

			it('maps a finite maxDailyTasks to the finite limit (D5)', async () => {
				api.get.mockResolvedValue({ maxDailyTasks: 4 })

				await store.fetchUserPreferences()

				expect(store.maxDailyTasks).toBe(4)
			})
		})

		describe('saveWeeklyPreferences', () => {
			const fullAvailability = {
				monday: true, tuesday: false, wednesday: true, thursday: true,
				friday: true, saturday: true, sunday: true,
			}
			const fullEnergy = {
				monday: 12, tuesday: 12, wednesday: 12, thursday: 12,
				friday: 12, saturday: 15, sunday: 15,
			}

			it('sends exactly one PUT with both sections and replaces state from the response', async () => {
				api.put.mockResolvedValue({
					weekdayAvailability: fullAvailability,
					energyBudget: fullEnergy,
				})

				await store.saveWeeklyPreferences({ weekdayAvailability: fullAvailability, energyBudget: fullEnergy })

				expect(api.put).toHaveBeenCalledTimes(1)
				expect(api.put).toHaveBeenCalledWith('/api/v1/scheduling/preferences', {
					weekdayAvailability: fullAvailability,
					energyBudget: fullEnergy,
				})
				expect(store.weekdayAvailability).toEqual(fullAvailability)
				expect(store.energyBudget).toEqual(fullEnergy)
			})

			it('sends only the provided section (energy-only payload)', async () => {
				api.put.mockResolvedValue({ energyBudget: fullEnergy })

				await store.saveWeeklyPreferences({ energyBudget: fullEnergy })

				expect(api.put).toHaveBeenCalledTimes(1)
				expect(api.put).toHaveBeenCalledWith('/api/v1/scheduling/preferences', {
					energyBudget: fullEnergy,
				})
				// Availability state untouched by the response.
				expect(store.weekdayAvailability.monday).toBe(true)
			})

			it('propagates errors and records them in state', async () => {
				api.put.mockRejectedValue(new Error('Request failed'))

				await expect(store.saveWeeklyPreferences({ energyBudget: fullEnergy })).rejects.toThrow('Request failed')

				expect(api.put).toHaveBeenCalledTimes(1)
				expect(store.error).toBe('Request failed')
			})
		})

		describe('fetchMemberAvailability', () => {
			it('fetches member availability', async () => {
				const members = [{ userId: 'user1' }, { userId: 'user2' }]
				api.get.mockResolvedValue(members)

				await store.fetchMemberAvailability()

				expect(store.memberAvailability).toEqual(members)
			})
		})

		describe('updateMemberPreferences (UX-014)', () => {
			it('PUTs the updates and merges them into the matching member', async () => {
				store.memberAvailability = [{ userId: 'user1', energyBudget: { monday: 10 } }]
				api.put.mockResolvedValue(undefined)

				await store.updateMemberPreferences('user1', { energyBudget: { monday: 15 } })

				expect(api.put).toHaveBeenCalledTimes(1)
				expect(api.put).toHaveBeenCalledWith('/api/v1/scheduling/members/user1', { energyBudget: { monday: 15 } })
				expect(store.memberAvailability[0].energyBudget.monday).toBe(15)
			})

			it('propagates errors and records them in state', async () => {
				api.put.mockRejectedValue(new Error('Forbidden'))

				await expect(store.updateMemberPreferences('user1', {})).rejects.toThrow('Forbidden')

				expect(store.error).toBe('Forbidden')
			})
		})

		describe('previewPlan (CS-PLAN-065)', () => {
			const input = { startDate: '2026-09-10', roomTaskIds: [11, 12] }

			it('POSTs the exact normalized input and stores the preview payload', async () => {
				const payload = {
					previewVersion: 'abc',
					startDate: '2026-09-10',
					endDateExclusive: '2026-09-17',
					timezone: 'UTC',
					changes: [],
					unchanged: [],
					unplaced: [],
					capacity: [],
					warnings: [],
				}
				api.post.mockResolvedValue(payload)

				const result = await store.previewPlan({ ...input })

				expect(api.post).toHaveBeenCalledWith('/api/v1/scheduling/suggestions', {
					startDate: '2026-09-10',
					roomTaskIds: [11, 12],
				})
				expect(result).toEqual(payload)
				expect(store.planPreview).toEqual(payload)
				expect(store.planInput).toEqual({ startDate: '2026-09-10', roomTaskIds: [11, 12], overrides: {} })
				expect(store.planStatus).toBe('ready')
				// A fresh preview is a fresh logical command (WP2 §3.5).
				expect(store.planCommandId).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/)
			})

			it('requires a fresh consent attestation for every preview (RF-073-WP2-06/13)', async () => {
				api.post.mockResolvedValue({ previewVersion: 'abc' })
				store.planConsent = true

				await store.previewPlan({ ...input })

				expect(store.planConsent).toBe(false)

				// The attestation is an explicit boolean decision.
				store.setPlanConsent('yes')
				expect(store.planConsent).toBe(false)
				store.setPlanConsent(true)
				expect(store.planConsent).toBe(true)
			})

			it('does not touch the shared loading flag nor the view-wide error (plan failures stay scoped)', async () => {
				api.post.mockRejectedValue(new Error('Bad request'))

				await expect(store.previewPlan({ ...input })).rejects.toThrow('Bad request')

				expect(store.loading).toBe(false)
				expect(store.planStatus).toBe('error')
				expect(store.error).toBeNull()
			})

			it('a superseded older response never replaces newer input', async () => {
				let resolveFirst
				api.post.mockImplementationOnce(() => new Promise((resolve) => { resolveFirst = resolve }))
				api.post.mockResolvedValueOnce({ previewVersion: 'newer' })

				const first = store.previewPlan({ ...input })
				await store.previewPlan({ ...input, roomTaskIds: [99] })
				resolveFirst({ previewVersion: 'older' })
				const olderResult = await first

				expect(olderResult).toBeNull()
				expect(store.planPreview).toEqual({ previewVersion: 'newer' })
				expect(store.planInput.roomTaskIds).toEqual([99])
			})
		})

		describe('applyPlan (CS-PLAN-065, CS-PLAN-073 WP2 §3.5)', () => {
			const setupReady = () => {
				store.planPreview = { previewVersion: 'abc', changes: [{ roomTaskId: 11 }] }
				store.planInput = { startDate: '2026-09-10', roomTaskIds: [11], overrides: {} }
				store.planStatus = 'ready'
				store.planCommandId = '11111111-2222-4333-8444-555555555555'
				store.planConsent = true
			}

			it('sends input + previewVersion + commandId + consent, reconciles rows, refreshes readers and reports applied', async () => {
				setupReady()
				api.post.mockImplementation(async (path) => {
					if (path === '/api/v1/scheduling/apply') {
						return { appliedCount: 1, windowStart: '2026-09-10', windowEndExclusive: '2026-09-17', roomTasks: [] }
					}
					return {}
				})

				const ok = await store.applyPlan()

				expect(ok).toBe(true)
				expect(api.post).toHaveBeenCalledWith('/api/v1/scheduling/apply', {
					startDate: '2026-09-10',
					roomTaskIds: [11],
					overrides: {},
					previewVersion: 'abc',
					commandId: '11111111-2222-4333-8444-555555555555',
					consent: true,
				})
				expect(store.planStatus).toBe('applied')
			})

			it('retains the same command UUID and consent across a failed attempt so a retry never repeats a transition', async () => {
				setupReady()
				const conflict = new Error('Conflict')
				conflict.status = 409
				api.post.mockRejectedValue(conflict)

				await expect(store.applyPlan()).rejects.toThrow('Conflict')

				// The draft keeps its command identity — a retry reuses the
				// SAME UUID instead of minting a fresh command — and the
				// recorded consent attestation survives the 409.
				expect(store.planCommandId).toBe('11111111-2222-4333-8444-555555555555')
				expect(store.planConsent).toBe(true)
				expect(store.planStatus).toBe('stale')
			})

			it('maps a 409 to the stale state so Apply stays disabled', async () => {
				setupReady()
				const conflict = new Error('Conflict')
				conflict.status = 409
				api.post.mockRejectedValue(conflict)

				await expect(store.applyPlan()).rejects.toThrow('Conflict')

				expect(store.planStatus).toBe('stale')
			})

			it('reports a committed apply with a failed refresh as refreshFailed', async () => {
				setupReady()
				api.post.mockImplementation(async (path) => {
					if (path === '/api/v1/scheduling/apply') {
						return { appliedCount: 1, roomTasks: [] }
					}
					return Promise.reject(new Error('post failure'))
				})
				api.get.mockRejectedValue(new Error('refetch failed'))

				const ok = await store.applyPlan()

				expect(ok).toBe(true)
				expect(store.planStatus).toBe('refreshFailed')
			})

			it('is a no-op without a confirmed preview', async () => {
				const ok = await store.applyPlan()

				expect(ok).toBe(false)
				expect(api.post).not.toHaveBeenCalled()
			})
		})

		describe('markPlanStale / resetPlan', () => {
			it('markPlanStale only downgrades a ready preview', () => {
				store.planStatus = 'ready'
				store.markPlanStale()
				expect(store.planStatus).toBe('stale')

				store.planStatus = 'applying'
				store.markPlanStale()
				expect(store.planStatus).toBe('applying')
			})

			it('resetPlan clears preview state and invalidates in-flight requests', async () => {
				store.planPreview = { previewVersion: 'x' }
				store.planInput = { startDate: '2026-09-10', roomTaskIds: [] }
				store.planStatus = 'ready'
				store.planConsent = true

				store.resetPlan()

				expect(store.planPreview).toBeNull()
				expect(store.planInput).toBeNull()
				expect(store.planStatus).toBe('idle')
				expect(store.planConsent).toBe(false)
			})
		})

		describe('loading flag ownership', () => {
			it('mutation actions do not toggle the shared loading flag', async () => {
				api.put.mockResolvedValue({})

				await store.saveWeeklyPreferences({
					weekdayAvailability: { ...store.weekdayAvailability },
				})
				expect(store.loading).toBe(false)
			})

			it('initializeScheduling owns the loading flag and survives owner-only member fetch failures', async () => {
				api.get.mockImplementation((path) => {
					if (path === '/api/v1/scheduling/preferences') {
						return Promise.resolve({ energyBudget: { monday: 11 } })
					}
					return Promise.reject(new Error('Forbidden'))
				})

				const promise = store.initializeScheduling()
				expect(store.loading).toBe(true)

				await promise

				expect(store.loading).toBe(false)
				expect(store.error).toBeNull()
				expect(store.energyBudget.monday).toBe(11)
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
			it('resets state to defaults', () => {
				store.memberAvailability = [{ userId: 'user1' }]
				store.energyBudget.monday = 20
				store.weekdayAvailability.monday = false
				store.loading = true
				store.error = 'Error'
				store.planPreview = { previewVersion: 'x' }
				store.planStatus = 'ready'

				store.reset()

				expect(store.memberAvailability).toEqual([])
				expect(store.energyBudget.monday).toBe(10)
				expect(store.weekdayAvailability.monday).toBe(true)
				expect(store.loading).toBe(false)
				expect(store.error).toBeNull()
				expect(store.planPreview).toBeNull()
				expect(store.planStatus).toBe('idle')
			})
		})
	})
})