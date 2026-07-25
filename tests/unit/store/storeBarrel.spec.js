/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'

jest.mock('../../../src/services/api.js', () => ({
	api: {
		get: jest.fn(),
		post: jest.fn(),
		put: jest.fn(),
		delete: jest.fn(),
		patch: jest.fn(),
	},
}))

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, message) => message),
}))

jest.mock('../../../src/utils/logger.js', () => ({
	logger: {
		error: jest.fn(),
		info: jest.fn(),
		warn: jest.fn(),
		debug: jest.fn(),
	},
}))

import {
	useHouseStore,
	useRoomsStore,
	useTasksStore,
	useRoomTasksStore,
	useGameStore,
	useUserStore,
	useChecklistStore,
	useSchedulingStore,
	useMinigameStore,
	useTitlesStore,
	resetAllStores,
	clearAllStoreErrors,
	getAnyStoreLoading,
	getAnyStoreError,
} from '../../../src/store/index.js'

const ALL_STORE_HOOKS = [
	useHouseStore,
	useRoomsStore,
	useTasksStore,
	useRoomTasksStore,
	useGameStore,
	useUserStore,
	useChecklistStore,
	useSchedulingStore,
	useMinigameStore,
	useTitlesStore,
]

describe('Store Barrel', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
	})

	describe('Named exports', () => {
		it('exports all 10 store hooks', () => {
			expect(useHouseStore).toBeDefined()
			expect(useRoomsStore).toBeDefined()
			expect(useTasksStore).toBeDefined()
			expect(useRoomTasksStore).toBeDefined()
			expect(useGameStore).toBeDefined()
			expect(useUserStore).toBeDefined()
			expect(useChecklistStore).toBeDefined()
			expect(useSchedulingStore).toBeDefined()
			expect(useMinigameStore).toBeDefined()
			expect(useTitlesStore).toBeDefined()
		})
	})

	describe('resetAllStores', () => {
		it('calls reset() on all 10 stores', () => {
			const spies = ALL_STORE_HOOKS.map((hook) => {
				const store = hook()
				return jest.spyOn(store, 'reset')
			})

			resetAllStores()

			for (const spy of spies) {
				expect(spy).toHaveBeenCalledTimes(1)
			}
		})
	})

	describe('clearAllStoreErrors', () => {
		it('calls clearError() on all 10 stores', () => {
			const spies = ALL_STORE_HOOKS.map((hook) => {
				const store = hook()
				return jest.spyOn(store, 'clearError')
			})

			clearAllStoreErrors()

			for (const spy of spies) {
				expect(spy).toHaveBeenCalledTimes(1)
			}
		})
	})

	describe('getAnyStoreLoading', () => {
		it('returns false when no store is loading', () => {
			expect(getAnyStoreLoading()).toBe(false)
		})

		it('returns true when any store is loading', () => {
			const store = useSchedulingStore()
			store.loading = true
			expect(getAnyStoreLoading()).toBe(true)
		})
	})

	describe('getAnyStoreError', () => {
		it('returns falsy when no store has errors', () => {
			expect(getAnyStoreError()).toBeFalsy()
		})

		it('returns error when any store has an error', () => {
			const store = useTitlesStore()
			store.error = 'something failed'
			expect(getAnyStoreError()).toBe('something failed')
		})
	})
})
