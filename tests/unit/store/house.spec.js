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
		let uuidSequence = 0
		Object.defineProperty(globalThis.crypto, 'randomUUID', {
			configurable: true,
			value: jest.fn(() => `12345678-1234-4234-8234-${String(++uuidSequence).padStart(12, '0')}`),
		})
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

		it('houseHealth returns 0 for a critical house (nullish, not falsy fallback)', () => {
			store.house = { houseHealth: 0 }
			expect(store.houseHealth).toBe(0)
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

		// CS-PLAN-057: the timezone getter feeds every day-key computation.
		it('timezone returns UTC fallback when no house', () => {
			expect(store.timezone).toBe('UTC')
		})

		it('timezone returns the house timezone when loaded', () => {
			store.house = { id: 1, timezone: 'Europe/Berlin' }
			expect(store.timezone).toBe('Europe/Berlin')
		})

		it('timezone falls back to UTC for a house without timezone', () => {
			store.house = { id: 1 }
			expect(store.timezone).toBe('UTC')
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

			// CS-PLAN-057: the timezone select saves through the same endpoint.
			it('sends the timezone when provided', async () => {
				const updatedHouse = { id: 1, name: 'Test House', timezone: 'Europe/Berlin' }
				api.put.mockResolvedValue(updatedHouse)

				await store.updateHouseName('Test House', 'Europe/Berlin')

				expect(api.put).toHaveBeenCalledWith('/api/v1/house', { name: 'Test House', timezone: 'Europe/Berlin' })
				expect(store.timezone).toBe('Europe/Berlin')
			})

			it('omits the timezone from the payload when not provided', async () => {
				api.put.mockResolvedValue({ id: 1, name: 'New Name' })

				await store.updateHouseName('New Name', null)

				expect(api.put).toHaveBeenCalledWith('/api/v1/house', { name: 'New Name' })
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

			// CS-PLAN-066 §3.2/§3.3: the health refresh and the full house
			// read share one generation — the newer request wins, and a
			// health refresh patches ONLY houseHealth onto the loaded house.
			it('patches only houseHealth, never clobbering fresher name/timezone fields', async () => {
				store.house = { id: 1, name: 'Old', timezone: 'UTC', houseHealth: 50 }
				api.get.mockResolvedValue({ houseHealth: 88 })

				await store.calculateHouseHealth()

				expect(store.house.houseHealth).toBe(88)
				expect(store.house.name).toBe('Old')
				expect(store.house.timezone).toBe('UTC')
			})

			it('a stale house fetch cannot overwrite a newer health refresh', async () => {
				let resolveFetch
				api.get.mockImplementationOnce(() => new Promise((resolve) => { resolveFetch = resolve }))
				api.get.mockResolvedValueOnce({ houseHealth: 88 })

				const houseFetch = store.fetchHouse()
				await store.calculateHouseHealth()
				store.house = { id: 1, name: 'Fresh', houseHealth: 88 }
				resolveFetch({ id: 1, name: 'Stale', houseHealth: 10 })
				await houseFetch

				// The superseded pre-refresh fetch result is dropped.
				expect(store.house.name).toBe('Fresh')
				expect(store.house.houseHealth).toBe(88)
			})

			it('an accepted house mutation invalidates pre-mutation reads', async () => {
				let resolveFetch
				api.get.mockImplementationOnce(() => new Promise((resolve) => { resolveFetch = resolve }))
				api.put.mockResolvedValue({ id: 1, name: 'Renamed' })

				const houseFetch = store.fetchHouse()
				await store.updateHouseName('Renamed')
				resolveFetch({ id: 1, name: 'Stale' })
				await houseFetch

				expect(store.house.name).toBe('Renamed')
			})

			it('reset during an in-flight read discards the late result', async () => {
				let resolveFetch
				api.get.mockImplementation(() => new Promise((resolve) => { resolveFetch = resolve }))

				const pending = store.fetchHouse()
				store.reset()
				resolveFetch({ id: 1, name: 'Late' })
				await pending

				expect(store.house).toBeNull()
			})
		})

		describe('resetHouse', () => {
			it('posts a receipt UUID and the displayed version, then clears the pending command', async () => {
				store.house = { id: 1, stateVersion: 7, houseHealth: 50 }
				const resetHouse = { id: 1, stateVersion: 8, houseHealth: 100 }
				api.post.mockResolvedValue(resetHouse)

				await store.resetHouse()

				expect(api.post).toHaveBeenCalledWith('/api/v1/house/reset', {
					commandId: expect.stringMatching(/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i),
					expectedVersion: 7,
				})
				expect(store.house).toEqual(resetHouse)
				expect(store.pendingResetCommand).toBeNull()
			})

			it('retries an uncertain reset with the same UUID and original version', async () => {
				store.house = { id: 1, stateVersion: 7 }
				api.post.mockRejectedValueOnce(new Error('Network error'))
				api.post.mockResolvedValueOnce({ id: 1, stateVersion: 9 })

				await expect(store.resetHouse()).rejects.toThrow('Network error')
				const firstPayload = api.post.mock.calls[0][1]
				store.house.stateVersion = 8
				await store.resetHouse()

				expect(api.post.mock.calls[1][1]).toEqual(firstPayload)
				expect(store.pendingResetCommand).toBeNull()
			})

			it('uses a new UUID after a definitive conflict and drops pending on store reset', async () => {
				store.house = { id: 1, stateVersion: 7 }
				api.post.mockRejectedValueOnce(Object.assign(new Error('Conflict'), { status: 409 }))
				api.post.mockResolvedValueOnce({ id: 1, stateVersion: 9 })

				await expect(store.resetHouse()).rejects.toThrow('Conflict')
				const firstPayload = api.post.mock.calls[0][1]
				expect(store.pendingResetCommand).toBeNull()
				store.house.stateVersion = 8
				await store.resetHouse()

				expect(api.post.mock.calls[1][1].commandId).not.toBe(firstPayload.commandId)
				expect(api.post.mock.calls[1][1].expectedVersion).toBe(8)
				api.post.mockRejectedValueOnce(new Error('Timeout'))
				await expect(store.resetHouse()).rejects.toThrow('Timeout')
				store.reset()
				expect(store.pendingResetCommand).toBeNull()
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
