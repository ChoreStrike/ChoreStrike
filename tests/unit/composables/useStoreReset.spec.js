/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { createPinia, setActivePinia } from 'pinia'

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

const {
	useUserStore,
	usePreferencesStore,
	useRankingStore,
	useGameStore,
	useMinigameStore,
	useHouseStore,
	useRoomsStore,
	useRoomTasksStore,
	useTasksStore,
	useChecklistStore,
	useSchedulingStore,
	useSetupStore,
	useTitlesStore,
	usePausesStore,
	useRewardsStore,
} = {
	useUserStore: require('../../../src/store/user.js').useUserStore,
	usePreferencesStore: require('../../../src/store/preferences.js').usePreferencesStore,
	useRankingStore: require('../../../src/store/ranking.js').useRankingStore,
	useGameStore: require('../../../src/store/game.js').useGameStore,
	useMinigameStore: require('../../../src/store/minigame.js').useMinigameStore,
	useHouseStore: require('../../../src/store/house.js').useHouseStore,
	useRoomsStore: require('../../../src/store/rooms.js').useRoomsStore,
	useRoomTasksStore: require('../../../src/store/roomTasks.js').useRoomTasksStore,
	useTasksStore: require('../../../src/store/tasks.js').useTasksStore,
	useChecklistStore: require('../../../src/store/checklist.js').useChecklistStore,
	useSchedulingStore: require('../../../src/store/scheduling.js').useSchedulingStore,
	useSetupStore: require('../../../src/store/setup.js').useSetupStore,
	useTitlesStore: require('../../../src/store/titles.js').useTitlesStore,
	usePausesStore: require('../../../src/store/pauses.js').usePausesStore,
	useRewardsStore: require('../../../src/store/rewards.js').useRewardsStore,
}

/**
 * Shared-device reset contract (CS-PLAN-070 WP6 §3.8, C5h): ONE identity
 * switch resets EVERY house-scoped store — the explicit enumeration is
 * the acceptance evidence, so an added house-scoped store without a
 * reset can never silently keep another login's data.
 */
describe('useStoreReset composable', () => {
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
	})

	const seedEachStore = () => {
		useUserStore().currentUser = { nextcloudUserId: 'mom', role: 'owner' }
		useUserStore().membership = 'member'
		usePreferencesStore().taskDigestEnabled = true
		useRankingStore().entries = [{ houseUserId: 1 }]
		useGameStore().gameState = { wallet: {} }
		useMinigameStore().personalBest = { score: 42 }
		useHouseStore().house = { id: 1, name: 'Old House' }
		useRoomsStore().floors = [{ id: 1 }]
		useRoomTasksStore().roomTasks = [{ id: 1 }]
		useTasksStore().tasks = [{ id: 1 }]
		useChecklistStore().items = { 1: [{ id: 1 }] }
		useSchedulingStore().memberAvailability = [{ userId: 'x' }]
		useSetupStore().catalog = { levels: [] }
		useTitlesStore().currentTitle = { labelKey: 'x' }
		usePausesStore().pauses = [{ pauseGroupId: 1 }]
		useRewardsStore().requests.mine.items = [{ id: 1 }]
		useRewardsStore().unresolvedCommand = { commandId: 'x', label: 'y' }
	}

	it('resets every house-scoped store in one call', () => {
		seedEachStore()

		const { resetIdentityStores } = require('../../../src/composables/useStoreReset.js')
		resetIdentityStores()

		expect(useUserStore().currentUser).toBeNull()
		expect(useUserStore().membership).toBe('unknown')
		expect(usePreferencesStore().taskDigestEnabled).toBe(false)
		expect(useRankingStore().entries).toEqual([])
		expect(useGameStore().gameState).toBeNull()
		expect(useMinigameStore().personalBest).toBeNull()
		expect(useHouseStore().house).toBeNull()
		expect(useRoomsStore().floors).toEqual([])
		expect(useRoomTasksStore().roomTasks).toEqual([])
		expect(useTasksStore().tasks).toEqual([])
		expect(useChecklistStore().items).toEqual({})
		expect(useSchedulingStore().memberAvailability).toEqual([])
		expect(useSetupStore().catalog).toBeNull()
		expect(useTitlesStore().currentTitle).toBeNull()
		expect(usePausesStore().pauses).toEqual([])
		expect(useRewardsStore().requests.mine.items).toEqual([])
		expect(useRewardsStore().unresolvedCommand).toBeNull()
	})

	it('keeps every store importable without a cycle (DAG pin guard, CS-PLAN-067 §3.1 D3)', () => {
		// The composable is the ONLY cross-cutting reset caller; no store
		// module may import it back. The static dependency-graph pin in
		// store/dependencies.spec.js keeps the store DAG acyclic; this
		// assertion pins the composable's own import direction.
		const { readFileSync } = require('fs')
		const { join } = require('path')
		const source = readFileSync(
			join(__dirname, '../../../src/composables/useStoreReset.js'),
			'utf8',
		)
		expect(source).toMatch(/from '\.\.\/store\//)
		// No store module may import the composable.
		const storeDir = join(__dirname, '../../../src/store')
		const { readdirSync } = require('fs')
		for (const file of readdirSync(storeDir).filter(f => f.endsWith('.js'))) {
			const content = readFileSync(join(storeDir, file), 'utf8')
			expect(content).not.toContain('useStoreReset')
		}
	})
})
