/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { beforeEach, describe, expect, it, jest } from '@jest/globals'
import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
import BonusDaysBanner from '../../../src/components/Today/BonusDaysBanner.vue'
import BonusDaysSettings from '../../../src/components/Settings/BonusDaysSettings.vue'
import CoinFactorSettings from '../../../src/components/Settings/CoinFactorSettings.vue'
import RewardSettings from '../../../src/components/Settings/RewardSettings.vue'
import { showServerRefusal } from '../../../src/utils/errorToast.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => Object.entries(vars).reduce((out, [key, value]) => out.replace(`{${key}}`, String(value)), text),
}))
jest.mock('@/utils/logger', () => ({
	logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() },
}))
jest.mock('../../../src/utils/errorToast.js', () => ({ showServerRefusal: jest.fn() }))
jest.mock('../../../src/components/Rewards/RewardCatalogEditor.vue', () => ({
	name: 'RewardCatalogEditor',
	template: '<div data-testid="catalog-editor-stub" />',
}))

let mockUserStore
let mockHouseStore
jest.mock('../../../src/store/user.js', () => ({ useUserStore: () => mockUserStore }))
jest.mock('../../../src/store/house.js', () => ({ useHouseStore: () => mockHouseStore }))

const dayKey = (offset) => new Date(Date.now() + offset * 86400000).toISOString().slice(0, 10)

/**
 * The owner's two coin multipliers and what Today shows of them
 * (CS-PLAN-091 WP-B5, ADR-021 decision 11).
 */
