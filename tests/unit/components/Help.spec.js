/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount } from '@vue/test-utils'
import HelpEntry from '@/components/Help/HelpEntry.vue'
import HelpGlossary from '@/components/Help/HelpGlossary.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

jest.mock('@nextcloud/vue', () => ({
	NcModal: {
		name: 'NcModal',
		props: ['canClose'],
		emits: ['close'],
		template: '<div class="modal-stub"><slot /></div>',
	},
}))

/**
 * Essential help slice (CS-PLAN-070 WP6 §3.7, FEAT-UX-023): the plain
 * glossary is reachable from the navigation entry and explains the core
 * household terms aligned with the delivered semantics.
 */
describe('Help entry and glossary', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	it('renders the navigation entry with an accessible expanded state', async () => {
		const wrapper = mount(HelpEntry)
		const button = wrapper.find('[data-testid="nav-help"]')

		expect(button.exists()).toBe(true)
		expect(button.attributes('aria-expanded')).toBe('false')

		await button.trigger('click')
		expect(button.attributes('aria-expanded')).toBe('true')
	})

	it('opens the glossary in a modal and closes it again', async () => {
		const wrapper = mount(HelpEntry)
		await wrapper.find('[data-testid="nav-help"]').trigger('click')

		const glossary = wrapper.find('[data-testid="help-glossary"]')
		expect(glossary.exists()).toBe(true)

		await wrapper.find('[data-testid="help-close"]').trigger('click')
		expect(wrapper.find('[data-testid="help-glossary"]').exists()).toBe(false)
	})

	it('explains the core household terms in plain family language', () => {
		const wrapper = mount(HelpGlossary)

		for (const term of ['my-day', 'agreement', 'catch-up', 'pause', 'streak', 'quiet-time']) {
			expect(wrapper.find(`[data-testid="help-term-${term}"]`).exists()).toBe(true)
		}
		// The C1d cover semantics are explained honestly.
		expect(wrapper.text()).toContain('it stays a catch-up')
		// The neutral pause semantics (C2b).
		expect(wrapper.text()).toContain('nothing counts for or against anyone')
		// The calm landing statement (C5f): Coins never on My Day.
		expect(wrapper.text()).toContain('never on My Day')
	})

	it('keeps the glossary free of engagement prompts', () => {
		const wrapper = mount(HelpGlossary)

		expect(wrapper.text()).not.toContain('Defend')
		expect(wrapper.text()).not.toContain('level up')
		expect(wrapper.text()).not.toContain('combo')
	})
})
