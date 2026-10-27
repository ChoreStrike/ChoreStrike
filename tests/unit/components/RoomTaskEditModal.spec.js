import { mount, flushPromises } from '@vue/test-utils'
import { reactive } from 'vue'
import { useUserStore } from '@/store/user.js'
import { createPinia, setActivePinia } from 'pinia'
import RoomTaskEditModal from '@/components/Tasks/RoomTaskEditModal.vue'
import { useRoomTasksStore } from '@/store/roomTasks.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

jest.mock('@nextcloud/dialogs', () => ({
	showSuccess: jest.fn(),
	showError: jest.fn(),
	showWarning: jest.fn(),
}))

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

jest.mock('@/components/Tasks/AssigneeChips.vue', () => ({
	name: 'AssigneeChips',
	props: ['modelValue'],
	emits: ['update:modelValue'],
	template: '<button type="button" data-testid="assignee-chips-stub" @click="$emit(\'update:modelValue\', [\'dad\'])">chips</button>',
}))

// The modal feeds AssigneeChips from the user store (CS-PLAN-068) and skips
// its mount-time fetch when members are already loaded.
jest.mock('@/store/user', () => ({
	useUserStore: jest.fn(() => ({
		houseUsers: [{ nextcloudUserId: 'mom', displayName: 'Mom' }],
		fetchHouseUsers: jest.fn(),
	})),
}))

import { showSuccess, showError, showWarning } from '@nextcloud/dialogs'

