import { mount } from '@vue/test-utils'
import EmptyState from '../../../src/components/Common/EmptyState.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, vars) => {
		if (vars) {
			return text.replace(/{\w+}/g, match => vars[match.slice(1, -1)] || match)
		}
		return text
	}),
}))

// The global test setup stubs Icon as `<span class="icon">{name}</span>`.

describe('EmptyState (CS-PLAN-044)', () => {
	it('renders the default message with the default icon', () => {
		const wrapper = mount(EmptyState)

		expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(true)
		expect(wrapper.find('.empty-icon .icon').text()).toBe('List')
		expect(wrapper.find('.empty-message').text()).toBe('Nothing here yet')
		expect(wrapper.find('.empty-hint').exists()).toBe(false)
	})

	it('renders message, hint and a custom icon variant', () => {
		const wrapper = mount(EmptyState, {
			props: {
				icon: 'Home',
				message: 'No floors yet',
				hint: 'Create your first floor',
			},
		})

		expect(wrapper.find('.empty-icon .icon').text()).toBe('Home')
		expect(wrapper.text()).toContain('No floors yet')
		expect(wrapper.find('.empty-hint').text()).toBe('Create your first floor')
	})

	it('renders an action slot for the next step', () => {
		const wrapper = mount(EmptyState, {
			slots: {
				action: '<button data-testid="empty-action">Create</button>',
			},
		})

		expect(wrapper.find('[data-testid="empty-action"]').exists()).toBe(true)
	})
})
