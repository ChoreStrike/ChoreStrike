/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { mount } from '@vue/test-utils'
import SettingsCategory from '../../../src/components/Settings/SettingsCategory.vue'

const mountCategory = (props = {}) => mount(SettingsCategory, {
	props: { id: 'house', title: 'House', testid: 'house-section', ...props },
	slots: { default: '<p data-testid="body">Body</p>' },
})

describe('SettingsCategory (FEAT-UX-054)', () => {
	it('is a closed heading button that does not render its body', () => {
		const wrapper = mountCategory()
		const toggle = wrapper.get('[data-testid="settings-category-house"]')

		expect(wrapper.attributes('data-testid')).toBe('house-section')
		expect(wrapper.get('h2 button > span:last-child').text()).toBe('House')
		expect(toggle.attributes('aria-expanded')).toBe('false')
		expect(toggle.attributes('aria-controls')).toBe('settings-panel-house')
		expect(wrapper.find('[data-testid="body"]').exists()).toBe(false)
	})

	it('renders its body in the controlled panel while open', () => {
		const wrapper = mountCategory({ open: true })

		expect(wrapper.get('[data-testid="settings-category-house"]').attributes('aria-expanded')).toBe('true')
		expect(wrapper.get('#settings-panel-house [data-testid="body"]').text()).toBe('Body')
	})

	it('reports a toggle with its id and marks the danger zone', async () => {
		const wrapper = mountCategory({ id: 'danger', danger: true })

		await wrapper.get('[data-testid="settings-category-danger"]').trigger('click')

		expect(wrapper.emitted('toggle')).toEqual([['danger']])
		expect(wrapper.classes()).toContain('danger')
	})
})
