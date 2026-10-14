/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, afterEach } from '@jest/globals'
import { mount } from '@vue/test-utils'
import TaskFilterBar from '../../../src/components/Tasks/TaskFilterBar.vue'

const defaults = () => ({ scope: 'mine', person: '', floor: '', room: '', status: 'all', priority: 'all', difficulty: 'all', visibility: 'all', search: '' })

const mountBar = (filters = {}) => mount(TaskFilterBar, {
	props: {
		modelValue: { ...defaults(), ...filters },
		members: [{ nextcloudUserId: 'teen', displayName: 'Teen' }],
		floors: [{ id: 1, name: 'Ground' }, { id: 2, name: 'Upstairs' }],
		rooms: [{ id: 12, floorId: 1, name: 'Kitchen' }, { id: 13, floorId: 2, name: 'Bedroom' }],
	},
})

const lastUpdate = (wrapper) => wrapper.emitted('update:modelValue').at(-1)[0]

describe('TaskFilterBar (CS-PLAN-084 WP-D)', () => {
	const originalMatchMedia = window.matchMedia

	afterEach(() => {
		window.matchMedia = originalMatchMedia
	})

	it('starts with the panel open on wide screens and toggles it', async () => {
		window.matchMedia = () => ({ matches: false })
		const wrapper = mountBar()
		const toggle = wrapper.get('[data-testid="tasks-filter-toggle"]')
		expect(toggle.attributes('aria-expanded')).toBe('true')
		expect(wrapper.get('#task-filter-panel').isVisible()).toBe(true)

		await toggle.trigger('click')
		expect(toggle.attributes('aria-expanded')).toBe('false')
		expect(wrapper.get('#task-filter-panel').isVisible()).toBe(false)
	})

	it('starts collapsed on phones while the scope buttons stay visible', () => {
		window.matchMedia = (query) => ({ matches: query === '(max-width: 768px)' })
		const wrapper = mountBar()
		expect(wrapper.get('[data-testid="tasks-filter-toggle"]').attributes('aria-expanded')).toBe('false')
		expect(wrapper.get('#task-filter-panel').isVisible()).toBe(false)
		expect(wrapper.get('[data-testid="tasks-filter-mine"]').isVisible()).toBe(true)
	})

	it('counts active panel filters on the toggle, scope excluded', () => {
		const label = (wrapper) => wrapper.get('[data-testid="tasks-filter-toggle"]').text()
		expect(label(mountBar({ scope: 'all' }))).toMatch(/^Filters(?! \()/)
		expect(label(mountBar({ status: 'overdue', room: '12', search: ' mop ' }))).toContain('Filters (3 active)')
		expect(label(mountBar({ search: '   ' }))).not.toContain('active')
	})

	it('emits whole filter objects with the coupled resets', async () => {
		const wrapper = mountBar({ person: 'teen', floor: '1', room: '12' })

		await wrapper.get('[data-testid="tasks-filter-unassigned"]').trigger('click')
		expect(lastUpdate(wrapper)).toMatchObject({ scope: 'unassigned', person: '', room: '12' })

		await wrapper.get('[data-testid="person-filter"]').setValue('teen')
		expect(lastUpdate(wrapper)).toMatchObject({ scope: 'all', person: 'teen' })

		await wrapper.get('[data-testid="floor-filter"]').setValue('2')
		expect(lastUpdate(wrapper)).toMatchObject({ floor: '2', room: '' })

		await wrapper.get('[data-testid="search-input"]').setValue('mop')
		expect(lastUpdate(wrapper)).toMatchObject({ search: 'mop', floor: '1' })
	})

	it('offers only the rooms of the selected floor and labels every control', () => {
		const wrapper = mountBar({ floor: '2' })
		expect(wrapper.get('[data-testid="room-filter"]').text()).toContain('Bedroom')
		expect(wrapper.get('[data-testid="room-filter"]').text()).not.toContain('Kitchen')
		for (const select of wrapper.findAll('#task-filter-panel select, #task-filter-panel input')) {
			expect(wrapper.find(`label[for="${select.attributes('id')}"]`).exists()).toBe(true)
		}
	})
})
