/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { flushPromises, mount } from '@vue/test-utils'
import { showError, showSuccess } from '@nextcloud/dialogs'
import TaskCreateDialog from '../../../src/components/Tasks/TaskCreateDialog.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: jest.fn((app, text) => text) }))

jest.mock('@nextcloud/dialogs', () => ({
	showSuccess: jest.fn(),
	showError: jest.fn(),
}))

jest.mock('@/utils/logger', () => ({
	logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() },
}))

const mockRoomTasksStore = { createTaskAggregate: jest.fn() }
jest.mock('../../../src/store/roomTasks.js', () => ({
	useRoomTasksStore: () => mockRoomTasksStore,
}))

jest.mock('../../../src/components/Common/Modal.vue', () => ({
	name: 'Modal',
	emits: ['close'],
	template: '<div class="modal-stub"><slot /></div>',
}))

jest.mock('../../../src/components/Tasks/TaskEditForm.vue', () => ({
	name: 'TaskEditForm',
	props: ['loading', 'presetRoomId', 'initialRoomId', 'initialFloorId', 'initialAssigneeIds'],
	emits: ['submit', 'cancel'],
	template: '<div class="form-stub" />',
}))

jest.mock('../../../src/components/Tasks/TaskTemplatePicker.vue', () => ({
	name: 'TaskTemplatePicker',
	props: ['roomId', 'roomType'],
	emits: ['custom', 'created', 'error'],
	template: '<div class="picker-stub" />',
}))

const kitchen = { id: 12, name: 'Kitchen', roomType: 'kitchen' }
const form = (wrapper) => wrapper.findComponent({ name: 'TaskEditForm' })
const picker = (wrapper) => wrapper.findComponent({ name: 'TaskTemplatePicker' })

describe('TaskCreateDialog (CS-PLAN-090 WP-C)', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	it('is the form without a room, prefilled and not locked to a room', () => {
		const wrapper = mount(TaskCreateDialog, { props: { prefill: { roomId: 12, floorId: 1, assigneeIds: ['teen'] } } })

		expect(picker(wrapper).exists()).toBe(false)
		expect(form(wrapper).props()).toMatchObject({ initialRoomId: 12, initialFloorId: 1, initialAssigneeIds: ['teen'] })
		expect(form(wrapper).props('presetRoomId')).toBeUndefined()
	})

	it('starts with the room\'s templates and keeps the custom form one step behind them (D-090-12)', async () => {
		const wrapper = mount(TaskCreateDialog, { props: { room: kitchen } })

		expect(picker(wrapper).props()).toMatchObject({ roomId: 12, roomType: 'kitchen' })
		expect(form(wrapper).exists()).toBe(false)

		await picker(wrapper).vm.$emit('custom')
		expect(picker(wrapper).exists()).toBe(false)
		expect(form(wrapper).props('presetRoomId')).toBe(12)
	})

	it('reads a room without a type as a generic one', () => {
		const wrapper = mount(TaskCreateDialog, { props: { room: { id: 5, name: 'Den' } } })

		expect(picker(wrapper).props('roomType')).toBe('generic')
	})

	it('closes once a template created the task, and toasts when it could not', async () => {
		const wrapper = mount(TaskCreateDialog, { props: { room: kitchen } })

		await picker(wrapper).vm.$emit('error', new Error('boom'))
		expect(showError).toHaveBeenCalledWith('Failed to create task')
		expect(wrapper.emitted('close')).toBeUndefined()

		await picker(wrapper).vm.$emit('created')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('creates with ONE aggregate request in the form\'s room, then closes', async () => {
		mockRoomTasksStore.createTaskAggregate.mockResolvedValue({})
		const wrapper = mount(TaskCreateDialog)

		await form(wrapper).vm.$emit('submit', { name: 'Dishes', roomId: 12, assigneeUserIds: ['mom'], energyCost: 2, nextDue: '2030-05-10' })
		await flushPromises()

		expect(mockRoomTasksStore.createTaskAggregate).toHaveBeenCalledTimes(1)
		expect(mockRoomTasksStore.createTaskAggregate).toHaveBeenCalledWith({
			roomId: 12,
			task: { name: 'Dishes', energyCost: 2 },
			assigneeUserIds: ['mom'],
			nextDue: '2030-05-10',
		})
		expect(showSuccess).toHaveBeenCalledWith('Task created')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('creates in the heading\'s room whatever the form reports', async () => {
		mockRoomTasksStore.createTaskAggregate.mockResolvedValue({})
		const wrapper = mount(TaskCreateDialog, { props: { room: kitchen } })
		await picker(wrapper).vm.$emit('custom')

		await form(wrapper).vm.$emit('submit', { name: 'Dishes', roomId: 99 })
		await flushPromises()

		expect(mockRoomTasksStore.createTaskAggregate).toHaveBeenCalledWith({
			roomId: 12,
			task: { name: 'Dishes' },
			assigneeUserIds: [],
			nextDue: null,
		})
	})

	it('stays open with the input kept when the request fails', async () => {
		mockRoomTasksStore.createTaskAggregate.mockRejectedValue(new Error('boom'))
		const wrapper = mount(TaskCreateDialog)

		await form(wrapper).vm.$emit('submit', { name: 'Dishes', roomId: 12 })
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Failed to create task')
		expect(showSuccess).not.toHaveBeenCalled()
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(form(wrapper).props('loading')).toBe(false)
	})

	it('closes on cancel', async () => {
		const wrapper = mount(TaskCreateDialog)

		await form(wrapper).vm.$emit('cancel')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