describe('RoomTaskEditModal', () => {
	let roomTasksStore
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		roomTasksStore = useRoomTasksStore()
		roomTasksStore.updateRoomTaskDueDate = jest.fn().mockResolvedValue({})
		roomTasksStore.setAssignees = jest.fn().mockResolvedValue(['dad'])
	})

	const mountModal = (roomTaskOverrides = {}) => mount(RoomTaskEditModal, {
		props: {
			roomTask: {
				id: 42,
				taskId: 7,
				nextDue: '2026-08-21T00:00:00+00:00',
				assignees: ['mom'],
				...roomTaskOverrides,
			},
			task: { id: 7, name: 'Do the dishes' },
		},
		global: { plugins: [pinia] },
	})

	it('renders due date input seeded from the room task and the task name', () => {
		const wrapper = mountModal()
		expect(wrapper.find('[data-testid="room-task-edit-modal"]').exists()).toBe(true)
		expect((wrapper.find('[data-testid="room-task-due-input"]').element).value).toBe('2026-08-21')
		expect(wrapper.find('h3').text()).toBe('Do the dishes')
	})

	it('contains member-load rejection and retries without saving or clearing assignments', async () => {
		const user = reactive({ houseUsers: [], fetchHouseUsers: jest.fn().mockRejectedValueOnce(new Error('offline')) })
		useUserStore.mockReturnValueOnce(user)
		const wrapper = mountModal()
		await flushPromises()
		expect(wrapper.get('[data-testid="member-load-error"]').text()).toContain('Failed to load members')
		user.fetchHouseUsers.mockImplementationOnce(async () => { user.houseUsers = [{ nextcloudUserId: 'dad' }] })
		expect(wrapper.get('[data-testid="error-retry"]').attributes('type')).toBe('button')
		await wrapper.get('[data-testid="error-retry"]').trigger('click')
		await flushPromises()
		expect(user.fetchHouseUsers).toHaveBeenCalledTimes(2)
		expect(wrapper.find('[data-testid="member-load-error"]').exists()).toBe(false)
		expect(wrapper.vm.assigneeUserIds).toEqual(['mom'])
		expect(roomTasksStore.setAssignees).not.toHaveBeenCalled()
		wrapper.unmount()
	})

	it('persists a changed due date and changed assignees, then emits saved', async () => {
		const wrapper = mountModal()
		await wrapper.find('[data-testid="room-task-due-input"]').setValue('2026-08-25')
		await wrapper.find('[data-testid="assignee-chips-stub"]').trigger('click')
		await wrapper.find('form').trigger('submit')

		expect(roomTasksStore.updateRoomTaskDueDate).toHaveBeenCalledWith(42, '2026-08-25')
		expect(roomTasksStore.setAssignees).toHaveBeenCalledWith(42, ['dad'])
		expect(wrapper.emitted('saved')).toBeTruthy()
		expect(showSuccess).toHaveBeenCalled()
	})

	it('skips both store calls when nothing changed', async () => {
		const wrapper = mountModal()
		await wrapper.find('form').trigger('submit')

		expect(roomTasksStore.updateRoomTaskDueDate).not.toHaveBeenCalled()
		expect(roomTasksStore.setAssignees).not.toHaveBeenCalled()
		expect(wrapper.emitted('saved')).toBeTruthy()
	})

	it('emits cancel cleanly without persisting', async () => {
		const wrapper = mountModal()
		await wrapper.find('[data-testid="room-task-due-input"]').setValue('2026-08-25')
		await wrapper.find('[data-testid="room-task-edit-cancel"]').trigger('click')

		expect(wrapper.emitted('cancel')).toBeTruthy()
		expect(roomTasksStore.updateRoomTaskDueDate).not.toHaveBeenCalled()
	})

	it('surfaces an error toast when the due date update fails and stays open', async () => {
		roomTasksStore.updateRoomTaskDueDate = jest.fn().mockRejectedValue(new Error('boom'))
		const wrapper = mountModal()
		await wrapper.find('[data-testid="room-task-due-input"]').setValue('2026-08-25')
		await wrapper.find('form').trigger('submit')

		expect(showError).toHaveBeenCalled()
		expect(wrapper.emitted('saved')).toBeFalsy()
	})

	// FEAT-UX-045 (CS-PLAN-088 D-088-20): "Last done on".
	it('records the last done day first and never resets a due date the server moved', async () => {
		const wrapper = mountModal()
		// The server re-anchors the interval; the parent row refreshes mid-save.
		roomTasksStore.recordLastDone = jest.fn().mockImplementation(async () => {
			await wrapper.setProps({ roomTask: { id: 42, taskId: 7, nextDue: '2026-08-28T00:00:00+00:00', assignees: ['mom'] } })
		})
		await wrapper.find('[data-testid="room-task-last-done-input"]').setValue('2026-08-19')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(roomTasksStore.recordLastDone).toHaveBeenCalledWith(42, '2026-08-19')
		expect(roomTasksStore.updateRoomTaskDueDate).not.toHaveBeenCalled()
		expect(wrapper.emitted('saved')).toBeTruthy()
	})

	it('shows a coded refusal inline and stays open', async () => {
		showError.mockClear()
		roomTasksStore.recordLastDone = jest.fn().mockRejectedValue(Object.assign(new Error('This task is overdue. Complete it or catch it up instead.'), { code: 'CONFLICT' }))
		const wrapper = mountModal()
		await wrapper.find('[data-testid="room-task-last-done-input"]').setValue('2026-08-19')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(wrapper.get('[data-testid="room-task-last-done-refusal"]').text()).toBe('This task is overdue. Complete it or catch it up instead.')
		expect(showError).not.toHaveBeenCalled()
		expect(wrapper.emitted('saved')).toBeFalsy()
	})

	it('hides the last done field from children', () => {
		useUserStore.mockReturnValueOnce({ currentUser: { role: 'child' }, houseUsers: [{ nextcloudUserId: 'mom' }], fetchHouseUsers: jest.fn() })
		const wrapper = mountModal()

		expect(wrapper.find('[data-testid="room-task-last-done-input"]').exists()).toBe(false)
	})

	it('reports partial success when only the assignment fails', async () => {
		roomTasksStore.setAssignees = jest.fn().mockRejectedValue(new Error('assign boom'))
		const wrapper = mountModal()
		await wrapper.find('[data-testid="assignee-chips-stub"]').trigger('click')
		await wrapper.find('form').trigger('submit')

		expect(roomTasksStore.updateRoomTaskDueDate).not.toHaveBeenCalled()
		expect(roomTasksStore.setAssignees).toHaveBeenCalled()
		expect(showWarning).toHaveBeenCalled()
		expect(wrapper.emitted('saved')).toBeTruthy()
	})
})