describe('coin multipliers', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		mockUserStore = reactive({
			houseUsers: [
				{ id: 2, nextcloudUserId: 'kid', displayName: 'Kid', coinFactor: 2 },
				{ id: 1, nextcloudUserId: 'anna', displayName: 'Anna', coinFactor: 1 },
			],
			fetchHouseUsers: jest.fn().mockResolvedValue([]),
			setCoinFactor: jest.fn().mockResolvedValue({}),
		})
		mockHouseStore = reactive({
			timezone: 'UTC',
			bonusPeriod: null,
			startBonusPeriod: jest.fn().mockResolvedValue({}),
			cancelBonusPeriod: jest.fn().mockResolvedValue(undefined),
		})
	})

	describe('CoinFactorSettings', () => {
		const rows = (wrapper) => wrapper.findAll('[data-testid="coin-factor-row"]')
		const select = (wrapper, index) => rows(wrapper)[index].get('[data-testid="coin-factor-select"]')

		it('lists every member by name with their factor and the three choices', () => {
			const wrapper = mount(CoinFactorSettings)

			expect(rows(wrapper).map((row) => row.attributes('data-user-id'))).toEqual(['anna', 'kid'])
			expect(select(wrapper, 1).element.value).toBe('2')
			expect(select(wrapper, 1).findAll('option').map((option) => option.text())).toEqual(['× 1', '× 2', '× 3'])
			expect(select(wrapper, 1).attributes('aria-label')).toBe('Coin factor of Kid')
			expect(wrapper.text()).toContain('Energy, the weekly goal and the streak stay the same')
			// The members are in the store already: no second read.
			expect(mockUserStore.fetchHouseUsers).not.toHaveBeenCalled()
		})

		it('saves a changed factor at once and confirms it', async () => {
			const wrapper = mount(CoinFactorSettings)

			await select(wrapper, 0).setValue('3')
			await flushPromises()

			expect(mockUserStore.setCoinFactor).toHaveBeenCalledWith('anna', 3)
			expect(wrapper.get('[data-testid="coin-factor-saved"]').text()).toBe('The coin factor of Anna was saved.')
		})

		it('shows why a save was refused, without a confirmation', async () => {
			const refusal = Object.assign(new Error('Owner role required'), { status: 403, code: 'FORBIDDEN' })
			mockUserStore.setCoinFactor.mockRejectedValue(refusal)
			const wrapper = mount(CoinFactorSettings)

			await select(wrapper, 0).setValue('2')
			await flushPromises()

			expect(showServerRefusal).toHaveBeenCalledWith(refusal, 'Could not save the coin factor')
			expect(wrapper.find('[data-testid="coin-factor-saved"]').exists()).toBe(false)
			expect(select(wrapper, 0).element.disabled).toBe(false)
		})

		it('reads the members where no view has, and offers a retry when that fails', async () => {
			mockUserStore.houseUsers = []
			mockUserStore.fetchHouseUsers.mockRejectedValueOnce(new Error('offline'))
			const wrapper = mount(CoinFactorSettings)
			await flushPromises()

			await wrapper.get('[data-testid="coin-factor-load-error"] button').trigger('click')
			await flushPromises()

			expect(mockUserStore.fetchHouseUsers).toHaveBeenCalledTimes(2)
			expect(wrapper.find('[data-testid="coin-factor-load-error"]').exists()).toBe(false)
		})
	})

	describe('BonusDaysSettings', () => {
		it('starts bonus days from today for one to three days', async () => {
			const wrapper = mount(BonusDaysSettings)

			expect(wrapper.get('[data-testid="bonus-days-start"]').element.value).toBe(dayKey(0))
			expect(wrapper.get('[data-testid="bonus-days-start"]').attributes('min')).toBe(dayKey(0))
			expect(wrapper.get('[data-testid="bonus-days-length"]').findAll('option').map((option) => option.text())).toEqual(['1 day', '2 days', '3 days'])
			await wrapper.get('[data-testid="bonus-days-start"]').setValue(dayKey(2))
			await wrapper.get('[data-testid="bonus-days-length"]').setValue('3')
			await wrapper.get('[data-testid="bonus-days-form"]').trigger('submit')
			await flushPromises()

			expect(mockHouseStore.startBonusPeriod).toHaveBeenCalledWith(dayKey(2), 3)
		})

		it.each([
			[{ startsOn: dayKey(-1), endsOn: dayKey(1) }, 'Running until '],
			[{ startsOn: dayKey(0), endsOn: dayKey(0) }, 'Running today'],
			[{ startsOn: dayKey(3), endsOn: dayKey(3) }, 'Planned for '],
			[{ startsOn: dayKey(3), endsOn: dayKey(5) }, 'Planned from '],
		])('shows the period of the house instead of the form and cancels it: %j', async (period, label) => {
			mockHouseStore.bonusPeriod = { id: 3, ...period }
			const wrapper = mount(BonusDaysSettings)

			expect(wrapper.find('[data-testid="bonus-days-form"]').exists()).toBe(false)
			expect(wrapper.get('[data-testid="bonus-days-period"]').text()).toContain(label)
			await wrapper.get('[data-testid="bonus-days-cancel"]').trigger('click')
			await flushPromises()

			expect(mockHouseStore.cancelBonusPeriod).toHaveBeenCalledWith(3)
		})

		it('shows why the server refused to start them', async () => {
			const refusal = Object.assign(new Error('Bonus days are already planned for this time'), { status: 409, code: 'CONFLICT' })
			mockHouseStore.startBonusPeriod.mockRejectedValue(refusal)
			const wrapper = mount(BonusDaysSettings)

			await wrapper.get('[data-testid="bonus-days-form"]').trigger('submit')
			await flushPromises()

			expect(showServerRefusal).toHaveBeenCalledWith(refusal, 'Could not start the bonus days')
			expect(wrapper.get('[data-testid="bonus-days-submit"]').element.disabled).toBe(false)
		})
	})

	describe('BonusDaysBanner', () => {
		const mountBanner = (period) => mount(BonusDaysBanner, { props: { period, today: dayKey(0) } })

		it('shows only a period that runs today', () => {
			expect(mountBanner(null).find('[data-testid="today-bonus-days"]').exists()).toBe(false)
			expect(mountBanner({ startsOn: dayKey(1), endsOn: dayKey(2) }).find('[data-testid="today-bonus-days"]').exists()).toBe(false)
			expect(mountBanner({ startsOn: dayKey(-3), endsOn: dayKey(-1) }).find('[data-testid="today-bonus-days"]').exists()).toBe(false)
		})

		it('says until when the coins count more, or that today is the last day', () => {
			expect(mountBanner({ startsOn: dayKey(0), endsOn: dayKey(2) }).get('.bonus-days-text').text()).toMatch(/^Bonus days: coins count × 1\.5 until .+\.$/)
			const last = mountBanner({ startsOn: dayKey(-1), endsOn: dayKey(0) })
			expect(last.get('.bonus-days-text').text()).toBe('Bonus day: coins count × 1.5 today.')
			expect(last.get('[data-testid="today-bonus-days"]').attributes('role')).toBe('status')
		})
	})

	it('RewardSettings hosts the two multipliers above the catalog', () => {
		const wrapper = mount(RewardSettings)
		const html = wrapper.html()

		expect(html.indexOf('coin-factor-settings')).toBeLessThan(html.indexOf('bonus-days-settings'))
		expect(html.indexOf('bonus-days-settings')).toBeLessThan(html.indexOf('catalog-editor-stub'))
	})
})
