/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, beforeEach, jest } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useSchedulingStore } from '../../../src/store/scheduling.js'

jest.mock('../../../src/services/api.js', () => ({
	api: {
		get: jest.fn(),
		put: jest.fn(),
		post: jest.fn(),
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

		describe('updateEnergyBudget', () => {
			it('updates energy budget', async () => {
				api.put.mockResolvedValue(undefined)

				await store.updateEnergyBudget('monday', 15)

				expect(store.energyBudget.monday).toBe(15)
			})

			it('throws error for invalid energy value', async () => {
				await expect(store.updateEnergyBudget('monday', 25)).rejects.toThrow('Energy budget must be between 1 and 20')
			})
		})

		describe('updateAvailability', () => {
			it('updates availability', async () => {
				api.put.mockResolvedValue(undefined)

				await store.updateAvailability('monday', false)

				expect(store.weekdayAvailability.monday).toBe(false)
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
				api.put.mockResolvedValue(undefined)

				await store.updateEnergyBudget('monday', 12)
				expect(store.loading).toBe(false)

				await store.updateAvailability('monday', false)
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