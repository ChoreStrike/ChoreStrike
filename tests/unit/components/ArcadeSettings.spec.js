/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest } from '@jest/globals'
import { mount } from '@vue/test-utils'
import ArcadeSettings from '@/components/Settings/ArcadeSettings.vue'

jest.mock('../../../src/components/Minigames/ArcadeGateSetting.vue', () => ({ name: 'ArcadeGateSetting', template: '<div data-testid="stub-gate" />' }))
jest.mock('../../../src/components/Minigames/ArcadeThresholdSettings.vue', () => ({ name: 'ArcadeThresholdSettings', template: '<div data-testid="stub-thresholds" />' }))
jest.mock('../../../src/components/Minigames/ArcadeResetList.vue', () => ({ name: 'ArcadeResetList', template: '<div data-testid="stub-reset" />' }))

/**
 * CS-PLAN-090 WP-J: the household's arcade category holds the gate, the
 * thresholds and the reset, in that order, each on its own card.
 */
describe('ArcadeSettings', () => {
	it('hosts the gate, the thresholds and the reset on their own cards', () => {
		const wrapper = mount(ArcadeSettings)

		const cards = wrapper.findAll('.setting-card')
		expect(cards.map((card) => card.element.firstElementChild.getAttribute('data-testid')))
			.toEqual(['stub-gate', 'stub-thresholds', 'stub-reset'])
		// The three bring their own styles; the shared settings rules must
		// not reach them (settings.css).
		expect(wrapper.classes()).not.toContain('cs-settings-form')
	})
})
