/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, beforeEach, jest } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useSchedulingStore } from '../../../src/store/scheduling.js'
import { useRoomTasksStore } from '../../../src/store/roomTasks.js'
import { useTasksStore } from '../../../src/store/tasks.js'

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
			expect(store.todayConsumedEnergy).toBe(0)
			expect(store.loading).toBe(false)
			expect(store.error).toBeNull()
			expect(store.energyBudget.monday).toBe(10)
			expect(store.weekdayAvailability.monday).toBe(true)
		})
	})

	describe('Getters', () => {
		it('availableWeekdays returns available days', () => {
			store.weekdayAvailability = {
				monday: true, tuesday: false, wednesday: true,
			}
			expect(store.availableWeekdays).toContain('monday')
			expect(store.availableWeekdays).toContain('wednesday')
			expect(store.availableWeekdays).not.toContain('tuesday')
		})

		it('unavailableWeekdays returns unavailable days', () => {
			store.weekdayAvailability = {
				monday: true, tuesday: false, wednesday: true,
			}
			expect(store.unavailableWeekdays).toContain('tuesday')
			expect(store.unavailableWeekdays).not.toContain('monday')
		})

		it('totalWeeklyEnergy sums energy budget', () => {
			store.energyBudget = {
				monday: 10, tuesday: 10, wednesday: 10,
				thursday: 10, friday: 10, saturday: 15, sunday: 15,
			}
			expect(store.totalWeeklyEnergy).toBe(80)
		})

		it('isAvailableOn checks day availability', () => {
			store.weekdayAvailability = { monday: true, tuesday: false }
			expect(store.isAvailableOn('monday')).toBe(true)
			expect(store.isAvailableOn('tuesday')).toBe(false)
		})

		it('getEnergyForDay returns energy for day', () => {
			store.energyBudget = { monday: 15 }
			expect(store.getEnergyForDay('monday')).toBe(15)
		})

		it('getMemberAvailability finds member by userId', () => {
			store.memberAvailability = [
				{ userId: 'user1', name: 'User 1' },
				{ userId: 'user2', name: 'User 2' },
			]
			const member = store.getMemberAvailability('user2')
			expect(member.name).toBe('User 2')
		})

		it('getAvailableMembersForDay filters by day', () => {
			store.memberAvailability = [
				{ userId: 'user1', weekdayAvailability: { monday: true } },
				{ userId: 'user2', weekdayAvailability: { monday: false } },
			]
			const available = store.getAvailableMembersForDay('monday')
			expect(available).toHaveLength(1)
			expect(available[0].userId).toBe('user1')
		})

		it('todayRemainingEnergy should return full budget when no energy was consumed', () => {
			store.energyBudget = {
				monday: 10,
				tuesday: 10,
				wednesday: 10,
				thursday: 10,
				friday: 10,
				saturday: 10,
				sunday: 10,
			}
			store.todayConsumedEnergy = 0

			expect(store.todayRemainingEnergy).toBe(10)
		})

		it('todayRemainingEnergy should subtract consumed energy from budget', () => {
			store.energyBudget = {
				monday: 10,
				tuesday: 10,
				wednesday: 10,
				thursday: 10,
				friday: 10,
				saturday: 10,
				sunday: 10,
			}
			store.todayConsumedEnergy = 4

			expect(store.todayRemainingEnergy).toBe(6)
		})

		it('todayRemainingEnergy should clamp at zero when consumed energy exceeds budget', () => {
			store.energyBudget = {
				monday: 10,
				tuesday: 10,
				wednesday: 10,
				thursday: 10,
				friday: 10,
				saturday: 10,
				sunday: 10,
			}
			store.todayConsumedEnergy = 14

			expect(store.todayRemainingEnergy).toBe(0)
		})
	})

	describe('Planned Energy & Overcapacity (CS-PLAN-040)', () => {
		const localDateKey = (date) => {
			const y = date.getFullYear()
			const m = String(date.getMonth() + 1).padStart(2, '0')
			const d = String(date.getDate()).padStart(2, '0')
			return `${y}-${m}-${d}`
		}

		const dateKeyInDays = (offset) => {
			const date = new Date()
			date.setHours(0, 0, 0, 0)
			date.setDate(date.getDate() + offset)
			return localDateKey(date)
		}

		const weekdayNameOf = (dateKey) => {
			const names = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']
			return names[new Date(`${dateKey}T00:00:00`).getDay()]
		}

		const seedTaskDueOn = (roomTasksStore, tasksStore, { roomTaskId, taskId, name, energyCost, priority, offset }) => {
			tasksStore.tasks.push({ id: taskId, name, energyCost, priority })
			roomTasksStore.roomTasks.push({
				id: roomTaskId,
				taskId,
				roomId: 1,
				isActive: true,
				nextDue: new Date(new Date().setHours(12, 0, 0, 0) + offset * 24 * 60 * 60 * 1000).toISOString(),
			})
		}

		beforeEach(() => {
			useRoomTasksStore().$reset()
			useTasksStore().$reset()
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
			it('fetches preferences successfully', async () => {
				const prefs = {
					energyBudget: { monday: 20 },
					weekdayAvailability: { monday: false },
					todayConsumedEnergy: 3,
				}
				api.get.mockResolvedValue(prefs)

				await store.fetchUserPreferences()

				expect(store.energyBudget.monday).toBe(20)
				expect(store.weekdayAvailability.monday).toBe(false)
				expect(store.todayConsumedEnergy).toBe(3)
			})
		})

		describe('registerTodayEnergyConsumption', () => {
			it('should increase todayConsumedEnergy for valid positive costs', () => {
				store.todayConsumedEnergy = 2

				store.registerTodayEnergyConsumption(3)

				expect(store.todayConsumedEnergy).toBe(5)
			})

			it('should ignore invalid or non-positive costs', () => {
				store.todayConsumedEnergy = 2

				store.registerTodayEnergyConsumption(0)
				store.registerTodayEnergyConsumption(-1)
				store.registerTodayEnergyConsumption('3')

				expect(store.todayConsumedEnergy).toBe(2)
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

		describe('updateMemberAvailability', () => {
			it('updates member availability', async () => {
				store.memberAvailability = [{ userId: 'user1', energy: 10 }]
				api.put.mockResolvedValue(undefined)

				await store.updateMemberAvailability('user1', { energy: 15 })

				expect(store.memberAvailability[0].energy).toBe(15)
			})
		})

		describe('getSchedulingSuggestions', () => {
			it('normalizes the weekday-keyed backend payload into an array', async () => {
				api.post.mockResolvedValue({
					monday: { availableMembers: 2, totalEnergy: 20, recommendedTasks: 4 },
					saturday: { availableMembers: 1, totalEnergy: 15, recommendedTasks: 3 },
				})

				const result = await store.getSchedulingSuggestions([{ id: 1 }])

				expect(api.post).toHaveBeenCalledWith('/api/v1/scheduling/suggestions', { tasks: [{ id: 1 }] })
				expect(Array.isArray(result)).toBe(true)
				expect(result).toHaveLength(2)
				expect(result[0]).toMatchObject({
					day: 'monday',
					availableMembers: 2,
					totalEnergy: 20,
					recommendedTasks: 4,
				})
				expect(result[0].title).toBeTruthy()
				expect(result[0].description).toBeTruthy()
			})

			it('returns an empty array for an empty payload', async () => {
				api.post.mockResolvedValue({})

				const result = await store.getSchedulingSuggestions([])

				expect(result).toEqual([])
			})

			it('does not toggle the shared loading flag', async () => {
				api.post.mockResolvedValue({})

				await store.getSchedulingSuggestions([])

				expect(store.loading).toBe(false)
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
				store.todayConsumedEnergy = 9
				store.energyBudget.monday = 20
				store.weekdayAvailability.monday = false
				store.loading = true
				store.error = 'Error'

				store.reset()

				expect(store.memberAvailability).toEqual([])
				expect(store.todayConsumedEnergy).toBe(0)
				expect(store.energyBudget.monday).toBe(10)
				expect(store.weekdayAvailability.monday).toBe(true)
				expect(store.loading).toBe(false)
				expect(store.error).toBeNull()
			})
		})
	})
})