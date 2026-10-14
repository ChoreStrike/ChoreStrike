/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import TaskListItem from '../../../src/components/Tasks/TaskListItem.vue'
import TaskListDetails from '../../../src/components/Tasks/TaskListDetails.vue'
import { useUserStore } from '../../../src/store/user.js'
import { useRoomTasksStore } from '../../../src/store/roomTasks.js'
import { confirmHold } from '../helpers/hold.js'

jest.mock('@nextcloud/dialogs', () => ({
	showSuccess: jest.fn(),
	showWarning: jest.fn(),
	showError: jest.fn(),
}))

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

const roomTask = (over = {}) => ({
	id: 42,
	roomId: 10,
	taskId: 7,
	isActive: true,
	nextDue: '2999-01-01T00:00:00Z',
	assignees: [],
	...over,
})

const task = (over = {}) => ({
	id: 7,
	name: 'Dishes',
	description: '',
	energyCost: 3,
	estimatedMinutes: 15,
	priority: 2,
	frequencyType: 'weekly',
	frequencyValue: 1,
	flexDays: 0,
	visibilityType: 'public',
	...over,
})

const mountItem = (propsOver = {}, storeRole = null) => {
	const userStore = useUserStore()
	if (storeRole !== null) {
		// role drives the canManageTasks/isHouseOwner getters — no direct writes.
		userStore.currentUser = { id: 1, role: storeRole, nextcloudUserId: 'u1' }
	}
	return mount(TaskListItem, {
		props: {
			item: { roomTask: roomTask(), task: task() },
			...propsOver,
		},
	})
}

describe('TaskListItem (CS-PLAN-067 §3.4)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('renders the stable row testid, name and priority badge', () => {
		const wrapper = mountItem({}, 'owner')
		expect(wrapper.find('[data-testid="task-item"]').exists()).toBe(true)
		expect(wrapper.find('.task-name').text()).toBe('Dishes')
		expect(wrapper.find('.priority-badge').text()).toBe('Normal')
	})

	it('shows the visibility lock/crown testids only for the matching types', () => {
		const privateWrapper = mountItem({ item: { roomTask: roomTask(), task: task({ visibilityType: 'private' }) } }, 'owner')
		expect(privateWrapper.find('[data-testid="visibility-lock-icon"]').exists()).toBe(true)

		const adminWrapper = mountItem({ item: { roomTask: roomTask(), task: task({ visibilityType: 'admin-only' }) } }, 'owner')
		expect(adminWrapper.find('[data-testid="visibility-crown-icon"]').exists()).toBe(true)
	})

	it('shows all assigned names without expanding, and labels unassigned work', () => {
		useUserStore().houseUsers = [{ nextcloudUserId: 'u1', displayName: 'Alice' }, { nextcloudUserId: 'u2', displayName: 'Bob' }]
		const wrapper = mountItem({ item: { roomTask: roomTask({ assignees: ['u1', 'u2'] }), task: task() } }, 'member')
		expect(wrapper.get('[data-testid="task-assignees"]').text()).toContain('Assigned to: Alice, Bob')
		expect(wrapper.get('[data-testid="complete-task-button"]').text()).toContain('Done')
		// The visible label is the accessible name; no competing aria-label.
		expect(wrapper.get('[data-testid="complete-task-button"]').attributes('aria-label')).toBeUndefined()
		expect(mountItem().get('[data-testid="task-assignees"]').text()).toContain('Unassigned')
	})

	it('emits complete/edit/delete upward', async () => {
		const wrapper = mountItem({}, 'owner')

		await confirmHold(wrapper, '[data-testid="complete-task-button"]')
		await wrapper.find('.action-btn').trigger('click')
		await wrapper.find('.action-btn.delete').trigger('click')

		expect(wrapper.emitted('complete')).toEqual([[42]])
		expect(wrapper.emitted('edit')).toBeTruthy()
		expect(wrapper.emitted('delete')).toEqual([[42]])
	})

	it('a plain click on Done does not complete (WP-E hold-to-confirm)', async () => {
		const wrapper = mountItem({}, 'owner')

		await wrapper.find('[data-testid="complete-task-button"]').trigger('click')

		expect(wrapper.emitted('complete')).toBeUndefined()
	})

	it('disables Done while the shared store has its completion in flight', async () => {
		const wrapper = mountItem({}, 'owner')
		useRoomTasksStore().completingIds = { 42: true }
		await wrapper.vm.$nextTick()

		expect(wrapper.get('[data-testid="complete-task-button"]').attributes('disabled')).toBeDefined()
	})

	it('emits toggle-select and toggle-expand', async () => {
		const wrapper = mountItem({}, 'owner')

		await wrapper.find('[data-testid="select-task-42"]').setValue(true)
		await wrapper.findAll('.action-btn')[1].trigger('click')

		expect(wrapper.emitted('toggle-select')).toEqual([[42]])
		expect(wrapper.emitted('toggle-expand')).toBeTruthy()
	})

	it('hides the selection checkbox, edit and delete for children', () => {
		const wrapper = mountItem({}, 'child')
		expect(wrapper.find('[data-testid="select-task-42"]').exists()).toBe(false)
		const titles = wrapper.findAll('.task-actions .action-btn').map(btn => btn.attributes('title'))
		expect(titles).not.toContain('Edit')
		expect(titles).not.toContain('Remove')
	})

	it('marks overdue rows and offers the claim button for eligible members', () => {
		const overdueItem = {
			roomTask: roomTask({ nextDue: '2000-01-01T00:00:00Z', assignees: ['someone-else'], occurrence: { classification: 'late' } }),
			task: task(),
		}
		const wrapper = mountItem({ item: overdueItem }, 'owner')
		expect(wrapper.find('.task-item.overdue').exists()).toBe(true)
		expect(wrapper.find('[data-testid="claim-overdue-button"]').exists()).toBe(true)
	})

	it('compact rows hide the description until the row is expanded (CS-PLAN-084 WP-D)', () => {
		const item = { roomTask: roomTask(), task: task({ description: 'Wipe the shelves too' }) }
		expect(mountItem({ item }).find('.task-description').exists()).toBe(true)
		expect(mountItem({ item, compact: true }).find('.task-item.compact').exists()).toBe(true)
		expect(mountItem({ item, compact: true }).find('.task-description').exists()).toBe(false)
		expect(mountItem({ item, compact: true, expanded: true }).get('.task-description').text()).toBe('Wipe the shelves too')
	})

	it('renders the expanded details through TaskListDetails', () => {
		const members = [{ nextcloudUserId: 'u1', displayName: 'Alice' }, { nextcloudUserId: 'u2', displayName: 'Bob' }]
		useUserStore().houseUsers = members
		const wrapper = mountItem({ expanded: true, assigneeDraft: ['u1'] }, 'owner')
		expect(wrapper.findComponent(TaskListDetails).exists()).toBe(true)
		expect(wrapper.findComponent(TaskListDetails).props('members')).toEqual(members)
		expect(wrapper.find('[data-testid="assignee-chip-u2"]').text()).toBe('Bob')
		expect(wrapper.find('.assignee-picker').exists()).toBe(true)
	})

	it('forwards the assignee toggle from the details chips', async () => {
		const wrapper = mountItem({ expanded: true, assigneeDraft: ['u1'] }, 'owner')
		const details = wrapper.findComponent(TaskListDetails)

		details.vm.$emit('toggle-assignee', 42, 'u2')
		await flushPromises()

		expect(wrapper.emitted('toggle-assignee')).toEqual([[42, 'u2']])
	})
})
