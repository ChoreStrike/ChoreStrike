/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, beforeEach, jest } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'

jest.mock('../../../src/services/api.js', () => ({
	api: {
		get: jest.fn(),
		put: jest.fn(),
	},
}))

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, message) => message),
}))

import { api } from '../../../src/services/api.js'
import { useTitlesStore } from '../../../src/store/titles.js'
import { useUserStore } from '../../../src/store/user.js'
import TitlePicker from '../../../src/components/Profile/TitlePicker.vue'

const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0))

function catalog() {
	return {
		home_helpers: {
			labelKey: 'titles.pack.home_helpers',
			signal: 'contributionScore',
			tiers: [
				{ key: 'helper', labelKey: 'titles.home_helpers.helper', threshold: 50 },
				{ key: 'organizer', labelKey: 'titles.home_helpers.organizer', threshold: 250 },
				{ key: 'care_captain', labelKey: 'titles.home_helpers.care_captain', threshold: 1000 },
			],
		},
		team_energy: {
			labelKey: 'titles.pack.team_energy',
			signal: 'longestStreak',
			tiers: [
				{ key: 'spark_starter', labelKey: 'titles.team_energy.spark_starter', threshold: 7 },
				{ key: 'flow_keeper', labelKey: 'titles.team_energy.flow_keeper', threshold: 30 },
				{ key: 'team_anchor', labelKey: 'titles.team_energy.team_anchor', threshold: 90 },
			],
		},
		cozy_home: {
			labelKey: 'titles.pack.cozy_home',
			signal: 'totalSparksEarned',
			tiers: [
				{ key: 'cozy_buddy', labelKey: 'titles.cozy_home.cozy_buddy', threshold: 500 },
				{ key: 'room_ranger', labelKey: 'titles.cozy_home.room_ranger', threshold: 2500 },
				{ key: 'home_guardian', labelKey: 'titles.cozy_home.home_guardian', threshold: 10000 },
			],
		},
	}
}

describe('TitlePicker', () => {
	let titlesStore
	let userStore

	beforeEach(() => {
		setActivePinia(createPinia())
		titlesStore = useTitlesStore()
		userStore = useUserStore()
		titlesStore.catalog = catalog()
		userStore.currentUser = {
			contributionScore: 60,
			longestStreak: 8,
			totalSparksEarned: 0,
		}
		jest.clearAllMocks()
	})

	it('renders a card per pack', () => {
		const wrapper = mount(TitlePicker, {
			props: { show: true, houseUserId: 7 },
		})
		expect(wrapper.find('[data-testid="title-pack-home_helpers"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="title-pack-team_energy"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="title-pack-cozy_home"]').exists()).toBe(true)
	})

	it('marks tiers as unlocked when the server lists them', () => {
		titlesStore.unlockedTitles = [
			{ pack: 'home_helpers', tierKey: 'helper', tierIndex: 0 },
		]
		const wrapper = mount(TitlePicker, {
			props: { show: true, houseUserId: 7 },
		})
		// home_helpers helper (tier 0) is in the server-derived unlocked set.
		const tier = wrapper.find('[data-testid="tier-home_helpers-0"]')
		expect(tier.text()).toContain('✓')
	})

	it('selects a pack via the select button', async () => {
		api.put.mockResolvedValue({
			selectedTitlePack: 'team_energy',
			currentTitle: { labelKey: 'titles.team_energy.spark_starter', tierIndex: 0 },
		})

		const wrapper = mount(TitlePicker, {
			props: { show: true, houseUserId: 7 },
		})

		// team_energy card is not selected initially → has a select button.
		const teamCard = wrapper.find('[data-testid="title-pack-team_energy"]')
		const button = teamCard.find('[data-testid="select-pack-button"]')
		expect(button.exists()).toBe(true)

		await button.trigger('click')
		await flushPromises()

		expect(api.put).toHaveBeenCalledWith('/api/v1/users/7/titles/select', { pack: 'team_energy' })
		expect(wrapper.emitted('selected')).toBeTruthy()
		expect(titlesStore.selectedTitlePack).toBe('team_energy')
	})

	it('shows the active tag for the selected pack and hides its select button', async () => {
		titlesStore.selectedTitlePack = 'home_helpers'
		const wrapper = mount(TitlePicker, {
			props: { show: true, houseUserId: 7 },
		})
		const homeCard = wrapper.find('[data-testid="title-pack-home_helpers"]')
		expect(homeCard.find('[data-testid="select-pack-button"]').exists()).toBe(false)
		expect(homeCard.find('[data-testid="active-pack-tag"]').exists()).toBe(true)
	})

	it('emits close when the close button is clicked', async () => {
		const wrapper = mount(TitlePicker, {
			props: { show: true, houseUserId: 7 },
		})
		await wrapper.find('.secondary-button').trigger('click')
		expect(wrapper.emitted('close')).toBeTruthy()
	})
})
