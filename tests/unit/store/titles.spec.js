/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, beforeEach, jest } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'

jest.mock('../../../src/services/api.js', () => ({
	api: {
		get: jest.fn(),
		put: jest.fn(),
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

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, message) => message),
}))

import { api } from '../../../src/services/api.js'
import { useTitlesStore } from '../../../src/store/titles.js'

describe('Titles Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useTitlesStore()
		jest.clearAllMocks()
	})

	describe('Initial State', () => {
		it('starts empty without a pack selection surface (WP4, Q-075-02 A)', () => {
			expect(store.currentTitle).toBeNull()
			expect(store.unlockedTitles).toEqual([])
			expect(store.selectedTitlePack).toBeUndefined()
			expect(store.catalog).toEqual({})
			expect(store.loading).toBe(false)
			expect(store.error).toBeNull()
			expect(store.selectPack).toBeUndefined()
		})
	})

	describe('fetchTitles', () => {
		it('loads current title, unlocked set and the fixed catalog', async () => {
			const payload = {
				currentTitle: { labelKey: 'titles.family_rhythm.rhythm_6', tierIndex: 1 },
				unlockedTitles: [{ pack: 'family_rhythm', tierIndex: 0 }],
				catalog: { family_rhythm: { tiers: [] } },
			}
			api.get.mockResolvedValue(payload)

			await store.fetchTitles(7)

			expect(api.get).toHaveBeenCalledWith('/api/v1/users/7/titles')
			expect(store.currentTitle).toEqual(payload.currentTitle)
			expect(store.catalog).toEqual(payload.catalog)
			expect(store.loading).toBe(false)
		})

		it('requires a house user id', async () => {
			await expect(store.fetchTitles()).rejects.toThrow()
		})

		it('sets error and rethrows on failure', async () => {
			api.get.mockRejectedValue(new Error('boom'))
			await expect(store.fetchTitles(7)).rejects.toThrow('boom')
			expect(store.error).toBe('boom')
			expect(store.loading).toBe(false)
		})
	})

	describe('reset', () => {
		it('clears all state', () => {
			store.currentTitle = { labelKey: 'x' }
			store.unlockedTitles = [{ pack: 'family_rhythm' }]
			store.catalog = { a: 1 }
			store.error = 'err'

			store.reset()

			expect(store.currentTitle).toBeNull()
			expect(store.unlockedTitles).toEqual([])
			expect(store.catalog).toEqual({})
			expect(store.error).toBeNull()
		})
	})

	describe('clearError', () => {
		it('clears the error state', () => {
			store.error = 'some error'
			store.clearError()
			expect(store.error).toBeNull()
		})
	})
})
