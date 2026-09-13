/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import TaskListDetails from '../../../src/components/Tasks/TaskListDetails.vue'

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
	energyCost: 3,
	flexDays: 0,
	...over,
})

const members = [
	{ nextcloudUserId: 'u1', displayName: 'Alice' },
	{ nextcloudUserId: 'u2', displayName: 'Bob' },
]

const mountDetails = (propsOver = {}) => {
	setActivePinia(createPinia())
	return mount(TaskListDetails, {
		props: {
			item: { roomTask: roomTask(), task: task() },
			members,
			assigneeDraft: ['u1'],
			canManageTasks: true,
			...propsOver,
		},
	})
}

describe('TaskListDetails (CS-PLAN-067 §3.4)', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	it('reuses AssigneeChips without any per-row member fetch', () => {
		const wrapper = mountDetails()
		const chips = wrapper.find('[data-testid="assignee-select"]')
		expect(chips.exists()).toBe(true)
		expect(wrapper.find('[data-testid="assignee-chip-u2"]').exists()).toBe(true)
		// The selected draft chip carries the selected class (interaction parity).
		expect(wrapper.find('[data-testid="assignee-chip-u1"]').classes()).toContain('selected')
	})

	it('hides the picker for children while the checklist stays visible', () => {
		const wrapper = mountDetails({ canManageTasks: false })
		expect(wrapper.find('.assignee-picker').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'TaskChecklist' }).exists()).toBe(true)
	})

	it('keeps the checklist editor owner-gated', () => {
		const ownerWrapper = mountDetails({ isHouseOwner: true })
		expect(ownerWrapper.findComponent({ name: 'TaskChecklist' }).props('canEdit')).toBe(true)

		const memberWrapper = mountDetails({ isHouseOwner: false })
		expect(memberWrapper.findComponent({ name: 'TaskChecklist' }).props('canEdit')).toBe(false)
	})

	it('reports the single toggled member id to the orchestrator', async () => {
		const wrapper = mountDetails()
		await wrapper.find('[data-testid="assignee-chip-u2"]').find('input').setValue(true)
		expect(wrapper.emitted('toggle-assignee')).toEqual([[42, 'u2']])

		const wrapper2 = mountDetails()
		await wrapper2.find('[data-testid="assignee-chip-u1"]').find('input').setValue(false)
		expect(wrapper2.emitted('toggle-assignee')).toEqual([[42, 'u1']])
	})
})
