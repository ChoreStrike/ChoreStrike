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
 * Essential help slice (CS-PLAN-070 WP6 §3.7 + WP8 §3.6, FEAT-UX-023):
 * the plain glossary is reachable from the navigation entry; contextual
 * topic triggers render inline outside navigation. Both use the shared
 * named Modal for focus/keyboard behavior — never a raw NcModal.
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

	it('opens the shared named Modal (dialog role), not a raw NcModal', async () => {
		const wrapper = mount(HelpEntry)
		await wrapper.find('[data-testid="nav-help"]').trigger('click')

		const dialog = wrapper.find('[role="dialog"][aria-modal="true"]')
		expect(dialog.exists()).toBe(true)
		expect(wrapper.find('[data-testid="help-modal"]').exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'NcModal' }).exists()).toBe(false)
	})

	// CS-PLAN-070 WP8 §3.6: contextual topic triggers for the essential
	// onboarding copy, usable outside the navigation.
	it('renders a context trigger outside navigation that opens only its topic', async () => {
		const wrapper = mount(HelpEntry, { props: { variant: 'context', topic: 'catalog' } })

		expect(wrapper.find('[data-testid="nav-help"]').exists()).toBe(false)
		const trigger = wrapper.find('[data-testid="help-context-catalog"]')
		expect(trigger.exists()).toBe(true)
		expect(trigger.attributes('aria-expanded')).toBe('false')

		await trigger.trigger('click')
		expect(trigger.attributes('aria-expanded')).toBe('true')
		// The topic body renders; the full glossary does not.
		expect(wrapper.text()).toContain('reserve the Coins first')
		expect(wrapper.find('[data-testid="help-glossary"]').exists()).toBe(false)

		// The agreements topic carries the honest share arithmetic
		// (10 Coins once, split — never multiplied per helper).
		const agreements = mount(HelpEntry, { props: { variant: 'context', topic: 'agreements' } })
		await agreements.find('button').trigger('click')
		expect(agreements.text()).toContain('3.4/3.3/3.3')
	})

	it('carries the essential onboarding topics (setup, agreements, progress, catalog, owner pricing)', async () => {
		for (const topic of ['setup', 'agreements', 'progress', 'catalog', 'owner-pricing']) {
			const wrapper = mount(HelpEntry, { props: { variant: 'context', topic } })
			expect(wrapper.find(`[data-testid="help-context-${topic}"]`).exists()).toBe(true)
			await wrapper.find('button').trigger('click')
			expect(wrapper.find('[data-testid="help-modal"]').exists()).toBe(true)
		}
	})

	it('opens the full glossary for an unknown topic instead of rendering nothing', async () => {
		const wrapper = mount(HelpEntry, { props: { variant: 'context', topic: 'nope' } })
		await wrapper.find('button').trigger('click')
		expect(wrapper.find('[data-testid="help-glossary"]').exists()).toBe(true)
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

	// CS-PLAN-070 WP8 §3.6: the reward terms join the glossary.
	it('explains the reward catalog, requests and balance clarifications', () => {
		const wrapper = mount(HelpGlossary)

		for (const term of ['reward-catalog', 'reward-request', 'balance-clarification']) {
			expect(wrapper.find(`[data-testid="help-term-${term}"]`).exists()).toBe(true)
		}
		expect(wrapper.text()).toContain('reserved')
	})

	it('keeps the glossary free of engagement prompts', () => {
		const wrapper = mount(HelpGlossary)

		expect(wrapper.text()).not.toContain('Defend')
		expect(wrapper.text()).not.toContain('level up')
		expect(wrapper.text()).not.toContain('combo')
	})
})
