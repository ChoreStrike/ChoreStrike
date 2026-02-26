import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useHouseStore } from '../../../src/store/house.js'

// Mock axios
jest.mock('@nextcloud/axios', () => ({
	get: jest.fn(),
	put: jest.fn(),
	post: jest.fn(),
}))

import axios from '@nextcloud/axios'

describe('House Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useHouseStore()
		// Reset mocks
		jest.clearAllMocks()
	})

	describe('State', () => {
		it('has correct initial state', () => {
			expect(store.house).toBeNull()
			expect(store.loading).toBe(false)
			expect(store.error).toBeNull()
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
				axios.get.mockResolvedValue({
					data: { success: true, data: mockHouse }
				})

				await store.fetchHouse()

				expect(store.house).toEqual(mockHouse)
				expect(store.loading).toBe(false)
				expect(store.error).toBeNull()
			})

			it('sets loading state while fetching', async () => {
				axios.get.mockResolvedValue({
					data: { success: true, data: { id: 1 } }
				})

				const promise = store.fetchHouse()
				expect(store.loading).toBe(true)
				await promise
				expect(store.loading).toBe(false)
			})

			it('handles error response', async () => {
				axios.get.mockResolvedValue({
					data: { success: false, error: { message: 'Not found' } }
				})

				await expect(store.fetchHouse()).rejects.toThrow('Not found')
				expect(store.error).toBe('Not found')
			})

			it('handles network error', async () => {
				axios.get.mockRejectedValue(new Error('Network error'))

				await expect(store.fetchHouse()).rejects.toThrow('Network error')
				expect(store.loading).toBe(false)
			})
		})

		describe('updateHouseName', () => {
			it('updates house name successfully', async () => {
				const updatedHouse = { id: 1, name: 'New Name' }
				axios.put.mockResolvedValue({
					data: { success: true, data: updatedHouse }
				})

				await store.updateHouseName('New Name')

				expect(store.house).toEqual(updatedHouse)
				expect(axios.put).toHaveBeenCalledWith('/apps/chorewars/api/v1/house', { name: 'New Name' })
			})

			it('throws error for empty name', async () => {
				await expect(store.updateHouseName('')).rejects.toThrow('House name is required')
				await expect(store.updateHouseName('   ')).rejects.toThrow('House name is required')
			})
		})

		describe('fetchHouseStats', () => {
			it('fetches stats successfully', async () => {
				const mockStats = { totalRooms: 5, cleanliness: 80 }
				axios.get.mockResolvedValue({
					data: { success: true, data: mockStats }
				})

				const result = await store.fetchHouseStats()

				expect(result).toEqual(mockStats)
			})

			it('handles error', async () => {
				axios.get.mockResolvedValue({
					data: { success: false, error: { message: 'Failed' } }
				})

				await expect(store.fetchHouseStats()).rejects.toThrow('Failed')
			})
		})

		describe('calculateHouseHealth', () => {
			it('calculates and updates health', async () => {
				store.house = { id: 1, houseHealth: 50 }
				axios.get.mockResolvedValue({
					data: { success: true, data: { houseHealth: 75 } }
				})

				const result = await store.calculateHouseHealth()

				expect(result).toBe(75)
				expect(store.house.houseHealth).toBe(75)
			})

			it('returns health even without local house', async () => {
				axios.get.mockResolvedValue({
					data: { success: true, data: { houseHealth: 60 } }
				})

				const result = await store.calculateHouseHealth()

				expect(result).toBe(60)
			})
		})

		describe('resetHouse', () => {
			it('resets house successfully', async () => {
				const resetHouse = { id: 1, houseHealth: 100 }
				axios.post.mockResolvedValue({
					data: { success: true, data: resetHouse }
				})

				await store.resetHouse()

				expect(store.house).toEqual(resetHouse)
			})
		})

		describe('initializeHouse', () => {
			it('fetches house if not exists', async () => {
				const mockHouse = { id: 1 }
				axios.get.mockResolvedValue({
					data: { success: true, data: mockHouse }
				})

				const result = await store.initializeHouse()

				expect(result).toEqual(mockHouse)
				expect(axios.get).toHaveBeenCalled()
			})

			it('returns existing house without fetching', async () => {
				store.house = { id: 1, name: 'Existing' }

				const result = await store.initializeHouse()

				expect(result).toEqual(store.house)
				expect(axios.get).not.toHaveBeenCalled()
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
