/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import ArcadeView from '@/views/ArcadeView.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

const mockPreferencesState = {
	arcadeVisible: true,
}

jest.mock('@/store/preferences.js', () => ({
	usePreferencesStore: () => require('vue').reactive(mockPreferencesState),
}))

jest.mock('@/store/game.js', () => ({
	useGameStore: () => ({
		weeklyLeaderboard: [],
		loading: false,
		error: null,
		fetchWeeklyLeaderboard: jest.fn().mockResolvedValue([]),
		fetchLeaderboard: jest.fn().mockResolvedValue([]),
	}),
}))

jest.mock('@/store/user.js', () => ({
	useUserStore: () => ({
		currentUser: { nextcloudUserId: 'mom', role: 'owner' },
		houseUsers: [],
	}),
}))

jest.mock('@/components/Minigames/ArcadeCatalog.vue', () => ({
	name: 'ArcadeCatalog',
	template: '<div data-testid="arcade-surface-stub" />',
}))

/**
 * Optional-area surface (CS-PLAN-070 WP6 §3.3, Q-077-05 Option A): the
 * arcade lives OUTSIDE the daily surfaces and hides entirely while the
 * personal arcadeVisible preference is off — a direct link shows the
 * neutral placeholder, never the arcade.
 */
describe('ArcadeView', () => {
	beforeEach(() => {
		mockPreferencesState.arcadeVisible = true
	})

	const mountView = async () => {
		const wrapper = mount(ArcadeView)
		await flushPromises()
		return wrapper
	}

	it('renders the arcade surface by default (opt-in default on)', async () => {
		const wrapper = await mountView()

		expect(wrapper.find('[data-testid="arcade-surface"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="arcade-hidden"]').exists()).toBe(false)
	})

	it('hides the arcade and shows the neutral placeholder when arcadeVisible is false', async () => {
		mockPreferencesState.arcadeVisible = false
		const wrapper = await mountView()

		expect(wrapper.find('[data-testid="arcade-surface"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="arcade-hidden"]').exists()).toBe(true)
	})
})
