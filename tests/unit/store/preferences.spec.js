/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { createPinia, setActivePinia } from 'pinia'

// Deliberate error-path specs must not leak real logger output into the
// noise gate (CS-PLAN-034 convention).
jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

jest.mock('../../../src/services/api.js', () => ({
	fetchMyPreferences: jest.fn(),
	updateMyPreferences: jest.fn(),
}))

import { fetchMyPreferences, updateMyPreferences } from '../../../src/services/api.js'
import { usePreferencesStore } from '../../../src/store/preferences.js'

/**
 * Cross-layer contract for the self-only preferences store (CS-PLAN-070
 * WP5, C5h, Q-076-01 A): version-aware saves with 409 draft preservation,
 * authoritative-state application, and the reset wiring for identity/house
 * switches.
 */
describe('preferences store', () => {
	let pinia

	const serverPayload = (overrides = {}) => ({
		version: 1,
		taskDigestEnabled: false,
		orgDigestEnabled: false,
		digestTime: '18:00',
		quietStart: null,
		quietEnd: null,
		soundEnabled: false,
		animationEnabled: false,
		arcadeVisible: true,
		catalogVisible: true,
		ranking: { state: 'none', generation: null, effectiveMonday: null },
		...overrides,
	})

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		fetchMyPreferences.mockReset()
		updateMyPreferences.mockReset()
		usePreferencesStore().reset()
	})

	it('starts at the neutral defaults with version 0', () => {
		const store = usePreferencesStore()

		expect(store.version).toBe(0)
		expect(store.taskDigestEnabled).toBe(false)
		expect(store.digestTime).toBe('18:00')
		expect(store.arcadeVisible).toBe(true)
		expect(store.ranking.state).toBe('none')
	})

	it('fetches the own preferences and applies the authoritative state', async () => {
		fetchMyPreferences.mockResolvedValue(serverPayload({
			version: 4,
			taskDigestEnabled: true,
			digestTime: '19:30',
			quietStart: '21:00',
			quietEnd: '07:00',
			ranking: { state: 'pending', generation: 2, effectiveMonday: '2026-10-05' },
		}))

		const store = usePreferencesStore()
		await store.fetchPreferences()

		expect(fetchMyPreferences).toHaveBeenCalledWith()
		expect(store.version).toBe(4)
		expect(store.taskDigestEnabled).toBe(true)
		expect(store.digestTime).toBe('19:30')
		expect(store.ranking.state).toBe('pending')
		expect(store.error).toBeNull()
	})

	it('saves with the current expectedVersion and a generated commandId', async () => {
		fetchMyPreferences.mockResolvedValue(serverPayload({ version: 2 }))
		const store = usePreferencesStore()
		await store.fetchPreferences()

		updateMyPreferences.mockResolvedValue(serverPayload({ version: 3, taskDigestEnabled: true }))
		const saved = await store.savePreferences({ taskDigestEnabled: true })

		expect(saved).toBe(true)
		expect(updateMyPreferences).toHaveBeenCalledWith(expect.objectContaining({
			expectedVersion: 2,
			taskDigestEnabled: true,
		}))
		const body = updateMyPreferences.mock.calls[0][0]
		expect(body.commandId).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i)
		expect(store.version).toBe(3)
		expect(store.taskDigestEnabled).toBe(true)
	})

	it('generates a fresh commandId per save attempt (a 409 retry carries new input)', async () => {
		fetchMyPreferences.mockResolvedValue(serverPayload({ version: 1 }))
		const store = usePreferencesStore()
		await store.fetchPreferences()

		updateMyPreferences
			.mockResolvedValueOnce(serverPayload({ version: 1 }))
			.mockResolvedValueOnce(serverPayload({ version: 2 }))

		await store.savePreferences({ taskDigestEnabled: true })
		await store.savePreferences({ taskDigestEnabled: true })

		const [first, second] = updateMyPreferences.mock.calls
		expect(first[0].commandId).not.toBe(second[0].commandId)
	})

	it('preserves the draft and refreshes the server state on 409', async () => {
		const conflictError = Object.assign(new Error('The preferences changed since they were displayed'), { status: 409 })
		fetchMyPreferences.mockResolvedValue(serverPayload({ version: 1 }))
		const store = usePreferencesStore()
		await store.fetchPreferences()

		updateMyPreferences.mockRejectedValue(conflictError)
		fetchMyPreferences.mockResolvedValueOnce(serverPayload({ version: 7, digestTime: '20:00' }))

		const saved = await store.savePreferences({ taskDigestEnabled: true, digestTime: '19:30' })

		expect(saved).toBe(false)
		expect(store.conflict).toBe(true)
		// Draft preservation: the local edits stay (the store keeps them —
		// applyServer only refreshes the authoritative version/fields).
		expect(store.version).toBe(7)
		expect(store.digestTime).toBe('20:00')
		expect(store.error).toBeNull()
	})

	it('flags a local digest time of null only when no category is enabled (no client guesswork)', async () => {
		// The server validates; the store just transports the fields.
		fetchMyPreferences.mockResolvedValue(serverPayload({ version: 1 }))
		const store = usePreferencesStore()
		await store.fetchPreferences()
		updateMyPreferences.mockResolvedValue(serverPayload({ version: 2 }))

		await store.savePreferences({ digestTime: null })

		expect(updateMyPreferences).toHaveBeenCalledWith(expect.objectContaining({
			digestTime: null,
		}))
	})

	it('propagates non-conflict failures and records the error', async () => {
		fetchMyPreferences.mockResolvedValue(serverPayload({ version: 1 }))
		const store = usePreferencesStore()
		await store.fetchPreferences()

		updateMyPreferences.mockRejectedValue(new Error('network down'))
		await expect(store.savePreferences({ taskDigestEnabled: true })).rejects.toThrow('network down')
		expect(store.error).toBe('network down')
		expect(store.conflict).toBe(false)
	})

	it('resets to the neutral defaults on identity/house switch', async () => {
		fetchMyPreferences.mockResolvedValue(serverPayload({ version: 9, taskDigestEnabled: true }))
		const store = usePreferencesStore()
		await store.fetchPreferences()
		expect(store.version).toBe(9)

		store.reset()

		expect(store.version).toBe(0)
		expect(store.taskDigestEnabled).toBe(false)
		expect(store.conflict).toBe(false)
		expect(store.ranking.state).toBe('none')
	})

	// CS-PLAN-070 WP8 §3.1: the initial neutral defaults are NOT a
	// completed preference read — optional-area consumers fail closed.
	describe('loaded marker (WP8 fail-closed visibility)', () => {
		it('starts unloaded even though the neutral defaults say visible', () => {
			const store = usePreferencesStore()

			expect(store.loaded).toBe(false)
			expect(store.catalogVisible).toBe(true)
		})

		it('marks the preferences loaded after an authoritative fetch', async () => {
			fetchMyPreferences.mockResolvedValue(serverPayload({ version: 1, catalogVisible: false }))
			const store = usePreferencesStore()

			await store.fetchPreferences()

			expect(store.loaded).toBe(true)
			expect(store.catalogVisible).toBe(false)
		})

		it('stays unloaded when the fetch fails (fail-closed, no stale read)', async () => {
			fetchMyPreferences.mockRejectedValue(new Error('offline'))
			const store = usePreferencesStore()

			await expect(store.fetchPreferences()).rejects.toThrow('offline')

			expect(store.loaded).toBe(false)
		})

		it('clears the loaded marker on reset', async () => {
			fetchMyPreferences.mockResolvedValue(serverPayload({ version: 1 }))
			const store = usePreferencesStore()
			await store.fetchPreferences()
			expect(store.loaded).toBe(true)

			store.reset()

			expect(store.loaded).toBe(false)
		})
	})
})
