/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, beforeEach, jest } from '@jest/globals'
import { mount } from '@vue/test-utils'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, message, params) => {
		if (params && message.includes('{titles}')) {
			return message.replace('{titles}', params.titles)
		}
		return message
	}),
}))

import { translate as t } from '@nextcloud/l10n'
import TitleBadge from '../../../src/components/Profile/TitleBadge.vue'

describe('TitleBadge', () => {
	beforeEach(() => jest.clearAllMocks())

	it('renders nothing when there is no title', () => {
		const wrapper = mount(TitleBadge, { props: { title: null } })
		expect(wrapper.find('[data-testid="title-badge"]').exists()).toBe(false)
	})

	it('renders the localized label and tier class', () => {
		const wrapper = mount(TitleBadge, {
			props: {
				title: {
					labelKey: 'titles.team_energy.team_anchor',
					tierIndex: 2,
					pack: 'team_energy',
				},
			},
		})
		const badge = wrapper.find('[data-testid="title-badge"]')
		expect(badge.exists()).toBe(true)
		expect(badge.classes()).toContain('tier-gold')
		expect(badge.text()).toContain('titles.team_energy.team_anchor')
	})

	it('uses the compact class when compact', () => {
		const wrapper = mount(TitleBadge, {
			props: {
				title: { labelKey: 'x', tierIndex: 0 },
				compact: true,
			},
		})
		expect(wrapper.find('[data-testid="title-badge"]').classes()).toContain('compact')
	})

	it('builds a history tooltip from unlocked tiers', () => {
		const wrapper = mount(TitleBadge, {
			props: {
				title: { labelKey: 'now', tierIndex: 1 },
				unlocked: [
					{ labelKey: 'tier-one', tierIndex: 0 },
					{ labelKey: 'tier-two', tierIndex: 1 },
				],
			},
		})
		const title = wrapper.find('[data-testid="title-badge"]').attributes('title')
		expect(title).toContain('tier-one')
		expect(title).toContain('tier-two')
	})

	it('omits the tooltip when no unlocked tiers are provided', () => {
		const wrapper = mount(TitleBadge, {
			props: { title: { labelKey: 'now', tierIndex: 0 } },
		})
		expect(wrapper.find('[data-testid="title-badge"]').attributes('title')).toBeFalsy()
	})
})
