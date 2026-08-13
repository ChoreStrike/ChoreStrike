import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useSetupStore } from '../../../src/store/setup.js'

jest.mock('../../../src/services/api.js', () => ({
	api: {
		get: jest.fn(),
		post: jest.fn(),
	},
}))

import { api } from '../../../src/services/api.js'

describe('Setup Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSetupStore()
		jest.clearAllMocks()
	})

	const catalog = {
		levels: [{ id: 'putzteufel', label: 'Putzteufel' }, { id: 'putzmuffel', label: 'Putzmuffel' }],
		roomTypes: [{ id: 'kitchen', defaultName: 'Kitchen', icon: 'Utensils', templateIds: [] }],
		templates: [{ id: 'kitchen_counters', name: 'Wipe counters', roomTypes: ['kitchen'], levels: {} }],
	}

	it('fetchCatalog populates catalog', async () => {
		api.get.mockResolvedValue(catalog)

		const data = await store.fetchCatalog()

		expect(api.get).toHaveBeenCalledWith('/api/v1/setup/catalog')
		expect(data).toEqual(catalog)
		expect(store.hasCatalog).toBe(true)
		expect(store.levels).toHaveLength(2)
		expect(store.roomTypes[0].id).toBe('kitchen')
	})

	it('fetchCatalog sets error and rethrows on failure', async () => {
		api.get.mockRejectedValue(new Error('boom'))

		await expect(store.fetchCatalog()).rejects.toThrow('boom')
		expect(store.error).toBe('boom')
	})

	it('applySetup posts payload and returns result', async () => {
		const payload = { intensityLevel: 'putzteufel', floors: [] }
		const result = { floors: 1, rooms: 2, tasks: 3, roomTasks: 3, skipped: [] }
		api.post.mockResolvedValue(result)

		const data = await store.applySetup(payload)

		expect(api.post).toHaveBeenCalledWith('/api/v1/setup/apply', payload)
		expect(data).toEqual(result)
	})

	it('applySetup sets applying flag during request', async () => {
		let resolve
		api.post.mockReturnValue(new Promise(r => { resolve = r }))
		const promise = store.applySetup({ intensityLevel: 'putzteufel', floors: [] })
		expect(store.applying).toBe(true)
		resolve({ floors: 0 })
		await promise
		expect(store.applying).toBe(false)
	})
})
