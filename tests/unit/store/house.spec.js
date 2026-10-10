import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useHouseStore } from '../../../src/store/house.js'

jest.mock('../../../src/services/api.js', () => ({
	api: {
		get: jest.fn(),
		put: jest.fn(),
		post: jest.fn(),
	},
	setCatalogEnabled: jest.fn(),
}))

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

import { api, setCatalogEnabled } from '../../../src/services/api.js'

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

		it('rankingEnabled defaults to false and mirrors the loaded house flag', () => {
			expect(store.rankingEnabled).toBe(false)
			store.house = { id: 1, rankingEnabled: true }
			expect(store.rankingEnabled).toBe(true)
			store.house = { id: 1 }
			expect(store.rankingEnabled).toBe(false)
		})

		// CS-PLAN-070 WP8: the household catalog gate is fail-closed —
		// false until the loaded house explicitly says otherwise.
		it('catalogEnabled defaults to false and mirrors the loaded house flag', () => {
			expect(store.catalogEnabled).toBe(false)
			store.house = { id: 1, catalogEnabled: true }
			expect(store.catalogEnabled).toBe(true)
			store.house = { id: 1 }
			expect(store.catalogEnabled).toBe(false)
		})
	})

	describe('Actions', () => {
		describe('fetchHouse', () => {
			it('keeps a superseded caller pending until the newest house read settles', async () => {
				let resolveFirst, resolveLatest
				api.get.mockImplementationOnce(() => new Promise(resolve => { resolveFirst = resolve }))
				api.get.mockImplementationOnce(() => new Promise(resolve => { resolveLatest = resolve }))
				let settled = false
				const first = store.fetchHouse().then(house => { settled = true; return house })
				const latest = store.fetchHouse()
				resolveFirst({ id: 1, name: 'Before setup' })
				await new Promise(resolve => { setTimeout(resolve, 0) })
				expect(settled).toBe(false)
				expect(store.loading).toBe(true)
				const saved = { id: 1, name: 'Saved Home', timezone: 'Europe/Berlin' }
				resolveLatest(saved)
				expect(await first).toEqual(saved)
				expect(await latest).toEqual(saved)
				expect(store.house).toEqual(saved)
				expect(store.loading).toBe(false)
			})

			it('fetches house successfully', async () => {
				const mockHouse = { id: 1, name: 'Test House', houseHealth: 90 }
				api.get.mockResolvedValue(mockHouse)

				await store.fetchHouse()

				expect(store.house).toEqual(mockHouse)
				expect(store.loading).toBe(false)
				expect(store.error).toBeNull()
			})

			it('propagates the winning read failure to a caller waiting on a superseded read', async () => {
				let resolveFirst, rejectLatest
				api.get.mockImplementationOnce(() => new Promise(resolve => { resolveFirst = resolve }))
				api.get.mockImplementationOnce(() => new Promise((resolve, reject) => { rejectLatest = reject }))
				const first = store.fetchHouse()
				const latest = store.fetchHouse()
				resolveFirst({ id: 1, name: 'Stale' })
				await new Promise(resolve => { setTimeout(resolve, 0) })
				const failures = Promise.allSettled([first, latest])
				rejectLatest(new Error('Network error'))
				expect((await failures).map(result => result.status)).toEqual(['rejected', 'rejected'])
				expect(store.house).toBeNull()
				expect(store.error).toBe('Network error')
				expect(store.loading).toBe(false)
			})

			it('does not clear a new identity read or its loading state when an old read resolves', async () => {
				let resolveOld, resolveNew
				api.get.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve }))
				api.get.mockImplementationOnce(() => new Promise(resolve => { resolveNew = resolve }))
				const old = store.fetchHouse()
				store.reset()
				const current = store.fetchHouse()
				const request = store.houseRequest
				resolveOld({ id: 1, name: 'Old identity' })
				await new Promise(resolve => { setTimeout(resolve, 0) })
				expect(store.house).toBeNull()
				expect(store.houseRequest).toBe(request)
				expect(store.loading).toBe(true)
				resolveNew({ id: 2, name: 'New identity' })
				await Promise.all([old, current])
				expect(store.house.name).toBe('New identity')
				expect(store.houseRequest).toBeNull()
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

		// CS-PLAN-070 WP4: the owner ranking flag rides the shared endpoint.
		describe('updateRankingEnabled', () => {
			it('never submits the display fallback as a persisted house name', async () => {
				await expect(store.updateRankingEnabled(true)).rejects.toThrow('House name is required')
				expect(api.put).not.toHaveBeenCalled()
			})

			it('sends the current name plus the flag and stores the response', async () => {
				store.house = { id: 1, name: 'Test House', rankingEnabled: false }
				const updated = { id: 1, name: 'Test House', rankingEnabled: true }
				api.put.mockResolvedValue(updated)

				await store.updateRankingEnabled(true)

				expect(api.put).toHaveBeenCalledWith('/api/v1/house', { name: 'Test House', rankingEnabled: true })
				expect(store.house).toEqual(updated)
				expect(store.rankingEnabled).toBe(true)
			})

			it('stores the error and rethrows on failure', async () => {
				store.house = { id: 1, name: 'Test House' }
				api.put.mockRejectedValue(new Error('Forbidden'))

				await expect(store.updateRankingEnabled(true)).rejects.toThrow('Forbidden')
				expect(store.error).toBe('Forbidden')
				expect(store.loading).toBe(false)
			})
		})

		// CS-PLAN-070 WP8: the reward-catalog gate uses ONLY the narrow
		// versioned writer — `PUT /house` never touches the flag.
		describe('updateCatalogEnabled', () => {
			it('sends the flag with the house stateVersion and a command UUID', async () => {
				store.house = { id: 1, name: 'Test House', stateVersion: 4, catalogEnabled: false }
				const updated = { id: 1, name: 'Test House', stateVersion: 5, catalogEnabled: true }
				setCatalogEnabled.mockResolvedValue(updated)

				await store.updateCatalogEnabled(true)

				expect(setCatalogEnabled).toHaveBeenCalledWith(expect.objectContaining({
					catalogEnabled: true,
					expectedVersion: 4,
				}))
				expect(setCatalogEnabled.mock.calls[0][0].commandId).toMatch(/^[0-9a-f-]{36}$/)
				expect(store.house).toEqual(updated)
				expect(store.catalogEnabled).toBe(true)
			})

			it('refuses to send without a loaded house stateVersion', async () => {
				await expect(store.updateCatalogEnabled(true)).rejects.toThrow('House state version is unavailable')
				expect(setCatalogEnabled).not.toHaveBeenCalled()
			})

			it('stores the error and rethrows on failure', async () => {
				store.house = { id: 1, stateVersion: 4 }
				setCatalogEnabled.mockRejectedValue(new Error('Conflict'))

				await expect(store.updateCatalogEnabled(true)).rejects.toThrow('Conflict')
				expect(store.error).toBe('Conflict')
				expect(store.loading).toBe(false)
			})

			it('re-reads the house after a 409 so the next attempt carries a fresh stateVersion', async () => {
				store.house = { id: 1, stateVersion: 4, catalogEnabled: false }
				setCatalogEnabled.mockRejectedValue(Object.assign(new Error('Conflict'), { status: 409 }))
				api.get.mockResolvedValue({ id: 1, name: 'Test House', stateVersion: 9, catalogEnabled: true })

				await expect(store.updateCatalogEnabled(true)).rejects.toThrow('Conflict')
				await new Promise(resolve => { setTimeout(resolve, 0) })

				expect(api.get).toHaveBeenCalledWith('/api/v1/house')
				expect(store.house.stateVersion).toBe(9)
				expect(store.house.catalogEnabled).toBe(true)
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

			it('waits for the full house read before refreshing only its health', async () => {
				let resolveFetch
				api.get.mockImplementationOnce(() => new Promise((resolve) => { resolveFetch = resolve }))
				api.get.mockResolvedValueOnce({ houseHealth: 88 })

				const houseFetch = store.fetchHouse()
				const healthRefresh = store.calculateHouseHealth()
				expect(api.get).toHaveBeenCalledTimes(1)
				resolveFetch({ id: 1, name: 'Fresh', houseHealth: 10 })
				await houseFetch
				await healthRefresh

				// Partial health reads cannot invalidate required name/timezone data.
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

			it('does not start a queued health read after the identity is reset', async () => {
				let resolveHouse
				api.get.mockImplementationOnce(() => new Promise(resolve => { resolveHouse = resolve }))
				const house = store.fetchHouse()
				const health = store.calculateHouseHealth()
				store.reset()
				resolveHouse({ id: 1, name: 'Old identity' })
				await Promise.all([house, health])
				expect(api.get).toHaveBeenCalledTimes(1)
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
