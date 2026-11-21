/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { beforeEach, describe, expect, it, jest } from '@jest/globals'
import { showSuccess } from '@nextcloud/dialogs'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import TaskForm from '../../../src/components/Tasks/TaskForm.vue'
import { useChecklistStore } from '../../../src/store/checklist.js'
import { useHouseStore } from '../../../src/store/house.js'
import { useRoomsStore } from '../../../src/store/rooms.js'
import { useRoomTasksStore } from '../../../src/store/roomTasks.js'
import { useTaskFormStore } from '../../../src/store/taskForm.js'
import { useUserStore } from '../../../src/store/user.js'
import { showRefusal } from '../../../src/utils/errorToast.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, params) => Object.entries(params ?? {})
		.reduce((label, [key, value]) => label.replaceAll(`{${key}}`, String(value)), text)),
}))
jest.mock('@nextcloud/dialogs', () => ({ showSuccess: jest.fn(), showError: jest.fn() }))
jest.mock('@/utils/logger', () => ({ logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() } }))
jest.mock('../../../src/utils/errorToast.js', () => ({ showRefusal: jest.fn() }))
jest.mock('../../../src/services/api.js', () => ({ api: { get: jest.fn(), post: jest.fn(), put: jest.fn(), delete: jest.fn() } }))

// The dialog renders in place: Modal itself teleports to the body.
jest.mock('../../../src/components/Common/Modal.vue', () => ({
	name: 'Modal',
	props: ['ariaLabel', 'show', 'persistent'],
	emits: ['close'],
	template: '<div class="modal-stub" :aria-label="ariaLabel"><slot /></div>',
}))
jest.mock('../../../src/components/Tasks/RoomPicker.vue', () => ({
	name: 'RoomPicker',
	props: ['modelValue', 'initialFloorId'],
	emits: ['update:modelValue'],
	template: '<button type="button" data-testid="room-picker-stub" @click="$emit(\'update:modelValue\', 12)">{{ modelValue }}</button>',
}))
jest.mock('../../../src/components/Tasks/AssigneeChips.vue', () => ({
	name: 'AssigneeChips',
	props: ['modelValue', 'members'],
	emits: ['update:modelValue'],
	template: '<button type="button" data-testid="assignee-chips-stub" @click="$emit(\'update:modelValue\', [\'mom\', \'kid\'])">{{ modelValue.join() }}</button>',
}))
jest.mock('../../../src/components/Tasks/TaskTemplateFill.vue', () => ({
	name: 'TaskTemplateFill',
	props: ['preferredRoomType'],
	emits: ['fill'],
	template: '<div data-testid="template-fill-stub" />',
}))

function task(over = {}) {
	return {
		id: 7,
		name: 'Dishes',
		description: '',
		instructions: '',
		energyCost: 3,
		priority: 2,
		category: 'kitchen',
		visibilityType: 'public',
		recurrenceMode: 'recurring',
		schedulingMode: 'dynamic',
		frequencyType: 'daily',
		frequencyValue: 2,
		flexDays: 1,
		isVoluntary: false,
		...over,
	}
}
const roomTask = (over = {}) => ({ id: 11, taskId: 7, roomId: 12, isActive: true, assignees: ['mom'], anchorOn: '2026-11-20', lastCompleted: null, cycle: { cycleKey: 'c9', expectedVersion: 4, state: 'open' }, ...over })
const refusal = (status, code) => Object.assign(new Error(code), { status, code })

/**
 * The one form for a new and an existing task (CS-PLAN-091 WP-B6,
 * FEAT-UX-040, D-091-85..87).
 */
