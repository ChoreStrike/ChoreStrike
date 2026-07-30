import { mount } from '@vue/test-utils'
import FlexWindowDisplay from '@/components/Tasks/FlexWindowDisplay.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

describe('FlexWindowDisplay', () => {
	const baseProps = {
		earliest: '2026-07-28',
		ideal: '2026-07-30',
		latest: '2026-08-02',
		flexDays: 3,
	}

	it('renders nothing when flexDays is 0', () => {
		const wrapper = mount(FlexWindowDisplay, {
			props: { ...baseProps, flexDays: 0 },
		})
		expect(wrapper.find('.flex-window-display').exists()).toBe(false)
	})

	it('renders nothing when dates are missing', () => {
		const wrapper = mount(FlexWindowDisplay, {
			props: { earliest: null, ideal: null, latest: null, flexDays: 3 },
		})
		expect(wrapper.find('.flex-window-display').exists()).toBe(false)
	})

	it('renders flex window when all props present', () => {
		const wrapper = mount(FlexWindowDisplay, { props: baseProps })
		expect(wrapper.find('.flex-window-display').exists()).toBe(true)
	})

	it('shows flex days badge', () => {
		const wrapper = mount(FlexWindowDisplay, { props: baseProps })
		expect(wrapper.text()).toContain('3')
	})

	it('hides status when showStatus is false', () => {
		const wrapper = mount(FlexWindowDisplay, {
			props: { ...baseProps, showStatus: false },
		})
		expect(wrapper.find('.flex-status').exists()).toBe(false)
	})

	it('renders compact variant', () => {
		const wrapper = mount(FlexWindowDisplay, {
			props: { ...baseProps, compact: true },
		})
		expect(wrapper.find('.compact').exists()).toBe(true)
	})
})
