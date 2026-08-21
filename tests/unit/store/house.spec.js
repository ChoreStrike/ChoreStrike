import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useHouseStore } from '../../../src/store/house.js'

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

describe('House Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useHouseStore()
		jest.clearAllMocks()
	})

	describe('State', () => {
		it('has correct initial state', () => {
			expect(store.house).toBeNull()
			expect(store.loading).toBe(false)
			expect(store.error).toBeNull()
			expect(store.insights).toBeNull()
			expect(store.activity).toEqual([])
			expect(store.activityLoading).toBe(false)
			expect(store.activityError).toBeNull()
		})
	})

	describe('Getters', () => {
		it('houseHealth returns default 100 when no house', () => {
			expect(store.houseHealth).toBe(100)
		})

		it('houseHealth returns house health when house exists', () => {
			store.house = { houseHealth: 85 }
			expect(store.houseHealth).toBe(85)
		})

		it('houseName returns default when no house', () => {
			expect(store.houseName).toBe('My House')
		})

		it('houseName returns house name when house exists', () => {
			store.house = { name: 'Test House' }
			expect(store.houseName).toBe('Test House')
		})

		it('hasHouse returns false when no house', () => {
			expect(store.hasHouse).toBe(false)
		})

		it('hasHouse returns true when house exists', () => {
			store.house = { id: 1 }
			expect(store.hasHouse).toBe(true)
		})
	})

	describe('Actions', () => {
		describe('fetchHouse', () => {
			it('fetches house successfully', async () => {
				const mockHouse = { id: 1, name: 'Test House', houseHealth: 90 }
				api.get.mockResolvedValue(mockHouse)

				await store.fetchHouse()

				expect(store.house).toEqual(mockHouse)
				expect(store.loading).toBe(false)
				expect(store.error).toBeNull()
			})

			it('sets loading state while fetching', async () => {
				api.get.mockResolvedValue({ id: 1 })

				const promise = store.fetchHouse()
				expect(store.loading).toBe(true)
				await promise
				expect(store.loading).toBe(false)
			})

			it('handles error response', async () => {
				api.get.mockRejectedValue(new Error('Not found'))

				await expect(store.fetchHouse()).rejects.toThrow('Not found')
				expect(store.error).toBe('Not found')
			})

			it('handles network error', async () => {
				api.get.mockRejectedValue(new Error('Network error'))

				await expect(store.fetchHouse()).rejects.toThrow('Network error')
				expect(store.loading).toBe(false)
			})
		})

		describe('updateHouseName', () => {
			it('updates house name successfully', async () => {
				const updatedHouse = { id: 1, name: 'New Name' }
				api.put.mockResolvedValue(updatedHouse)

				await store.updateHouseName('New Name')

				expect(store.house).toEqual(updatedHouse)
				expect(api.put).toHaveBeenCalledWith('/api/v1/house', { name: 'New Name' })
			})

			it('throws error for empty name', async () => {
				await expect(store.updateHouseName('')).rejects.toThrow('House name is required')
				await expect(store.updateHouseName('   ')).rejects.toThrow('House name is required')
			})
		})

		describe('fetchInsights', () => {
			it('fetches insights and stores them', async () => {
				const mockInsights = {
					house: { id: 1, name: 'Test House', houseHealth: 85 },
					stats: { totalRooms: 5, cleanRooms: 4, dirtyRooms: 1, roomsNeedingCleaning: 1, houseHealth: 85 },
				}
				api.get.mockResolvedValue(mockInsights)

				const result = await store.fetchInsights()

				expect(api.get).toHaveBeenCalledWith('/api/v1/house/stats')
				expect(result).toEqual(mockInsights)
				expect(store.insights).toEqual(mockInsights)
				expect(store.loading).toBe(false)
				expect(store.error).toBeNull()
			})

			it('handles error', async () => {
				api.get.mockRejectedValue(new Error('Failed'))

				await expect(store.fetchInsights()).rejects.toThrow('Failed')
				expect(store.error).toBe('Failed')
				expect(store.loading).toBe(false)
			})
		})

		describe('fetchActivity', () => {
			it('fetches activity and stores completions', async () => {
				const payload = {
					completions: [
						{ id: 1, taskTitle: 'Vacuum', displayName: 'Alice', energyCost: 3 },
					],
					total: 1,
				}
				api.get.mockResolvedValue(payload)

				const result = await store.fetchActivity()

				expect(api.get).toHaveBeenCalledWith('/api/v1/house/activity', { params: { days: 30, limit: 50 } })
				expect(result).toEqual(payload)
				expect(store.activity).toEqual(payload.completions)
				expect(store.activityLoading).toBe(false)
				expect(store.activityError).toBeNull()
			})

			it('forwards custom days/limit', async () => {
				api.get.mockResolvedValue({ completions: [], total: 0 })

				await store.fetchActivity(7, 10)

				expect(api.get).toHaveBeenCalledWith('/api/v1/house/activity', { params: { days: 7, limit: 10 } })
			})

			it('handles error into activityError without touching shared error state', async () => {
				api.get.mockRejectedValue(new Error('Boom'))

				await expect(store.fetchActivity()).rejects.toThrow('Boom')
				expect(store.activityError).toBe('Boom')
				expect(store.error).toBeNull()
				expect(store.activityLoading).toBe(false)
			})
		})

		describe('calculateHouseHealth', () => {
			it('calculates and updates health', async () => {
				store.house = { id: 1, houseHealth: 50 }
				api.get.mockResolvedValue({ houseHealth: 75 })

				const result = await store.calculateHouseHealth()

				expect(result).toBe(75)
				expect(store.house.houseHealth).toBe(75)
			})

			it('returns health even without local house', async () => {
				api.get.mockResolvedValue({ houseHealth: 60 })

				const result = await store.calculateHouseHealth()

				expect(result).toBe(60)
			})
		})

		describe('resetHouse', () => {
			it('resets house successfully', async () => {
				const resetHouse = { id: 1, houseHealth: 100 }
				api.post.mockResolvedValue(resetHouse)

				await store.resetHouse()

				expect(store.house).toEqual(resetHouse)
			})
		})

		describe('initializeHouse', () => {
			it('fetches house if not exists', async () => {
				const mockHouse = { id: 1 }
				api.get.mockResolvedValue(mockHouse)

				const result = await store.initializeHouse()

				expect(result).toEqual(mockHouse)
				expect(api.get).toHaveBeenCalled()
			})

			it('returns existing house without fetching', async () => {
				store.house = { id: 1, name: 'Existing' }

				const result = await store.initializeHouse()

				expect(result).toEqual(store.house)
				expect(api.get).not.toHaveBeenCalled()
			})
		})

		describe('clearError', () => {
			it('clears error state', () => {
				store.error = 'Some error'
				store.clearError()
				expect(store.error).toBeNull()
			})
		})

		describe('reset', () => {
			it('resets store state', () => {
				store.house = { id: 1 }
				store.loading = true
				store.error = 'Error'

				store.reset()

				expect(store.house).toBeNull()
				expect(store.loading).toBe(false)
				expect(store.error).toBeNull()
			})
		})
	})
})