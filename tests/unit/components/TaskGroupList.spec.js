/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest } from '@jest/globals'
import { mount } from '@vue/test-utils'
import TaskGroupList from '../../../src/components/Tasks/TaskGroupList.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, vars) => (vars
		? text.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? String(vars[key]) : match))
		: text)),
}))

jest.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', template: '<button type="button"><slot name="icon" /><slot /></button>' },
}))

jest.mock('../../../src/store/house.js', () => ({
	useHouseStore: () => ({ timezone: 'UTC' }),
}))

jest.mock('../../../src/components/Tasks/TaskList.vue', () => ({
	name: 'TaskList',
	props: ['roomTasks'],
	emits: ['complete'],
	template: '<div class="task-list-stub" />',
}))

const item = (id) => ({ roomTask: { id, roomId: 10, isActive: true }, task: { id, name: `Task ${id}` } })
const kitchen = { id: 10, floorId: 1, name: 'Kitchen', cleanliness: 85, lastCleaned: '2026-11-03T09:00:00Z', roomType: 'kitchen' }
const hall = { id: 11, floorId: 1, name: 'Hall', cleanliness: 100, lastCleaned: null }
const roomSections = [{
	key: 1,
	label: 'Ground',
	open: 2,
	groups: [
		{ key: 10, label: 'Kitchen', room: kitchen, items: [item(1), item(2)], open: 2, overdue: 1 },
		{ key: 11, label: 'Hall', room: hall, items: [], open: 0, overdue: 0 },
	],
}]
const personSections = [{
	key: 'people',
	label: '',
	groups: [{ key: 'user:mom', label: 'Parent', items: [item(1)], open: 1, overdue: 0 }],
}]

const mountList = (props = {}) => mount(TaskGroupList, {
	props: { sections: roomSections, canAdd: true, ...props },
	global: { stubs: { Icon: true, ExpandChevron: true } },
})
const group = (wrapper, key) => wrapper.get(`[data-testid="task-group-${key}"]`)

describe('TaskGroupList (CS-PLAN-090 WP-C)', () => {
	it('shows floors as headings and rooms with their open and overdue counts', () => {
		const wrapper = mountList()

		expect(wrapper.get('[data-testid="task-floor-1"]').text()).toContain('Ground')
		expect(wrapper.get('[data-testid="task-floor-1"]').text()).toContain('2 open')
		expect(group(wrapper, 10).get('[data-testid="task-group-name"]').text()).toBe('Kitchen')
		expect(group(wrapper, 10).get('[data-testid="task-group-open"]').text()).toBe('2 open')
		expect(group(wrapper, 10).get('[data-testid="task-group-overdue"]').text()).toBe('1 overdue')
		expect(group(wrapper, 11).find('[data-testid="task-group-overdue"]').exists()).toBe(false)
	})

	it('shows a room\'s cleanliness and "Last cleaned", the latter only once it was cleaned (D-090-19)', () => {
		const wrapper = mountList()

		expect(group(wrapper, 10).get('[data-testid="cleanliness-value"]').text()).toBe('85%')
		expect(group(wrapper, 10).get('[data-testid="room-last-cleaned"]').text()).toMatch(/^Last cleaned: .*3/)
		expect(group(wrapper, 11).get('[data-testid="cleanliness-value"]').text()).toBe('100%')
		expect(group(wrapper, 11).find('[data-testid="room-last-cleaned"]').exists()).toBe(false)
	})

	it('renders the rows of the open group only', () => {
		const closed = mountList()
		expect(closed.findComponent({ name: 'TaskList' }).exists()).toBe(false)
		expect(group(closed, 10).get('[data-testid="task-group-toggle"]').attributes('aria-expanded')).toBe('false')

		const open = mountList({ openKey: 10 })
		const lists = open.findAllComponents({ name: 'TaskList' })
		expect(lists).toHaveLength(1)
		expect(lists[0].props('roomTasks')).toEqual(roomSections[0].groups[0].items)
		expect(group(open, 10).get('[data-testid="task-group-toggle"]').attributes('aria-expanded')).toBe('true')
		expect(group(open, 11).get('[data-testid="task-group-toggle"]').attributes('aria-expanded')).toBe('false')
	})

	it('reports a toggle and passes a completion on', async () => {
		const wrapper = mountList({ openKey: 10 })

		await group(wrapper, 11).get('[data-testid="task-group-toggle"]').trigger('click')
		expect(wrapper.emitted('toggle')).toEqual([[11]])

		const list = wrapper.findComponent({ name: 'TaskList' })
		const claim = { participants: [{ userId: 'mom', sharePct: 100 }] }
		list.vm.$emit('complete', 1, claim)
		expect(wrapper.emitted('complete')).toEqual([[1, claim]])
		// A row edits and deletes in the one form itself (CS-PLAN-091 WP-B6).
		expect(wrapper.emitted('edit')).toBeUndefined()
		expect(wrapper.emitted('delete')).toBeUndefined()
	})

	it('offers *New* in a room heading to those who may add tasks, and names the room', async () => {
		const wrapper = mountList()
		const button = group(wrapper, 11).get('[data-testid="room-add-task-button"]')

		expect(button.attributes('aria-label')).toBe('New task in Hall')
		await button.trigger('click')
		expect(wrapper.emitted('add')).toEqual([[hall]])
		expect(wrapper.emitted('toggle')).toBeUndefined()

		expect(mountList({ canAdd: false }).find('[data-testid="room-add-task-button"]').exists()).toBe(false)
	})

	it('shows people without a floor heading, room facts or *New*', () => {
		const wrapper = mountList({ sections: personSections })

		expect(wrapper.find('.section-heading').exists()).toBe(false)
		expect(group(wrapper, 'user:mom').get('[data-testid="task-group-open"]').text()).toBe('1 open')
		expect(wrapper.find('[data-testid="cleanliness-value"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="room-add-task-button"]').exists()).toBe(false)
	})

	it('says so when there is nothing to group', () => {
		expect(mountList({ sections: [] }).text()).toContain('No tasks yet')
	})
})
