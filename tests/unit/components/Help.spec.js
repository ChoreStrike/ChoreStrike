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

	})

	// RF-091-13: the work shares left the page of the weekly plan with it and
	// are a term of the glossary, with the honest share arithmetic (10 Coins
	// once, split — never multiplied per helper).
	it('explains work shares in the glossary, no longer as a context topic', () => {
		const glossary = mount(HelpGlossary)
		const shares = glossary.get('[data-testid="help-term-shares"]').text()
		expect(shares).toContain('3.4/3.3/3.3')
		expect(shares).toContain('before coin factors and bonus days')

		const entry = mount(HelpEntry, { props: { variant: 'context', topic: 'shares' } })
		expect(entry.vm.topics.shares).toBeUndefined()
	})

	// CS-PLAN-091 WP-C: Today shows the suggestion, and the glossary says what it is.
	it('explains the suggestion and names it on Today', () => {
		const glossary = mount(HelpGlossary)
		expect(glossary.get('[data-testid="help-term-today"]').text()).toContain('what is suggested for today')
		const suggestion = glossary.get('[data-testid="help-term-suggestion"]').text()
		expect(suggestion).toContain('preferred days first')
		expect(suggestion).toContain('stays on today\'s list')
		expect(suggestion).toContain('you may do any task on any day')
	})

	// CS-PLAN-091 WP-G: the terms Release B introduced have a glossary entry each.
	it('explains postponing, sleeping and bonus tasks and the two coin multipliers', () => {
		const glossary = mount(HelpGlossary)
		const term = id => glossary.get(`[data-testid="help-term-${id}"]`).text()
		expect(term('postpone')).toContain('half its flex days, up to three times')
		expect(term('postpone')).toContain('already overdue, cannot be postponed')
		expect(term('sleeping')).toContain('never overdue and is not suggested')
		expect(term('bonus-task')).toContain('once per person and day')
		expect(term('bonus-task')).toContain('neither for nor against the weekly goal')
		expect(term('coin-factor')).toContain('× 1, × 2 or × 3')
		expect(term('coin-factor')).toContain('× 1.5')
		expect(term('coin-factor')).toContain('Coins only')
	})

	// ADR-021 (CS-PLAN-091 WP-B7): windows replaced agreed days and catch-up
	// in every topic, and early work is on time.
	it('explains due windows without agreements or catch-up in every topic', async () => {
		for (const topic of ['setup', 'progress', 'catalog', 'owner-pricing']) {
			const wrapper = mount(HelpEntry, { props: { variant: 'context', topic } })
			await wrapper.find('button').trigger('click')
			expect(wrapper.find('.topic-body').text()).not.toMatch(/agreement|catch-up/i)
		}
		const progress = mount(HelpEntry, { props: { variant: 'context', topic: 'progress' } })
		await progress.find('button').trigger('click')
		expect(progress.text()).toContain('or earlier, is on time')
		expect(progress.text()).toContain('entirely inside your absence is neutral')
	})

	it('carries the essential onboarding topics (setup, progress, catalog, owner pricing)', async () => {
		for (const topic of ['setup', 'progress', 'catalog', 'owner-pricing']) {
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

		for (const term of ['today', 'window', 'overdue', 'absence', 'streak', 'quiet-time']) {
			expect(wrapper.find(`[data-testid="help-term-${term}"]`).exists()).toBe(true)
		}
		// Windows replaced agreed days and catch-up (ADR-021, CS-PLAN-091 WP-B7).
		for (const term of ['agreement', 'catch-up']) {
			expect(wrapper.find(`[data-testid="help-term-${term}"]`).exists()).toBe(false)
		}
		expect(wrapper.get('[data-testid="help-term-overdue"]').text()).toContain('one red entry')
		// A fixed day's red entry ends with the next window (ADR-021 decision 1).
		expect(wrapper.get('[data-testid="help-term-overdue"]').text()).toContain('until the next window opens')
		expect(wrapper.get('[data-testid="help-term-streak"]').text()).toContain('neither add to it nor break it')
		expect(wrapper.text()).not.toMatch(/agreement|catch-up/i)
		// The neutral absence semantics (C2b, ADR-020).
		expect(wrapper.text()).toContain('never counts against you; work you do during it still counts')
		expect(wrapper.text()).toContain('no reason and no request are needed')
		// Today is the one landing page and shows the own balance (WP-B).
		expect(wrapper.get('[data-testid="help-term-today"]').text()).toContain('what is overdue')
		expect(wrapper.text()).toContain('Today shows your available balance')
		expect(wrapper.text()).not.toContain('My Day')
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
