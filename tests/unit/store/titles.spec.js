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
		it('starts empty', () => {
			expect(store.currentTitle).toBeNull()
			expect(store.unlockedTitles).toEqual([])
			expect(store.selectedTitlePack).toBeNull()
			expect(store.catalog).toEqual({})
			expect(store.loading).toBe(false)
			expect(store.error).toBeNull()
		})
	})

	describe('fetchTitles', () => {
		it('loads current title, unlocked set, selected pack and catalog', async () => {
			const payload = {
				currentTitle: { labelKey: 'titles.team_energy.flow_keeper', tierIndex: 1 },
				unlockedTitles: [{ pack: 'team_energy', tierIndex: 0 }],
				selectedTitlePack: 'team_energy',
				catalog: { team_energy: { tiers: [] } },
			}
			api.get.mockResolvedValue(payload)

			await store.fetchTitles(7)

			expect(api.get).toHaveBeenCalledWith('/api/v1/users/7/titles')
			expect(store.currentTitle).toEqual(payload.currentTitle)
			expect(store.selectedTitlePack).toBe('team_energy')
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

	describe('selectPack', () => {
		it('updates the selected pack and current title from the response', async () => {
			api.put.mockResolvedValue({
				selectedTitlePack: 'cozy_home',
				currentTitle: { labelKey: 'titles.cozy_home.cozy_buddy', tierIndex: 0 },
			})

			await store.selectPack(7, 'cozy_home')

			expect(api.put).toHaveBeenCalledWith('/api/v1/users/7/titles/select', { pack: 'cozy_home' })
			expect(store.selectedTitlePack).toBe('cozy_home')
			expect(store.currentTitle.tierIndex).toBe(0)
		})

		it('rethrows on failure', async () => {
			api.put.mockRejectedValue(new Error('nope'))
			await expect(store.selectPack(7, 'cozy_home')).rejects.toThrow('nope')
			expect(store.error).toBe('nope')
		})
	})

	describe('reset', () => {
		it('clears all state', () => {
			store.currentTitle = { labelKey: 'x' }
			store.selectedTitlePack = 'home_helpers'
			store.catalog = { a: 1 }
			store.error = 'err'

			store.reset()

			expect(store.currentTitle).toBeNull()
			expect(store.selectedTitlePack).toBeNull()
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
