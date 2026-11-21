/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { beforeEach, describe, expect, it, jest } from '@jest/globals'
import { flushPromises, mount } from '@vue/test-utils'
import { confirmHold, tapHold } from '../helpers/hold.js'
import TodayBonusRow from '../../../src/components/Today/TodayBonusRow.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => Object.entries(vars).reduce((out, [key, value]) => out.replace(`{${key}}`, String(value)), text),
}))
jest.mock('@/utils/logger', () => ({
	logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() },
}))
jest.mock('@/components/Tasks/CompletionPicker.vue', () => ({
	name: 'CompletionPicker',
	props: { roomTaskId: Number, taskId: Number, assignees: Array, unavailable: Array, unavailableNote: String, withoutSubTasks: Boolean },
	emits: ['close', 'confirm'],
	template: '<div data-testid="picker-stub" />',
}))

let mockRoomTasksStore
let mockUserStore
jest.mock('../../../src/store/roomTasks.js', () => ({ useRoomTasksStore: () => mockRoomTasksStore }))
jest.mock('../../../src/store/user.js', () => ({ useUserStore: () => mockUserStore }))
jest.mock('../../../src/store/rooms.js', () => ({ useRoomsStore: () => ({ getFloorById: () => undefined }) }))

const row = (over = {}) => ({
	roomTask: { id: 40, taskId: 9, roomId: 1, isActive: true },
	task: { id: 9, name: 'Empty the dishwasher', energyCost: 1 },
	room: { id: 1, name: 'Kitchen' },
	assignees: [],
	creditedToday: [],
	cycle: { state: 'bonus', expectedVersion: 2 },
	...over,
})
const mountRow = (over = {}) => mount(TodayBonusRow, { props: { item: row(over) } })
const picker = (wrapper) => wrapper.findComponent({ name: 'CompletionPicker' })

/**
 * One bonus task on Today (CS-PLAN-091 WP-B4, ADR-021 decision 8): it pays
 * each person once per day, so the row offers it only to those it has not
 * counted for yet.
 */
describe('TodayBonusRow', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		mockRoomTasksStore = { isCompleting: jest.fn(() => false), wasJustCompleted: jest.fn(() => false) }
		mockUserStore = {
			currentUser: { nextcloudUserId: 'mom' },
			houseUsers: [
				{ nextcloudUserId: 'mom', displayName: 'Mom' },
				{ nextcloudUserId: 'kid', displayName: 'Kid' },
			],
		}
	})

	it('completes on a hold and names what the task is', async () => {
		const wrapper = mountRow()

		expect(wrapper.text()).toContain('Empty the dishwasher')
		expect(wrapper.get('[data-testid="task-recurrence"]').text()).toContain('Bonus task')
		expect(wrapper.find('[data-testid="today-bonus-counted-for-40"]').exists()).toBe(false)
		await confirmHold(wrapper, '[data-testid="today-bonus-complete-40"]')

		expect(wrapper.emitted('complete')).toEqual([[row()]])
	})

	it('opens the picker on a short tap, without sub-tasks, and hands its split on', async () => {
		const wrapper = mountRow({ assignees: ['kid'] })

		await tapHold(wrapper, '[data-testid="today-bonus-complete-40"]')

		expect(picker(wrapper).props()).toMatchObject({ roomTaskId: 40, taskId: 9, assignees: ['kid'], unavailable: [], withoutSubTasks: true })
		const split = { participants: [{ userId: 'kid', sharePct: 100 }], allocationReviewed: true }
		picker(wrapper).vm.$emit('confirm', split)
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('complete')[0][1]).toEqual(split)
		expect(picker(wrapper).exists()).toBe(false)
	})

	it('shows the check and whom it counted for once the viewer is counted today', async () => {
		const wrapper = mountRow({ creditedToday: ['mom'] })

		expect(wrapper.find('[data-testid="today-bonus-complete-40"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="today-bonus-counted-40"]').exists()).toBe(true)
		expect(wrapper.get('[data-testid="today-bonus-counted-for-40"]').text()).toBe('Counted today for Mom')

		// Someone else can still be credited: the picker marks who cannot.
		await wrapper.get('[data-testid="today-bonus-other-40"]').trigger('click')
		expect(picker(wrapper).props()).toMatchObject({ assignees: [], unavailable: ['mom'], unavailableNote: 'counted today' })
	})

	it('holds for the assignees: one of them counted already blocks the hold, the other stays pickable', async () => {
		const wrapper = mountRow({ assignees: ['kid', 'mom'], creditedToday: ['kid'] })

		expect(wrapper.find('[data-testid="today-bonus-complete-40"]').exists()).toBe(false)
		await wrapper.get('[data-testid="today-bonus-other-40"]').trigger('click')

		expect(picker(wrapper).props('assignees')).toEqual(['mom'])
	})

	it('offers nobody else once it counted for the whole household', () => {
		const wrapper = mountRow({ creditedToday: ['kid', 'mom'] })

		expect(wrapper.find('[data-testid="today-bonus-other-40"]').exists()).toBe(false)
		expect(wrapper.get('[data-testid="today-bonus-counted-for-40"]').text()).toBe('Counted today for Kid, Mom')
	})

	it('still holds for an assigned task while the viewer is unknown, and marks the button busy in flight', () => {
		mockUserStore.currentUser = null
		mockRoomTasksStore.isCompleting.mockReturnValue(true)
		const wrapper = mountRow({ assignees: ['kid'] })

		expect(wrapper.get('[data-testid="today-bonus-complete-40"]').attributes('aria-busy')).toBe('true')
	})

	it('shows no check for an unassigned task while the viewer is unknown: it counted for nobody yet', () => {
		mockUserStore.currentUser = null
		const wrapper = mountRow()

		expect(wrapper.find('[data-testid="today-bonus-complete-40"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="today-bonus-counted-40"]').exists()).toBe(false)
	})

	// RF-091-09: the check is named, the other button says what it is for,
	// and focus stays on the row when the hold turns into the check.
	describe('accessibility (RF-091-09)', () => {
		it('names the check and the button for someone else', () => {
			const wrapper = mountRow({ creditedToday: ['mom'] })
			const check = wrapper.get('[data-testid="today-bonus-counted-40"]')

			expect(check.attributes('role')).toBe('img')
			expect(check.attributes('aria-label')).toBe('Counted today for Mom')
			expect(wrapper.get('[data-testid="today-bonus-other-40"]').attributes('aria-label')).toBe('Someone else did “Empty the dishwasher”')
		})

		it('moves focus to the check when the hold turns into it, and takes it from nowhere else', async () => {
			const outside = document.createElement('button')
			document.body.appendChild(outside)
			const wrapper = mount(TodayBonusRow, { props: { item: row() }, attachTo: document.body })

			await wrapper.setProps({ item: row({ creditedToday: ['mom'] }) })
			await flushPromises()
			expect(document.activeElement?.dataset.testid).toBe('today-bonus-counted-40')

			await wrapper.setProps({ item: row() })
			outside.focus()
			await wrapper.setProps({ item: row({ creditedToday: ['mom'] }) })
			await flushPromises()
			expect(document.activeElement).toBe(outside)

			wrapper.unmount()
			outside.remove()
		})
	})
})
