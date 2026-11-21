/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { beforeEach, describe, expect, it, jest } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import TaskList from '../../../src/components/Tasks/TaskList.vue'
import TaskRow from '../../../src/components/Tasks/TaskRow.vue'
import { confirmHold } from '../helpers/hold.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, vars) => (vars
		? text.replace(/{\w+}/g, (match) => vars[match.slice(1, -1)] ?? match)
		: text)),
	translatePlural: jest.fn((app, singular, plural, count) => (count === 1 ? singular : plural)),
}))

jest.mock('../../../src/store/checklist.js', () => ({
	useChecklistStore: jest.fn(() => ({
		fetchTaskItems: jest.fn(),
		fetchRoomTaskProgress: jest.fn(),
		toggleItem: jest.fn(),
		getTaskItems: jest.fn(() => []),
		getRoomTaskProgress: jest.fn(() => []),
	})),
}))

const day = 86400000

/**
 * The task list (CS-PLAN-067 §3.4, CS-PLAN-091 WP-B6): sorted rows of the
 * one task row, with a heading where the section of a row changes. The
 * rows themselves are covered by TaskRow.spec.js.
 */
describe('TaskList', () => {
	const mockTask = (overrides = {}) => ({
		task: {
			id: 1,
			name: 'Test Task',
			energyCost: 2,
			priority: 2,
			frequencyType: 'weekly',
			frequencyValue: 1,
			...overrides.task,
		},
		roomTask: {
			id: 1,
			roomId: 1,
			taskId: 1,
			isActive: true,
			nextDue: new Date(Date.now() + day).toISOString(),
			assignees: [],
			...overrides.roomTask,
		},
	})
	const mountList = (props = {}) => mount(TaskList, { props: { loading: false, roomTasks: [mockTask()], ...props } })

	beforeEach(() => {
		setActivePinia(createPinia())
	})

	describe('rendering states', () => {
		it('shows the skeleton while loading', () => {
			const wrapper = mountList({ loading: true, roomTasks: [] })

			expect(wrapper.find('.skeleton-loading').exists()).toBe(true)
			expect(wrapper.findAll('.skeleton-task-item')).toHaveLength(5)
			expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(false)
		})

		it('shows the empty state without rows, also for a null list', () => {
			const wrapper = mountList({ roomTasks: [] })

			expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(true)
			expect(wrapper.find('.empty-icon').exists()).toBe(true)
			expect(wrapper.text()).toContain('No tasks yet')
			expect(wrapper.text()).toContain('Add your first task to get started')
			expect(mountList({ roomTasks: null }).find('[data-testid="empty-state"]').exists()).toBe(true)
		})

		it('renders every row as the one task row in its list form', () => {
			const wrapper = mountList({ roomTasks: [mockTask(), mockTask({ task: { id: 2 }, roomTask: { id: 2 } })] })

			expect(wrapper.find('[data-testid="room-task-list"]').exists()).toBe(true)
			expect(wrapper.findAllComponents(TaskRow).map((row) => row.props('variant'))).toEqual(['list', 'list'])
			expect(wrapper.findAll('[data-testid="task-item"]')).toHaveLength(2)
		})

		it('keeps no selection, bulk bar or claim of its own (D-091-87)', () => {
			const wrapper = mountList()

			expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(0)
			expect(wrapper.find('[data-testid="bulk-assign-bar"]').exists()).toBe(false)
			expect(wrapper.find('[data-testid="claim-overdue-button"]').exists()).toBe(false)
		})

		it('declares its props', () => {
			expect(TaskList.props.roomTasks.type).toBe(Array)
			expect(TaskList.props.roomTasks.default()).toEqual([])
			expect(TaskList.props.loading.type).toBe(Boolean)
			expect(TaskList.props.loading.default).toBe(false)
		})
	})

	describe('task status', () => {
		it('offers Done for an active row', () => {
			const wrapper = mountList()

			expect(wrapper.find('[data-testid="complete-task-button"]').exists()).toBe(true)
			expect(wrapper.find('.completed-check').exists()).toBe(false)
		})

		it('shows a finished row as completed with its check mark', () => {
			const wrapper = mountList({ roomTasks: [mockTask({ roomTask: { isActive: false } })] })

			expect(wrapper.find('[data-testid="complete-task-button"]').exists()).toBe(false)
			expect(wrapper.find('.completed-check').exists()).toBe(true)
			expect(wrapper.get('[data-testid="task-item"]').classes()).toContain('completed')
			expect(wrapper.get('.task-due').text()).toBe('Completed')
		})

		it('marks a row whose cycle is overdue', () => {
			const wrapper = mountList({
				roomTasks: [mockTask({ roomTask: { nextDue: new Date(Date.now() - 3 * day).toISOString(), cycle: { state: 'overdue', windowEndOn: '2000-01-01' } } })],
			})

			expect(wrapper.get('[data-testid="task-item"]').classes()).toContain('overdue')
			expect(wrapper.get('.task-due').classes()).toContain('overdue')
		})

		it('does not mark work still due (open window) overdue from its past timestamp alone (D-084-08)', () => {
			const wrapper = mountList({
				roomTasks: [mockTask({ roomTask: { nextDue: new Date(Date.now() - day).toISOString(), cycle: { state: 'open' } } })],
			})

			expect(wrapper.get('[data-testid="task-item"]').classes()).not.toContain('overdue')
		})
	})

	describe('completion', () => {
		it('passes a confirmed hold on with the room task id', async () => {
			const wrapper = mountList({ roomTasks: [mockTask({ roomTask: { id: 42 } })] })

			await confirmHold(wrapper, '[data-testid="complete-task-button"]')

			expect(wrapper.emitted('complete')).toHaveLength(1)
			expect(wrapper.emitted('complete')[0][0]).toBe(42)
		})

		it('passes the split a row picked on with it', async () => {
			const item = mockTask({ roomTask: { id: 42 } })
			const wrapper = mountList({ roomTasks: [item] })
			const split = { participants: [{ userId: 'mom', sharePct: 100 }], allocationReviewed: true }

			wrapper.getComponent(TaskRow).vm.$emit('complete', item, split)

			expect(wrapper.emitted('complete')).toEqual([[42, split]])
		})
	})

	describe('order', () => {
		it('sorts by due date, then by priority', () => {
			const now = new Date().toISOString()
			const wrapper = mountList({
				roomTasks: [
					mockTask({ task: { id: 1, name: 'Tomorrow' }, roomTask: { id: 1, nextDue: new Date(Date.now() + day).toISOString() } }),
					mockTask({ task: { id: 2, name: 'Low', priority: 1 }, roomTask: { id: 2, nextDue: now } }),
					mockTask({ task: { id: 3, name: 'High', priority: 3 }, roomTask: { id: 3, nextDue: now } }),
				],
			})

			expect(wrapper.findAll('.task-name').map((name) => name.text())).toEqual(['High', 'Low', 'Tomorrow'])
		})
	})

	describe('sections and order from the view (CS-PLAN-090 WP-C)', () => {
		const rows = () => [
			mockTask({ task: { id: 1, name: 'Later' }, roomTask: { id: 1, nextDue: new Date(Date.now() + day).toISOString() } }),
			mockTask({ task: { id: 2, name: 'Sooner' }, roomTask: { id: 2, nextDue: new Date(Date.now() - day).toISOString() } }),
			mockTask({ task: { id: 3, name: 'Latest' }, roomTask: { id: 3, nextDue: new Date(Date.now() + 3 * day).toISOString() } }),
		]
		const names = (wrapper) => wrapper.findAll('.task-name').map((name) => name.text())
		const headings = (wrapper) => wrapper.findAll('[data-testid="task-section-heading"]').map((heading) => heading.text())

		it('shows no heading and keeps the due order by default', () => {
			const wrapper = mountList({ roomTasks: rows() })

			expect(names(wrapper)).toEqual(['Sooner', 'Later', 'Latest'])
			expect(headings(wrapper)).toEqual([])
		})

		it('uses the order it is given', () => {
			const wrapper = mountList({ roomTasks: rows(), compare: (a, b) => b.roomTask.id - a.roomTask.id })

			expect(names(wrapper)).toEqual(['Latest', 'Sooner', 'Later'])
		})

		it('shows a heading where the section of a row differs from the row before', () => {
			const wrapper = mountList({ roomTasks: rows(), sectionLabel: (item) => (item.roomTask.id === 2 ? 'Overdue · 1' : 'Later · 2') })

			expect(headings(wrapper)).toEqual(['Overdue · 1', 'Later · 2'])
			expect(wrapper.find('.task-items').element.firstElementChild.textContent.trim()).toBe('Overdue · 1')
		})
	})
})
