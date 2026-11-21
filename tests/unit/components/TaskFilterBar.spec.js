/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { afterEach, beforeEach, describe, expect, it, jest } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import TaskFilterBar from '../../../src/components/Tasks/TaskFilterBar.vue'
import { useRoomsStore } from '../../../src/store/rooms.js'

jest.mock('@/utils/logger', () => ({ logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() } }))

const defaults = () => ({ scope: 'mine', people: [], unassigned: true, floor: '', room: '', status: 'all', priority: 'all', difficulty: 'all', visibility: 'all', category: 'all', search: '' })
const members = [{ nextcloudUserId: 'mom', displayName: 'Mom' }, { nextcloudUserId: 'teen', displayName: 'Teen' }]

const lastUpdate = (wrapper) => wrapper.emitted('update:modelValue').at(-1)[0]

describe('TaskFilterBar (CS-PLAN-084 WP-D)', () => {
	const originalMatchMedia = window.matchMedia
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		const roomsStore = useRoomsStore()
		roomsStore.floors = [{ id: 1, name: 'Ground' }, { id: 2, name: 'Upstairs' }]
		roomsStore.rooms = [{ id: 12, floorId: 1, name: 'Kitchen' }, { id: 13, floorId: 2, name: 'Bedroom' }]
		jest.spyOn(roomsStore, 'fetchFloorsWithRooms').mockResolvedValue([])
	})

	afterEach(() => {
		window.matchMedia = originalMatchMedia
	})

	const mountBar = (filters = {}, props = {}) => mount(TaskFilterBar, {
		props: { modelValue: { ...defaults(), ...filters }, members, actorId: 'mom', ...props },
		global: { plugins: [pinia] },
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

	it('filters by energy in bolts and reaches a longer task (FEAT-UX-046)', () => {
		window.matchMedia = () => ({ matches: false })
		const labels = mountBar().get('[data-testid="difficulty-filter"]').findAll('option').map((option) => option.text())

		expect(labels).toEqual(['All energy levels', '⚡', '⚡⚡', '⚡⚡⚡', '⚡⚡⚡⚡', '⚡⚡⚡⚡⚡', 'Longer task (6 to 20)'])
	})

	it('filters by the household categories and counts a chosen one (CS-PLAN-090 WP-I)', async () => {
		window.matchMedia = () => ({ matches: false })
		const wrapper = mountBar({}, { categories: [{ value: 'kitchen', label: 'Kitchen' }, { value: 'c0a1b2c3d', label: 'Garden' }, { value: 'other', label: 'Other' }] })
		const select = wrapper.get('[data-testid="category-filter"]')
		expect(select.findAll('option').map((option) => option.text())).toEqual(['All categories', 'Kitchen', 'Garden', 'Other'])

		await select.setValue('c0a1b2c3d')
		expect(lastUpdate(wrapper)).toMatchObject({ category: 'c0a1b2c3d' })
		await wrapper.setProps({ modelValue: { ...defaults(), category: 'c0a1b2c3d' } })
		expect(wrapper.get('[data-testid="tasks-filter-toggle"]').text()).toContain('Filters (1 active)')
	})

	it('starts collapsed on phones while the scope buttons and the quick filters stay visible', () => {
		window.matchMedia = (query) => ({ matches: query === '(max-width: 768px)' })
		const wrapper = mountBar()
		expect(wrapper.get('[data-testid="tasks-filter-toggle"]').attributes('aria-expanded')).toBe('false')
		expect(wrapper.get('#task-filter-panel').isVisible()).toBe(false)
		expect(wrapper.get('[data-testid="tasks-filter-mine"]').isVisible()).toBe(true)
		expect(wrapper.get('[data-testid="people-filter-toggle"]').isVisible()).toBe(true)
		expect(wrapper.get('[data-testid="room-picker-toggle"]').isVisible()).toBe(true)
	})

	it('counts only the detail filters of the panel on the toggle: no scope, people, floor or room', () => {
		const label = (wrapper) => wrapper.get('[data-testid="tasks-filter-toggle"]').text()
		expect(label(mountBar({ scope: 'all' }))).toMatch(/^Filters(?! \()/)
		expect(label(mountBar({ scope: 'custom', people: ['teen'], unassigned: false, floor: '1', room: '12' }))).toMatch(/^Filters(?! \()/)
		expect(label(mountBar({ status: 'overdue', room: '12', search: ' mop ' }))).toContain('Filters (2 active)')
		expect(label(mountBar({ search: '   ' }))).not.toContain('active')
	})

	// The detail panel no longer repeats what the quick filters decide (GUI review of WP-B6).
	it('keeps person, floor and room out of the panel and labels every control in it', () => {
		window.matchMedia = () => ({ matches: false })
		const wrapper = mountBar({ floor: '2' })

		for (const testid of ['person-filter', 'floor-filter', 'room-filter']) {
			expect(wrapper.find(`[data-testid="${testid}"]`).exists()).toBe(false)
		}
		expect(wrapper.findAll('#task-filter-panel select').map((select) => select.attributes('data-testid')))
			.toEqual(['status-filter', 'priority-filter', 'difficulty-filter', 'visibility-filter', 'category-filter'])
		for (const control of wrapper.findAll('#task-filter-panel select, #task-filter-panel input')) {
			expect(wrapper.find(`label[for="${control.attributes('id')}"]`).exists()).toBe(true)
		}
	})

	describe('scope and people', () => {
		it('emits whole filter objects, and a scope button clears a custom choice of people', async () => {
			const wrapper = mountBar({ scope: 'custom', people: ['teen'], unassigned: false, floor: '1', room: '12' })
			for (const scope of ['mine', 'unassigned', 'all']) {
				expect(wrapper.get(`[data-testid="tasks-filter-${scope}"]`).attributes('aria-pressed')).toBe('false')
			}

			await wrapper.get('[data-testid="tasks-filter-unassigned"]').trigger('click')
			expect(lastUpdate(wrapper)).toMatchObject({ scope: 'unassigned', people: [], unassigned: true, floor: '1', room: '12' })

			await wrapper.get('[data-testid="search-input"]').setValue('mop')
			expect(lastUpdate(wrapper)).toMatchObject({ search: 'mop', scope: 'custom', people: ['teen'], floor: '1' })
		})

		it('says what My tasks stands for, and only then', () => {
			expect(mountBar().text()).toContain('Your tasks and unassigned tasks')
			expect(mountBar({ scope: 'all' }).text()).not.toContain('Your tasks and unassigned tasks')
		})

		it('hands the people filter the scope, the members and the viewer, and takes its choice as it comes', async () => {
			const wrapper = mountBar({ scope: 'custom', people: ['teen'], unassigned: false })
			const people = wrapper.getComponent({ name: 'TaskPeopleFilter' })
			expect(people.props()).toMatchObject({ scope: 'custom', people: ['teen'], unassigned: false, members, actorId: 'mom', disabled: false })

			await people.vm.$emit('change', { scope: 'all', people: [], unassigned: true })
			expect(lastUpdate(wrapper)).toMatchObject({ scope: 'all', people: [], unassigned: true, status: 'all' })
		})

		it('ticks through to a custom choice of people', async () => {
			const wrapper = mountBar({ scope: 'all' })

			await wrapper.get('[data-testid="people-filter-toggle"]').trigger('click')
			await wrapper.get('[data-testid="people-filter-mom"]').setValue(false)

			expect(lastUpdate(wrapper)).toMatchObject({ scope: 'custom', people: ['teen'], unassigned: true })
		})

		it('turns the people filter off while the member names are unavailable', () => {
			expect(mountBar({}, { membersDisabled: true }).get('[data-testid="people-filter-toggle"]').element.disabled).toBe(true)
		})
	})

	describe('the room quick filter', () => {
		it('shows the chosen floor or room as a filter of the picker', () => {
			const picker = (filters) => mountBar(filters).getComponent({ name: 'RoomPicker' })

			expect(picker({}).props()).toMatchObject({ filter: true, modelValue: null, floorId: null })
			expect(picker({ floor: '2' }).props()).toMatchObject({ modelValue: null, floorId: 2 })
			expect(picker({ floor: '1', room: '12' }).props()).toMatchObject({ modelValue: 12, floorId: 1 })
			expect(mountBar({ floor: '1', room: '12' }).get('.picker-label').text()).toBe('Ground · Kitchen')
		})

		it('takes floor and room of a choice in one update, as the strings the filters hold', async () => {
			const wrapper = mountBar({ scope: 'all', search: 'mop' })
			const picker = wrapper.getComponent({ name: 'RoomPicker' })

			await picker.vm.$emit('select', { floorId: 2, roomId: 13 })
			expect(wrapper.emitted('update:modelValue')).toHaveLength(1)
			expect(lastUpdate(wrapper)).toMatchObject({ floor: '2', room: '13', scope: 'all', search: 'mop' })

			await picker.vm.$emit('select', { floorId: 1, roomId: null })
			expect(lastUpdate(wrapper)).toMatchObject({ floor: '1', room: '' })

			await picker.vm.$emit('select', { floorId: null, roomId: null })
			expect(wrapper.emitted('update:modelValue')).toHaveLength(3)
			expect(lastUpdate(wrapper)).toMatchObject({ floor: '', room: '' })
		})

		it('filters by a floor with one press and by one of its rooms with the next', async () => {
			const wrapper = mountBar({ scope: 'all' })

			await wrapper.get('[data-testid="room-picker-toggle"]').trigger('click')
			await wrapper.get('[data-testid="room-picker-floor-2"]').trigger('click')
			expect(wrapper.emitted('update:modelValue')).toHaveLength(1)
			expect(lastUpdate(wrapper)).toMatchObject({ floor: '2', room: '' })

			await wrapper.setProps({ modelValue: { ...defaults(), scope: 'all', floor: '2' } })
			await wrapper.get('[data-testid="room-picker-room-13"]').trigger('click')
			expect(wrapper.emitted('update:modelValue')).toHaveLength(2)
			expect(lastUpdate(wrapper)).toMatchObject({ floor: '2', room: '13' })
		})
	})
})
