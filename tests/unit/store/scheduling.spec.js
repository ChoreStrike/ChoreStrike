/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, beforeEach, jest } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useUserStore } from '../../../src/store/user.js'
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
			// CS-PLAN-065: the reader-less energy counter is gone (D7). The
			// applied plan went with ADR-022.
			expect(store).not.toHaveProperty('todayConsumedEnergy')
			expect(store).not.toHaveProperty('planStatus')
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
				// The projection is the END of the window; the load view
				// counts the task on its planned day.
				nextDue: `${dateKeyInDays(offset + 2)}T00:00:00Z`,
				cycle: { cycleKey: 'c1', expectedVersion: 0, state: 'upcoming', centreOn: dateKeyInDays(offset), windowEndOn: dateKeyInDays(offset + 2) },
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

		it('getOwnCapacityForDay ignores the household read and honours an unavailable day', () => {
			store.memberAvailability = [
				{ userId: 'a', weekdayAvailability: { monday: true }, energyBudget: { monday: 9 } },
				{ userId: 'b', weekdayAvailability: { monday: true }, energyBudget: { monday: 4 } },
			]
			store.energyBudget = { ...store.energyBudget, monday: 6, tuesday: 7 }
			store.weekdayAvailability = { ...store.weekdayAvailability, tuesday: false }

			expect(store.getOwnCapacityForDay('Monday')).toBe(6)
			expect(store.getOwnCapacityForDay('tuesday')).toBe(0)
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

			// RF-090-37: Today and the planning settings both read the
			// preferences; an older answer landing last must not replace the
			// newer one, or the form's watcher discards an unsaved edit.
			const deferredGet = () => {
				let resolve
				api.get.mockImplementationOnce(() => new Promise((done) => {
					resolve = done
				}))
				return (data) => resolve(data)
			}

			it('never lets an older read overwrite a newer one (RF-090-37)', async () => {
				const answerOlder = deferredGet()
				const answerNewer = deferredGet()
				const older = store.fetchUserPreferences()
				const newer = store.fetchUserPreferences()

				answerNewer({ energyBudget: { monday: 9 } })
				await newer
				const landed = store.energyBudget
				answerOlder({ energyBudget: { monday: 5 } })
				await older

				expect(store.energyBudget).toBe(landed)
				expect(store.energyBudget.monday).toBe(9)
			})

			it('lets the caller of an overtaken read wait for the winning read (RF-090-37)', async () => {
				const answerOlder = deferredGet()
				const answerNewer = deferredGet()
				let olderDone = false
				const older = store.fetchUserPreferences().then(() => {
					olderDone = true
				})
				const newer = store.fetchUserPreferences()

				answerOlder({ energyBudget: { monday: 5 } })
				await new Promise((resolve) => setTimeout(resolve, 0))
				expect(olderDone).toBe(false)
				expect(store.energyBudget.monday).toBe(10)

				answerNewer({ energyBudget: { monday: 9 } })
				await Promise.all([older, newer])
				expect(olderDone).toBe(true)
				expect(store.energyBudget.monday).toBe(9)
			})

			it('drops a read that started before an accepted save or a reset (RF-090-37)', async () => {
				const answerBeforeSave = deferredGet()
				const beforeSave = store.fetchUserPreferences()
				api.put.mockResolvedValue({ energyBudget: { monday: 12 } })
				await store.saveWeeklyPreferences({ energyBudget: { monday: 12 } })
				answerBeforeSave({ energyBudget: { monday: 5 } })
				await beforeSave
				expect(store.energyBudget.monday).toBe(12)

				const answerBeforeReset = deferredGet()
				const beforeReset = store.fetchUserPreferences()
				store.reset()
				answerBeforeReset({ energyBudget: { monday: 5 } })
				await beforeReset
				expect(store.energyBudget.monday).toBe(10)
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

		describe('loading flag ownership', () => {
			it('mutation actions do not toggle the shared loading flag', async () => {
				api.put.mockResolvedValue({})

				await store.saveWeeklyPreferences({
					weekdayAvailability: { ...store.weekdayAvailability },
				})
				expect(store.loading).toBe(false)
			})

			it('initializeScheduling reads the member availability for owners only', async () => {
				api.get.mockResolvedValue({})
				const userStore = useUserStore()

				userStore.currentUser = { id: 1, role: 'child' }
				await store.initializeScheduling()
				expect(api.get).not.toHaveBeenCalledWith('/api/v1/scheduling/members')

				userStore.currentUser = { id: 2, role: 'member' }
				await store.initializeScheduling()
				expect(api.get).not.toHaveBeenCalledWith('/api/v1/scheduling/members')

				userStore.currentUser = { id: 3, role: 'owner' }
				await store.initializeScheduling()
				expect(api.get).toHaveBeenCalledWith('/api/v1/scheduling/members')
				expect(store.loading).toBe(false)
			})

			it('initializeScheduling waits for the role on a reload, where the user is still loading', async () => {
				api.get.mockResolvedValue({})
				const userStore = useUserStore()
				userStore.currentUser = null
				userStore.initializeCurrentUser = jest.fn(async () => {
					userStore.currentUser = { id: 3, role: 'owner' }
				})

				await store.initializeScheduling()

				expect(api.get).toHaveBeenCalledWith('/api/v1/scheduling/members')
			})

			it('initializeScheduling treats a role it cannot read as no owner', async () => {
				api.get.mockResolvedValue({})
				const userStore = useUserStore()
				userStore.currentUser = null
				userStore.initializeCurrentUser = jest.fn().mockRejectedValue(new Error('down'))

				await store.initializeScheduling()

				expect(api.get).not.toHaveBeenCalledWith('/api/v1/scheduling/members')
				expect(store.loading).toBe(false)
			})

			it('initializeScheduling owns the loading flag and survives owner-only member fetch failures', async () => {
				useUserStore().currentUser = { id: 3, role: 'owner' }
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

				store.reset()

				expect(store.memberAvailability).toEqual([])
				expect(store.energyBudget.monday).toBe(10)
				expect(store.weekdayAvailability.monday).toBe(true)
				expect(store.loading).toBe(false)
				expect(store.error).toBeNull()
			})
		})
	})
})
