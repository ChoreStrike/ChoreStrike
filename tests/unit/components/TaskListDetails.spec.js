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

// The history reads through its own store; this suite only places it.
jest.mock('@/components/Tasks/TaskHistory.vue', () => ({
	name: 'TaskHistory',
	props: ['roomTaskId'],
	template: '<div data-testid="task-history-stub" />',
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

// CS-PLAN-070 WP8 §3.6: the essential agreements/shares help sits on the
// expanded task details.
describe('TaskListDetails contextual help (WP8 §3.6)', () => {
	it('offers the contextual agreements help on the task details', () => {
		const wrapper = mountDetails()
		expect(wrapper.find('[data-testid="help-context-agreements"]').exists()).toBe(true)
	})
})

describe('TaskListDetails cycle actions (CS-PLAN-091 WP-B3)', () => {
	const cycle = { cycleKey: 'c88', expectedVersion: 4, state: 'open', postponeCount: 1, canPostpone: true }

	it('offers postponing and the history to every member, children included', () => {
		const wrapper = mountDetails({
			item: { roomTask: roomTask({ cycle }), task: task({ flexDays: 2 }) },
			canManageTasks: false,
		})

		expect(wrapper.findComponent({ name: 'PostponeButton' }).props()).toEqual({ roomTaskId: 42, cycle, flexDays: 2 })
		expect(wrapper.findComponent({ name: 'TaskHistory' }).props('roomTaskId')).toBe(42)
	})

	it('keeps the history of a finished task but offers no postponing', () => {
		const wrapper = mountDetails({ item: { roomTask: roomTask({ isActive: false, cycle: null }), task: task() } })

		expect(wrapper.findComponent({ name: 'PostponeButton' }).exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'TaskHistory' }).exists()).toBe(true)
	})
})

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

	it('shows the reason of a refused assignee change at the chips', () => {
		expect(mountDetails().find('[data-testid="assignee-refusal"]').exists()).toBe(false)

		const wrapper = mountDetails({ assigneeRefusal: 'Assigning this member would exceed a daily task limit' })
		expect(wrapper.get('[data-testid="assignee-refusal"]').text()).toContain('daily task limit')
		expect(wrapper.get('[data-testid="assignee-refusal"]').attributes('role')).toBe('alert')
	})

	it('reports the single toggled member id to the orchestrator', async () => {
		const wrapper = mountDetails()
		await wrapper.find('[data-testid="assignee-chip-u2"]').find('input').setValue(true)
		expect(wrapper.emitted('toggleAssignee')).toEqual([[42, 'u2']])

		const wrapper2 = mountDetails()
		await wrapper2.find('[data-testid="assignee-chip-u1"]').find('input').setValue(false)
		expect(wrapper2.emitted('toggleAssignee')).toEqual([[42, 'u1']])
	})
})

// --- Occurrence agreements (CS-PLAN-070 WP6 §3.5) ---

// ADR-021 decision 13: the agreement commands and the date dialog went with
// the occurrence timeline; the expanded row offers neither.
describe('TaskListDetails retired entries (CS-PLAN-091 WP-B1)', () => {
	it('offers no agreement form and no date dialog, also to an owner on an overdue cycle', () => {
		const wrapper = mountDetails({
			isHouseOwner: true,
			item: {
				roomTask: roomTask({ cycle: { cycleKey: 'd2026-11-10', expectedVersion: 2, state: 'overdue', centreOn: '2026-11-10', windowEndOn: '2026-11-10' } }),
				task: task(),
			},
		})

		expect(wrapper.find('[data-testid="occurrence-agreement"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="task-date-button"]').exists()).toBe(false)
		expect(wrapper.find('input[type="date"]').exists()).toBe(false)
	})
})
