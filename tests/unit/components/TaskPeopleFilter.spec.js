/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, expect, it, jest } from '@jest/globals'
import { mount } from '@vue/test-utils'
import TaskPeopleFilter from '../../../src/components/Tasks/TaskPeopleFilter.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, params) => Object.entries(params ?? {})
		.reduce((label, [key, value]) => label.replaceAll(`{${key}}`, String(value)), text)),
}))

const members = [
	{ nextcloudUserId: 'mom', displayName: 'Mom' },
	{ nextcloudUserId: 'teen', displayName: 'Teen' },
	// A member without a display name shows its user id.
	{ nextcloudUserId: 'kid', displayName: '' },
]

/**
 * The people of the task list (CS-PLAN-091 WP-B6, the operator's GUI
 * review): a tick per member and one for unassigned work. The three scope
 * buttons are its presets, so the ticks and the buttons always say the same.
 */
describe('TaskPeopleFilter', () => {
	const mountFilter = (props = {}) => mount(TaskPeopleFilter, { props: { scope: 'mine', members, actorId: 'mom', ...props } })
	const toggle = (wrapper) => wrapper.get('[data-testid="people-filter-toggle"]')
	const open = async (props = {}) => {
		const wrapper = mountFilter(props)
		await toggle(wrapper).trigger('click')
		return wrapper
	}
	const tick = (wrapper, id) => wrapper.get(`[data-testid="people-filter-${id}"]`)
	const ticked = (wrapper) => wrapper.findAll('input[type="checkbox"]').filter((input) => input.element.checked).map((input) => input.attributes('data-testid').replace('people-filter-', ''))
	const lastChange = (wrapper) => wrapper.emitted('change').at(-1)[0]

	it('is one closed button until it is opened, and closes again', async () => {
		const wrapper = mountFilter()
		expect(toggle(wrapper).text()).toContain('People')
		expect(toggle(wrapper).attributes('aria-expanded')).toBe('false')
		expect(wrapper.find('[data-testid="people-filter-all"]').exists()).toBe(false)

		await toggle(wrapper).trigger('click')
		expect(toggle(wrapper).attributes('aria-expanded')).toBe('true')
		const panel = wrapper.get(`[id="${toggle(wrapper).attributes('aria-controls')}"]`)
		expect(panel.attributes('role')).toBe('group')
		expect(panel.attributes('aria-label')).toBe('People')
		expect(panel.findAll('label').map((label) => label.text())).toEqual(['Mom', 'Teen', 'kid', 'Unassigned'])

		await toggle(wrapper).trigger('click')
		expect(wrapper.find('[data-testid="people-filter-all"]').exists()).toBe(false)
	})

	describe('shows what a scope button stands for', () => {
		it.each([
			['mine', ['mom', 'unassigned']],
			['unassigned', ['unassigned']],
			['all', ['mom', 'teen', 'kid', 'unassigned']],
		])('%s ticks %j', async (scope, expected) => {
			const wrapper = await open({ scope })

			expect(ticked(wrapper)).toEqual(expected)
			// A preset is no choice of its own: the button carries no count.
			expect(toggle(wrapper).text()).not.toContain('(')
		})

		it('ticks nobody under My tasks while the viewer is unknown', async () => {
			expect(ticked(await open({ scope: 'mine', actorId: null }))).toEqual(['unassigned'])
		})

		it('follows a scope that changes while it is open', async () => {
			const wrapper = await open({ scope: 'all' })

			await wrapper.setProps({ scope: 'unassigned' })

			expect(ticked(wrapper)).toEqual(['unassigned'])
		})
	})

	describe('a custom choice', () => {
		it('ticks exactly the people it holds and counts them on the button', async () => {
			const wrapper = await open({ scope: 'custom', people: ['teen', 'kid'], unassigned: false })

			expect(ticked(wrapper)).toEqual(['teen', 'kid'])
			expect(toggle(wrapper).text()).toContain('People (2)')
		})

		it('counts unassigned work as one more', () => {
			expect(toggle(mountFilter({ scope: 'custom', people: ['teen'], unassigned: true })).text()).toContain('People (2)')
			expect(toggle(mountFilter({ scope: 'custom', people: [], unassigned: false })).text()).toContain('People (0)')
		})
	})

	describe('ticks become a preset where they are one', () => {
		it('adds a person to My tasks as a custom choice', async () => {
			const wrapper = await open({ scope: 'mine' })

			await tick(wrapper, 'teen').setValue(true)

			expect(lastChange(wrapper)).toEqual({ scope: 'custom', people: ['mom', 'teen'], unassigned: true })
		})

		it('becomes Everyone with the last missing tick', async () => {
			const wrapper = await open({ scope: 'custom', people: ['mom', 'teen'], unassigned: true })

			await tick(wrapper, 'kid').setValue(true)

			expect(lastChange(wrapper)).toEqual({ scope: 'all', people: [], unassigned: true })
		})

		it('becomes My tasks when only the viewer and unassigned work stay', async () => {
			const wrapper = await open({ scope: 'custom', people: ['mom', 'teen'], unassigned: true })

			await tick(wrapper, 'teen').setValue(false)

			expect(lastChange(wrapper)).toEqual({ scope: 'mine', people: [], unassigned: true })
		})

		it('becomes Unassigned when the last person goes', async () => {
			const wrapper = await open({ scope: 'mine' })

			await tick(wrapper, 'mom').setValue(false)

			expect(lastChange(wrapper)).toEqual({ scope: 'unassigned', people: [], unassigned: true })
		})

		it('takes a person out of Everyone', async () => {
			const wrapper = await open({ scope: 'all' })

			await tick(wrapper, 'teen').setValue(false)

			expect(lastChange(wrapper)).toEqual({ scope: 'custom', people: ['mom', 'kid'], unassigned: true })
		})
	})

	describe('the unassigned tick', () => {
		it('leaves a preset for the people alone', async () => {
			const everyone = await open({ scope: 'all' })
			await tick(everyone, 'unassigned').setValue(false)
			expect(lastChange(everyone)).toEqual({ scope: 'custom', people: ['mom', 'teen', 'kid'], unassigned: false })

			const mine = await open({ scope: 'mine' })
			await tick(mine, 'unassigned').setValue(false)
			expect(lastChange(mine)).toEqual({ scope: 'custom', people: ['mom'], unassigned: false })
		})

		it('comes back into a custom choice', async () => {
			const wrapper = await open({ scope: 'custom', people: ['teen'], unassigned: false })

			await tick(wrapper, 'unassigned').setValue(true)

			expect(lastChange(wrapper)).toEqual({ scope: 'custom', people: ['teen'], unassigned: true })
		})
	})

	describe('All and None', () => {
		it('All is Everyone', async () => {
			const wrapper = await open({ scope: 'custom', people: ['teen'], unassigned: false })

			await wrapper.get('[data-testid="people-filter-all"]').trigger('click')

			expect(lastChange(wrapper)).toEqual({ scope: 'all', people: [], unassigned: true })
		})

		it('None unticks everything, unassigned work included: the start of a choice', async () => {
			const wrapper = await open({ scope: 'all' })

			await wrapper.get('[data-testid="people-filter-none"]').trigger('click')
			expect(lastChange(wrapper)).toEqual({ scope: 'custom', people: [], unassigned: false })

			await wrapper.setProps(lastChange(wrapper))
			expect(ticked(wrapper)).toEqual([])
			await tick(wrapper, 'kid').setValue(true)
			expect(lastChange(wrapper)).toEqual({ scope: 'custom', people: ['kid'], unassigned: false })
		})
	})

	describe('while the member names are unavailable', () => {
		it('is off and shows no ticks', () => {
			const wrapper = mountFilter({ disabled: true })

			expect(toggle(wrapper).element.disabled).toBe(true)
			expect(wrapper.find('[data-testid="people-filter-all"]').exists()).toBe(false)
		})

		it('hides an open panel when it turns off, and shows it again afterwards', async () => {
			const wrapper = await open()

			await wrapper.setProps({ disabled: true })
			expect(wrapper.find('[data-testid="people-filter-all"]').exists()).toBe(false)

			await wrapper.setProps({ disabled: false })
			expect(wrapper.find('[data-testid="people-filter-all"]').exists()).toBe(true)
		})
	})

	it('offers only unassigned work while no member is known', async () => {
		const wrapper = await open({ scope: 'unassigned', members: [] })

		expect(wrapper.findAll('label').map((label) => label.text())).toEqual(['Unassigned'])
		expect(ticked(wrapper)).toEqual(['unassigned'])

		// All is Everyone also then: it never depends on the member list.
		await wrapper.get('[data-testid="people-filter-all"]').trigger('click')
		expect(lastChange(wrapper)).toEqual({ scope: 'all', people: [], unassigned: true })
	})
})
