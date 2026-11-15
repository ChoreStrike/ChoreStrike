/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest } from '@jest/globals'
import { mount } from '@vue/test-utils'
import RewardWalletBanner from '../../../src/components/Rewards/RewardWalletBanner.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

const mountBanner = (wallet) => mount(RewardWalletBanner, { props: { wallet } })

describe('RewardWalletBanner', () => {
	it('shows the available and reserved Coins of the wallet it is given', () => {
		const wrapper = mountBanner({ availableMinor: 2300, reservedMinor: 700, fundingHold: false })

		expect(wrapper.find('[data-testid="rewards-wallet"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="wallet-available"]').text()).toContain('23')
		expect(wrapper.find('[data-testid="wallet-reserved"]').text()).toContain('7')
		expect(wrapper.find('[data-testid="wallet-hold"]').exists()).toBe(false)
	})

	it('announces a funding hold as a status', () => {
		const wrapper = mountBanner({ availableMinor: 0, reservedMinor: 0, fundingHold: true })

		const hold = wrapper.find('[data-testid="wallet-hold"]')
		expect(hold.exists()).toBe(true)
		expect(hold.attributes('role')).toBe('status')
	})
})
