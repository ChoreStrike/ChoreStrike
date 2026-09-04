import { mount } from '@vue/test-utils'
import NotAuthorizedState from '../../../src/components/Common/NotAuthorizedState.vue'

/**
 * NotAuthorizedState (CS-PLAN-061 §3.9): the app-wide authorization denial
 * view. Removed vs. never-invited is deliberately indistinguishable
 * (anti-enumeration) — the copy must state the invite path without
 * revealing which case applies.
 */
describe('NotAuthorizedState', () => {
	it('renders the not-authorized testid', () => {
		const wrapper = mount(NotAuthorizedState)

		expect(wrapper.find('[data-testid="not-authorized-state"]').exists()).toBe(true)
	})

	it('shows the invite hint copy', () => {
		const wrapper = mount(NotAuthorizedState)
		const text = wrapper.text()

		expect(text).toContain('You are not a member of this household yet')
		expect(text).toContain('Ask the household owner to invite you')
	})

	it('emits retry when the retry button is clicked', async () => {
		const wrapper = mount(NotAuthorizedState)

		await wrapper.find('[data-testid="not-authorized-retry"]').trigger('click')

		expect(wrapper.emitted('retry')).toHaveLength(1)
	})
})