describe('TaskForm', () => {
	let pinia
	let userStore
	let taskFormStore
	let roomTasksStore
	let checklistStore

	beforeEach(() => {
		jest.clearAllMocks()
		pinia = createPinia()
		setActivePinia(pinia)
		userStore = useUserStore()
		userStore.currentUser = { nextcloudUserId: 'mom', role: 'owner' }
		userStore.houseUsers = [{ nextcloudUserId: 'mom', displayName: 'Mom' }, { nextcloudUserId: 'kid', displayName: 'Kid' }]
		jest.spyOn(userStore, 'fetchHouseUsers').mockResolvedValue([])
		useRoomsStore().rooms = [{ id: 12, name: 'Kitchen', floorId: 3, roomType: 'kitchen' }]
		taskFormStore = useTaskFormStore()
		jest.spyOn(taskFormStore, 'create').mockResolvedValue({ task: { id: 8 }, roomTask: { id: 12 }, assignees: [] })
		jest.spyOn(taskFormStore, 'save').mockResolvedValue({})
		roomTasksStore = useRoomTasksStore()
		jest.spyOn(roomTasksStore, 'deleteRoomTask').mockResolvedValue()
		checklistStore = useChecklistStore()
		jest.spyOn(checklistStore, 'fetchTaskItems').mockResolvedValue([])
	})

	const mountForm = async (props = {}) => {
		const wrapper = mount(TaskForm, { props, global: { plugins: [pinia] } })
		await flushPromises()
		return wrapper
	}
	const editForm = (taskOver = {}, roomTaskOver = {}) => mountForm({ item: { task: task(taskOver), roomTask: roomTask(roomTaskOver) } })
	const saveButton = (wrapper) => wrapper.get('[data-testid="save-task-button"]')
	const submit = async (wrapper) => {
		await wrapper.get('[data-testid="task-form"]').trigger('submit')
		await flushPromises()
	}

	describe('a new task', () => {
		it('names itself, offers the templates and no deletion', async () => {
			const wrapper = await mountForm()

			expect(wrapper.get('.form-title').text()).toBe('New task')
			expect(wrapper.get('.modal-stub').attributes('aria-label')).toBe('New task')
			expect(wrapper.find('[data-testid="template-fill-stub"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="task-delete-button"]').exists()).toBe(false)
		})

		it('saves only with a name and a room', async () => {
			const wrapper = await mountForm()
			expect(saveButton(wrapper).element.disabled).toBe(true)

			await wrapper.get('[data-testid="task-name-input"]').setValue('Vacuum')
			expect(saveButton(wrapper).element.disabled).toBe(true)
			await wrapper.get('[data-testid="room-picker-stub"]').trigger('click')
			expect(saveButton(wrapper).element.disabled).toBe(false)

			await wrapper.get('[data-testid="task-name-input"]').setValue('   ')
			expect(saveButton(wrapper).element.disabled).toBe(true)
		})

		it('starts from the prefill of the list: room, floor and people', async () => {
			const wrapper = await mountForm({ prefill: { roomId: 12, floorId: 3, assigneeIds: ['kid'] } })

			expect(wrapper.getComponent({ name: 'RoomPicker' }).props()).toEqual({ modelValue: 12, initialFloorId: 3 })
			expect(wrapper.getComponent({ name: 'AssigneeChips' }).props('modelValue')).toEqual(['kid'])
			// The templates of the chosen room's type come first.
			expect(wrapper.getComponent({ name: 'TaskTemplateFill' }).props('preferredRoomType')).toBe('kitchen')
		})

		it('creates the task in one request with everything the form holds', async () => {
			const wrapper = await mountForm({ prefill: { roomId: 12, floorId: 3, assigneeIds: [] } })
			await wrapper.get('[data-testid="task-name-input"]').setValue('  Vacuum  ')
			await wrapper.get('[data-testid="assignee-chips-stub"]').trigger('click')
			await wrapper.get('[data-testid="task-subtask-new"]').setValue('Move the chairs')
			await wrapper.get('[data-testid="task-subtask-add"]').trigger('click')
			await wrapper.get('[data-testid="task-next-due-input"]').setValue('2026-12-01')

			await submit(wrapper)

			expect(taskFormStore.create).toHaveBeenCalledTimes(1)
			expect(taskFormStore.create).toHaveBeenCalledWith({
				roomId: 12,
				task: expect.objectContaining({ name: 'Vacuum', energyCost: 2, recurrenceMode: 'recurring', frequencyType: 'weekly', flexDays: 1, isVoluntary: false }),
				assigneeUserIds: ['mom', 'kid'],
				checklistItems: [{ label: 'Move the chairs' }],
				sleeping: false,
				firstDate: '2026-12-01',
			})
			expect(showSuccess).toHaveBeenCalledWith('Task created')
			expect(wrapper.emitted('close')).toHaveLength(1)
		})

		it('stays focusable while a save runs and sends it once', async () => {
			let finish
			taskFormStore.create.mockReturnValueOnce(new Promise((resolve) => {
				finish = resolve
			}))
			const wrapper = await mountForm({ prefill: { roomId: 12, floorId: 3, assigneeIds: [] } })
			await wrapper.get('[data-testid="task-name-input"]').setValue('Vacuum')
			expect(saveButton(wrapper).attributes('aria-disabled')).toBeUndefined()

			await wrapper.get('[data-testid="task-form"]').trigger('submit')
			await wrapper.get('[data-testid="task-form"]').trigger('submit')

			// Not `disabled`: a disabled button would drop the focus of the press.
			expect(saveButton(wrapper).attributes('aria-disabled')).toBe('true')
			expect(saveButton(wrapper).element.disabled).toBe(false)
			expect(taskFormStore.create).toHaveBeenCalledTimes(1)

			finish({ task: { id: 8 }, roomTask: { id: 12 }, assignees: [] })
			await flushPromises()
			expect(wrapper.emitted('close')).toHaveLength(1)
		})

		it('shows why a creation was refused and stays open with the draft', async () => {
			const refused = refusal(400, 'VALIDATION_ERROR')
			taskFormStore.create.mockRejectedValueOnce(refused)
			const wrapper = await mountForm({ prefill: { roomId: 12, floorId: 3, assigneeIds: [] } })
			await wrapper.get('[data-testid="task-name-input"]').setValue('Vacuum')

			await submit(wrapper)

			expect(showRefusal).toHaveBeenCalledWith(refused, 'Failed to create task')
			expect(wrapper.emitted('close')).toBeUndefined()
			expect(wrapper.get('[data-testid="task-name-input"]').element.value).toBe('Vacuum')
			expect(saveButton(wrapper).element.disabled).toBe(false)
		})

		it('takes what a template fills in, and keeps room and people', async () => {
			const wrapper = await mountForm({ prefill: { roomId: 12, floorId: 3, assigneeIds: ['kid'] } })

			await wrapper.getComponent({ name: 'TaskTemplateFill' }).vm.$emit('fill', {
				details: { name: 'Wipe counters', description: 'All of them', instructions: '', energyCost: 4, priority: 3, category: 'kitchen' },
				rhythm: { mode: 'after', every: 2, unit: 'day', weekdays: [], monthDay: null, flexDays: 1 },
			})

			expect(wrapper.get('[data-testid="task-name-input"]').element.value).toBe('Wipe counters')
			expect(wrapper.get('[data-testid="task-frequency-value"]').element.value).toBe('2')
			expect(wrapper.get('[data-testid="task-frequency-unit"]').element.value).toBe('day')
			expect(wrapper.getComponent({ name: 'RoomPicker' }).props('modelValue')).toBe(12)
			expect(wrapper.getComponent({ name: 'AssigneeChips' }).props('modelValue')).toEqual(['kid'])
		})

		it('closes on Cancel without a request', async () => {
			const wrapper = await mountForm()

			await wrapper.get('[data-testid="task-cancel-button"]').trigger('click')

			expect(wrapper.emitted('close')).toHaveLength(1)
			expect(taskFormStore.create).not.toHaveBeenCalled()
		})
	})

	describe('an existing task', () => {
		it('shows what the task holds', async () => {
			const wrapper = await editForm()

			expect(wrapper.get('.form-title').text()).toBe('Edit task')
			expect(wrapper.find('[data-testid="template-fill-stub"]').exists()).toBe(false)
			expect(wrapper.get('[data-testid="task-name-input"]').element.value).toBe('Dishes')
			expect(wrapper.get('[data-testid="task-rhythm-after"]').attributes('aria-pressed')).toBe('true')
			expect(wrapper.get('[data-testid="task-frequency-value"]').element.value).toBe('2')
			expect(wrapper.get('[data-testid="task-next-due-input"]').element.value).toBe('2026-11-20')
			expect(wrapper.getComponent({ name: 'AssigneeChips' }).props('modelValue')).toEqual(['mom'])
			expect(wrapper.find('[data-testid="task-delete-button"]').exists()).toBe(true)
		})

		it('saves the form as one command with the version of the shown cycle', async () => {
			const wrapper = await editForm()
			await wrapper.get('[data-testid="task-name-input"]').setValue('Wash up')

			await submit(wrapper)

			// Of the definition only what changed (D-091-100): the rhythm, the other details and the first date stay as stored.
			expect(taskFormStore.save).toHaveBeenCalledWith(11, 4, {
				roomId: 12,
				task: { name: 'Wash up' },
				assigneeUserIds: ['mom'],
				checklistItems: [],
				sleeping: false,
			})
			expect(showSuccess).not.toHaveBeenCalled()
			expect(wrapper.emitted('close')).toHaveLength(1)
		})

		it('sends the version it was opened with, also after the task changed: a fresher one would overwrite that change', async () => {
			roomTasksStore.dailyTasks = [{ roomTask: { id: 11 }, task: { id: 7 }, cycle: { cycleKey: 'c10', expectedVersion: 6, state: 'open' } }]
			const stale = refusal(409, 'STALE_TARGET')
			taskFormStore.save.mockRejectedValueOnce(stale)
			const wrapper = await editForm()

			await submit(wrapper)
			expect(taskFormStore.save.mock.calls[0][1]).toBe(4)
			expect(showRefusal).toHaveBeenCalledWith(stale, 'Could not save the task')
			expect(wrapper.emitted('close')).toBeUndefined()

			// A second press sends the same stale receipt, never the row's new version with the old content.
			taskFormStore.save.mockRejectedValueOnce(stale)
			await submit(wrapper)
			expect(taskFormStore.save.mock.calls[1][1]).toBe(4)
		})

		it('sends a changed first date, and no stored value the form only shows differently', async () => {
			useHouseStore().house = { taskCategories: { revision: 1, items: [{ key: 'kitchen', label: null }, { key: 'other', label: null }] } }
			// A category the household deleted shows as Other; legacy empty values show their defaults.
			const wrapper = await editForm({ category: 'bathroom', energyCost: null, priority: null, description: null })
			expect(wrapper.get('[data-testid="task-category"]').element.value).toBe('other')
			await wrapper.get('[data-testid="task-next-due-input"]').setValue('2026-11-25')

			await submit(wrapper)

			expect(taskFormStore.save.mock.calls[0][2].task).toEqual({})
			expect(taskFormStore.save.mock.calls[0][2].firstDate).toBe('2026-11-25')
		})

		it('sends a category the member picked', async () => {
			useHouseStore().house = { taskCategories: { revision: 1, items: [{ key: 'kitchen', label: null }, { key: 'other', label: null }] } }
			const wrapper = await editForm({ category: 'bathroom' })

			await wrapper.get('[data-testid="task-category"]').setValue('kitchen')
			await submit(wrapper)

			expect(taskFormStore.save.mock.calls[0][2].task).toEqual({ category: 'kitchen' })
		})

		it('sends no first date once the task was done: the history moves the window then', async () => {
			const wrapper = await editForm({}, { lastCompleted: '2026-11-15T09:00:00Z' })
			expect(wrapper.find('[data-testid="task-next-due-input"]').exists()).toBe(false)
			expect(wrapper.find('[data-testid="task-last-done"]').exists()).toBe(true)

			await submit(wrapper)

			expect(taskFormStore.save.mock.calls[0][2]).not.toHaveProperty('firstDate')
		})

		it('asks this room or all rooms when a shared definition changes, and saves with the answer', async () => {
			taskFormStore.save.mockRejectedValueOnce(refusal(409, 'SCOPE_REQUIRED'))
			const wrapper = await editForm()

			await submit(wrapper)
			expect(showRefusal).not.toHaveBeenCalled()
			expect(wrapper.emitted('close')).toBeUndefined()
			expect(wrapper.find('[data-testid="task-scope-dialog"]').exists()).toBe(true)

			await wrapper.get('[data-testid="task-scope-all-rooms"]').trigger('click')
			await flushPromises()

			expect(taskFormStore.save).toHaveBeenCalledTimes(2)
			expect(taskFormStore.save.mock.calls[1][2]).toEqual({ ...taskFormStore.save.mock.calls[0][2], scope: 'allRooms' })
			expect(wrapper.find('[data-testid="task-scope-dialog"]').exists()).toBe(false)
			expect(wrapper.emitted('close')).toHaveLength(1)
		})

		it('leaves the scope question without a save', async () => {
			taskFormStore.save.mockRejectedValueOnce(refusal(409, 'SCOPE_REQUIRED'))
			const wrapper = await editForm()
			await submit(wrapper)

			await wrapper.get('[data-testid="task-scope-cancel"]').trigger('click')

			expect(wrapper.find('[data-testid="task-scope-dialog"]').exists()).toBe(false)
			expect(taskFormStore.save).toHaveBeenCalledTimes(1)
			expect(wrapper.emitted('close')).toBeUndefined()
		})

		it('says how many rooms share the definition', async () => {
			roomTasksStore.roomTasks = [{ id: 11, taskId: 7 }, { id: 12, taskId: 7 }, { id: 13, taskId: 8 }]

			expect((await editForm()).get('[data-testid="task-shared-hint"]').text()).toBe('This task is used in 2 rooms.')
			roomTasksStore.roomTasks = [{ id: 11, taskId: 7 }]
			expect((await editForm()).find('[data-testid="task-shared-hint"]').exists()).toBe(false)
		})

		it('shows why a save was refused and stays open', async () => {
			const refused = refusal(403, 'FORBIDDEN')
			taskFormStore.save.mockRejectedValueOnce(refused)
			const wrapper = await editForm()

			await submit(wrapper)

			expect(showRefusal).toHaveBeenCalledWith(refused, 'Could not save the task')
			expect(wrapper.emitted('close')).toBeUndefined()
		})
	})

	describe('deleting (D-091-86)', () => {
		it('deletes the task after the confirmation and closes', async () => {
			const wrapper = await editForm()

			await wrapper.get('[data-testid="task-delete-button"]').trigger('click')
			expect(wrapper.get('[data-testid="confirm-dialog"]').text()).toContain('Delete this task?')
			expect(wrapper.get('[data-testid="confirm-dialog"]').text()).toContain('What was done stays in the history.')
			expect(roomTasksStore.deleteRoomTask).not.toHaveBeenCalled()

			await wrapper.get('[data-testid="confirm-dialog-confirm"]').trigger('click')
			await flushPromises()

			expect(roomTasksStore.deleteRoomTask).toHaveBeenCalledWith(11)
			expect(wrapper.emitted('close')).toHaveLength(1)
			expect(taskFormStore.save).not.toHaveBeenCalled()
		})

		it('keeps the task when the confirmation is cancelled', async () => {
			const wrapper = await editForm()
			await wrapper.get('[data-testid="task-delete-button"]').trigger('click')

			await wrapper.get('[data-testid="confirm-dialog-cancel"]').trigger('click')

			expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(false)
			expect(roomTasksStore.deleteRoomTask).not.toHaveBeenCalled()
		})

		it('shows why a deletion failed and keeps the confirmation open', async () => {
			const failure = refusal(403, 'FORBIDDEN')
			roomTasksStore.deleteRoomTask.mockRejectedValueOnce(failure)
			const wrapper = await editForm()
			await wrapper.get('[data-testid="task-delete-button"]').trigger('click')

			await wrapper.get('[data-testid="confirm-dialog-confirm"]').trigger('click')
			await flushPromises()

			expect(showRefusal).toHaveBeenCalledWith(failure, 'Failed to delete task')
			expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(true)
			expect(wrapper.emitted('close')).toBeUndefined()
		})
	})

	describe('sub-tasks', () => {
		it('shows the cached sub-tasks without a read and saves them with their ids', async () => {
			checklistStore.items = { 7: [{ id: 4, label: 'Rinse' }, { id: 5, label: 'Load' }] }
			const wrapper = await editForm()

			expect(checklistStore.fetchTaskItems).not.toHaveBeenCalled()
			await wrapper.findAll('[data-testid="task-subtask-remove"]')[0].trigger('click')
			await submit(wrapper)

			expect(taskFormStore.save.mock.calls[0][2].checklistItems).toEqual([{ id: 5, label: 'Load' }])
		})

		it('reads the sub-tasks of a task the cache does not hold', async () => {
			checklistStore.fetchTaskItems.mockResolvedValueOnce([{ id: 4, label: 'Rinse' }])
			const wrapper = await editForm()

			expect(checklistStore.fetchTaskItems).toHaveBeenCalledWith(7)
			expect(wrapper.findAll('[data-testid="task-subtask-label"]').map((input) => input.element.value)).toEqual(['Rinse'])
		})

		it('leaves the sub-tasks as they are when they could not be read, and offers a retry instead of the list', async () => {
			checklistStore.fetchTaskItems.mockRejectedValueOnce(new Error('offline'))
			const wrapper = await editForm()

			expect(wrapper.text()).toContain('Could not load the sub-tasks')
			expect(wrapper.find('[data-testid="task-subtask-new"]').exists()).toBe(false)
			await submit(wrapper)
			expect(taskFormStore.save.mock.calls[0][2].checklistItems).toBeNull()

			checklistStore.fetchTaskItems.mockResolvedValueOnce([{ id: 4, label: 'Rinse' }])
			await wrapper.getComponent({ name: 'ErrorState' }).vm.$emit('retry')
			await flushPromises()
			expect(wrapper.findAll('[data-testid="task-subtask-label"]').map((input) => input.element.value)).toEqual(['Rinse'])
			await submit(wrapper)
			expect(taskFormStore.save.mock.calls[1][2].checklistItems).toEqual([{ id: 4, label: 'Rinse' }])
		})

		it('keeps a sub-task added while the others still load, after them', async () => {
			let resolve
			checklistStore.fetchTaskItems.mockReturnValueOnce(new Promise((done) => {
				resolve = done
			}))
			const wrapper = mount(TaskForm, { props: { item: { task: task(), roomTask: roomTask() } }, global: { plugins: [pinia] } })
			await flushPromises()

			await wrapper.get('[data-testid="task-subtask-new"]').setValue('Dry')
			await wrapper.get('[data-testid="task-subtask-add"]').trigger('click')
			resolve([{ id: 4, label: 'Rinse' }])
			await flushPromises()

			expect(wrapper.findAll('[data-testid="task-subtask-label"]').map((input) => input.element.value)).toEqual(['Rinse', 'Dry'])
		})

		it('drops blank sub-tasks and trims the others', async () => {
			checklistStore.items = { 7: [{ id: 4, label: 'Rinse' }] }
			const wrapper = await editForm()

			await wrapper.findAll('[data-testid="task-subtask-label"]')[0].setValue('   ')
			await submit(wrapper)

			expect(taskFormStore.save.mock.calls[0][2].checklistItems).toEqual([])
		})

		it('adds a sub-task without saving the form', async () => {
			const wrapper = await editForm()

			await wrapper.get('[data-testid="task-subtask-new"]').setValue('Dry')
			await wrapper.get('[data-testid="task-subtask-add"]').trigger('click')
			await wrapper.get('[data-testid="task-subtask-new"]').setValue('Put away')
			await wrapper.get('[data-testid="task-subtask-new"]').trigger('keydown', { key: 'Enter' })
			await flushPromises()

			expect(taskFormStore.save).not.toHaveBeenCalled()
			expect(wrapper.emitted('close')).toBeUndefined()
			expect(wrapper.findAll('[data-testid="task-subtask-label"]').map((input) => input.element.value)).toContain('Dry')
		})
	})

	describe('bonus and sleep', () => {
		it('hides the rhythm and the window of a bonus task and sends the flag', async () => {
			const wrapper = await editForm()

			await wrapper.get('[data-testid="task-bonus-switch"]').setValue(true)

			expect(wrapper.find('[data-testid="task-rhythm"]').exists()).toBe(false)
			expect(wrapper.find('[data-testid="task-next-window"]').exists()).toBe(false)
			await submit(wrapper)
			expect(taskFormStore.save.mock.calls[0][2].task.isVoluntary).toBe(true)
		})

		it('puts a task to sleep with an optional wake date from tomorrow', async () => {
			const wrapper = await editForm()
			expect(wrapper.find('[data-testid="task-wake-on"]').exists()).toBe(false)

			await wrapper.get('[data-testid="task-sleeping-switch"]').setValue(true)
			const wakeOn = wrapper.get('[data-testid="task-wake-on"]')
			expect(wakeOn.attributes('min')).toMatch(/^\d{4}-\d{2}-\d{2}$/)
			await wakeOn.setValue('2099-03-01')
			await submit(wrapper)

			expect(taskFormStore.save.mock.calls[0][2]).toMatchObject({ sleeping: true, wakeOn: '2099-03-01' })
		})

		it('opens a sleeping task as sleeping, with its wake date', async () => {
			const wrapper = await editForm({}, { cycle: { state: 'asleep', expectedVersion: 5, wakeOn: '2099-03-01' } })

			expect(wrapper.get('[data-testid="task-sleeping-switch"]').element.checked).toBe(true)
			expect(wrapper.get('[data-testid="task-wake-on"]').element.value).toBe('2099-03-01')
		})
	})

	describe('members and categories', () => {
		it('reads the members where nobody has, and offers a retry that keeps the draft', async () => {
			userStore.houseUsers = []
			userStore.fetchHouseUsers.mockRejectedValueOnce(new Error('offline'))
			const wrapper = await mountForm()
			await wrapper.get('[data-testid="task-name-input"]').setValue('Keep this draft')
			expect(wrapper.text()).toContain('Failed to load members')
			expect(wrapper.findComponent({ name: 'AssigneeChips' }).exists()).toBe(false)

			userStore.fetchHouseUsers.mockImplementationOnce(async () => {
				userStore.houseUsers = [{ nextcloudUserId: 'dad', displayName: 'Dad' }]
			})
			await wrapper.get('[data-testid="error-retry"]').trigger('click')
			await flushPromises()

			expect(userStore.fetchHouseUsers).toHaveBeenCalledTimes(2)
			expect(wrapper.findComponent({ name: 'AssigneeChips' }).exists()).toBe(true)
			expect(wrapper.get('[data-testid="task-name-input"]').element.value).toBe('Keep this draft')
		})

		it('reads no members another view has loaded', async () => {
			await mountForm()

			expect(userStore.fetchHouseUsers).not.toHaveBeenCalled()
		})

		it('offers the household categories and reads a deleted key as Other (CS-PLAN-090 WP-I)', async () => {
			useHouseStore().house = { taskCategories: { revision: 1, items: [{ key: 'kitchen', label: null }, { key: 'c0a1b2c3d', label: 'Garden' }, { key: 'other', label: null }] } }
			const category = (wrapper) => wrapper.get('[data-testid="task-category"]')

			const created = await mountForm()
			expect(category(created).findAll('option').map((option) => option.text())).toEqual(['Kitchen', 'Garden', 'Other'])
			expect(category(created).element.value).toBe('other')
			expect(category(await editForm({ category: 'bathroom' })).element.value).toBe('other')
			expect(category(await editForm({ category: 'c0a1b2c3d' })).element.value).toBe('c0a1b2c3d')
		})

		it('keeps a stored category until the house list arrives (RF-090-34)', async () => {
			const wrapper = await editForm({ category: 'c11111111' })

			expect(wrapper.get('[data-testid="task-category"]').element.value).toBe('c11111111')
		})

		it('offers admin-only visibility to the owner only', async () => {
			const options = (wrapper) => wrapper.get('[data-testid="task-visibility-select"]').findAll('option').map((option) => option.element.value)
			expect(options(await mountForm())).toContain('admin-only')

			userStore.currentUser = { nextcloudUserId: 'dad', role: 'member' }
			expect(options(await mountForm())).not.toContain('admin-only')
		})
	})
})
