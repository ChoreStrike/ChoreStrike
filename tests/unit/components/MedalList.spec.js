import { describe, it, expect, vi } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import MedalList from '../../../src/components/Common/MedalList.vue'
import MedalBadge from '../../../src/components/Common/MedalBadge.vue'

// Mock nextcloud l10n
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, text) => text,
}))

describe('MedalList.vue', () => {
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
	})

	it('renders empty state when no medals', () => {
		const wrapper = mount(MedalList, {
			props: { medals: [], emptyMessage: 'No medals yet' },
			global: {
				plugins: [pinia],
				components: { MedalBadge },
			},
		})

		expect(wrapper.find('.empty-state').exists()).toBe(true)
		expect(wrapper.find('.empty-state').text()).toBe('No medals yet')
	})

	it('renders grouped medals', () => {
		const medals = [
			{ id: 1, medalType: 'gold', awardedAt: '2024-01-01' },
			{ id: 2, medalType: 'silver', awardedAt: '2024-01-02' },
		]

		const wrapper = mount(MedalList, {
			props: { medals },
			global: {
				plugins: [pinia],
				components: { MedalBadge },
			},
		})

		expect(wrapper.find('.medal-groups').exists()).toBe(true)
		expect(wrapper.findAll('.medal-group')).toHaveLength(2)
	})

	it('groups medals by type and counts them', () => {
		const medals = [
			{ id: 1, medalType: 'gold', awardedAt: '2024-01-01' },
			{ id: 2, medalType: 'gold', awardedAt: '2024-01-02' },
			{ id: 3, medalType: 'silver', awardedAt: '2024-01-03' },
		]

		const wrapper = mount(MedalList, {
			props: { medals },
			global: {
				plugins: [pinia],
				components: { MedalBadge },
			},
		})

		const counts = wrapper.findAll('.medal-count')
		expect(counts[0].text()).toBe('×2') // gold group
		expect(counts[1].text()).toBe('×1') // silver group
	})

	it('renders MedalBadge for each group', () => {
		const medals = [
			{ id: 1, medalType: 'team_anchor', awardedAt: '2024-01-01' },
		]

		const wrapper = mount(MedalList, {
			props: { medals },
			global: {
				plugins: [pinia],
				components: { MedalBadge },
			},
		})

		expect(wrapper.findComponent(MedalBadge).exists()).toBe(true)
	})

	it('applies badgeSize prop to MedalBadge', () => {
		const medals = [
			{ id: 1, medalType: 'gold', awardedAt: '2024-01-01' },
		]

		const wrapper = mount(MedalList, {
			props: { medals, badgeSize: 'large' },
			global: {
				plugins: [pinia],
				components: { MedalBadge },
			},
		})

		const badge = wrapper.findComponent(MedalBadge)
		expect(badge.props('size')).toBe('large')
	})

	it('shows dates when showDates is true', () => {
		const medals = [
			{ id: 1, medalType: 'gold', awardedAt: '2024-01-15T00:00:00Z' },
		]

		const wrapper = mount(MedalList, {
			props: { medals, showDates: true },
			global: {
				plugins: [pinia],
				components: { MedalBadge },
			},
		})

		expect(wrapper.find('.medal-date').exists()).toBe(true)
	})

	it('does not show dates when showDates is false', () => {
		const medals = [
			{ id: 1, medalType: 'gold', awardedAt: '2024-01-15' },
		]

		const wrapper = mount(MedalList, {
			props: { medals, showDates: false },
			global: {
				plugins: [pinia],
				components: { MedalBadge },
			},
		})

		expect(wrapper.find('.medal-date').exists()).toBe(false)
	})

	it('emits select event when medal group clicked', () => {
		const medals = [
			{ id: 1, medalType: 'gold', awardedAt: '2024-01-01' },
		]

		const wrapper = mount(MedalList, {
			props: { medals },
			global: {
				plugins: [pinia],
				components: { MedalBadge },
			},
		})

		wrapper.find('.medal-group').trigger('click')
		expect(wrapper.emitted('select')).toBeTruthy()
	})

	it('sorts groups by count descending', () => {
		const medals = [
			{ id: 1, medalType: 'silver', awardedAt: '2024-01-01' },
			{ id: 2, medalType: 'gold', awardedAt: '2024-01-02' },
			{ id: 3, medalType: 'gold', awardedAt: '2024-01-03' },
		]

		const wrapper = mount(MedalList, {
			props: { medals },
			global: {
				plugins: [pinia],
				components: { MedalBadge },
			},
		})

		const counts = wrapper.findAll('.medal-count')
		expect(counts[0].text()).toBe('×2') // gold first (higher count)
		expect(counts[1].text()).toBe('×1') // silver second
	})

	it('handles medals with createdAt instead of awardedAt', () => {
		const medals = [
			{ id: 1, medalType: 'bronze', createdAt: '2024-01-15T00:00:00Z' },
		]

		const wrapper = mount(MedalList, {
			props: { medals, showDates: true },
			global: {
				plugins: [pinia],
				components: { MedalBadge },
			},
		})

		expect(wrapper.find('.medal-date').exists()).toBe(true)
	})

	it('handles medals without any date', () => {
		const medals = [
			{ id: 1, medalType: 'gold' },
		]

		const wrapper = mount(MedalList, {
			props: { medals, showDates: true },
			global: {
				plugins: [pinia],
				components: { MedalBadge },
			},
		})

		// Should not throw error
		expect(wrapper.find('.medal-group').exists()).toBe(true)
	})
})
