import { describe, it, expect, vi } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import MedalDetails from '../../../src/components/Common/MedalDetails.vue'
import MedalBadge from '../../../src/components/Common/MedalBadge.vue'

// Mock nextcloud l10n
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => {
		if (vars) {
			return text.replace(/{(\w+)}/g, (match, key) => vars[key] || match)
		}
		return text
	},
	translatePlural: (app, text) => text,
}))

describe('MedalDetails.vue', () => {
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
	})

	it('renders with medal prop', () => {
		const medal = {
			id: 1,
			medalType: 'team_anchor',
			userName: 'John Doe',
			awardedAt: '2024-01-15T10:30:00Z',
			value: 100,
		}

		const wrapper = mount(MedalDetails, {
			props: { medal },
			global: {
				plugins: [pinia],
				components: { MedalBadge },
			},
		})

		expect(wrapper.find('.medal-details').exists()).toBe(true)
	})

	it('renders MedalBadge component', () => {
		const medal = {
			id: 1,
			medalType: 'flow_keeper',
		}

		const wrapper = mount(MedalDetails, {
			props: { medal },
			global: {
				plugins: [pinia],
				components: { MedalBadge },
			},
		})

		expect(wrapper.findComponent(MedalBadge).exists()).toBe(true)
	})

	it('displays medal value when present', () => {
		const medal = {
			id: 1,
			medalType: 'team_anchor',
			value: 150,
		}

		const wrapper = mount(MedalDetails, {
			props: { medal },
			global: {
				plugins: [pinia],
				components: { MedalBadge },
			},
		})

		expect(wrapper.find('.medal-value').exists()).toBe(true)
		expect(wrapper.find('.medal-value').text()).toContain('150')
	})

	it('does not display value when medal has no value', () => {
		const medal = {
			id: 1,
			medalType: 'room_guardian',
		}

		const wrapper = mount(MedalDetails, {
			props: { medal },
			global: {
				plugins: [pinia],
				components: { MedalBadge },
			},
		})

		expect(wrapper.find('.medal-value').exists()).toBe(false)
	})

	it('displays user name when present', () => {
		const medal = {
			id: 1,
			medalType: 'team_player',
			userName: 'Jane Smith',
		}

		const wrapper = mount(MedalDetails, {
			props: { medal },
			global: {
				plugins: [pinia],
				components: { MedalBadge },
			},
		})

		const infoRows = wrapper.findAll('.info-row')
		const userNameRow = infoRows.find(row => row.text().includes('Awarded to:'))
		expect(userNameRow.exists()).toBe(true)
		expect(userNameRow.text()).toContain('Jane Smith')
	})

	it('formats awarded date correctly', () => {
		const medal = {
			id: 1,
			medalType: 'streak_keeper',
			awardedAt: '2024-01-15T10:30:00Z',
		}

		const wrapper = mount(MedalDetails, {
			props: { medal },
			global: {
				plugins: [pinia],
				components: { MedalBadge },
			},
		})

		const infoRows = wrapper.findAll('.info-row')
		const awardedRow = infoRows.find(row => row.text().includes('Awarded:'))
		expect(awardedRow.exists()).toBe(true)
	})

	it('displays week range when weekStart is present', () => {
		const medal = {
			id: 1,
			medalType: 'flow_keeper',
			weekStart: '2024-01-15T00:00:00Z',
		}

		const wrapper = mount(MedalDetails, {
			props: { medal },
			global: {
				plugins: [pinia],
				components: { MedalBadge },
			},
		})

		const infoRows = wrapper.findAll('.info-row')
		const weekRow = infoRows.find(row => row.text().includes('Week:'))
		expect(weekRow.exists()).toBe(true)
	})

	it('displays description for known medal types', () => {
		const medal = {
			id: 1,
			medalType: 'team_anchor',
		}

		const wrapper = mount(MedalDetails, {
			props: { medal },
			global: {
				plugins: [pinia],
				components: { MedalBadge },
			},
		})

		expect(wrapper.find('.medal-description').exists()).toBe(true)
		expect(wrapper.find('.medal-description').text()).toContain('pillar')
	})

	it('does not display description for unknown medal types', () => {
		const medal = {
			id: 1,
			medalType: 'unknown_type',
		}

		const wrapper = mount(MedalDetails, {
			props: { medal },
			global: {
				plugins: [pinia],
				components: { MedalBadge },
			},
		})

		expect(wrapper.find('.medal-description').exists()).toBe(false)
	})

	it('displays all medal types with correct descriptions', () => {
		const medalTypes = [
			{ type: 'team_anchor', keyword: 'pillar' },
			{ type: 'flow_keeper', keyword: 'Consistency' },
			{ type: 'room_guardian', keyword: 'space' },
			{ type: 'team_player', keyword: 'collaborative' },
			{ type: 'streak_keeper', keyword: 'streak' },
		]

		medalTypes.forEach(({ type, keyword }) => {
			const medal = {
				id: 1,
				medalType: type,
			}

			const wrapper = mount(MedalDetails, {
				props: { medal },
				global: {
					plugins: [pinia],
					components: { MedalBadge },
				},
			})

			expect(wrapper.find('.medal-description').text()).toContain(keyword)
		})
	})
})
