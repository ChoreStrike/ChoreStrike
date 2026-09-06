import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import TaskEditForm from '@/components/Tasks/TaskEditForm.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, params) => {
		let result = text
		if (params) {
			for (const [key, value] of Object.entries(params)) {
				result = result.split(`{${key}}`).join(String(value))
			}
		}
		return result
	}),
}))

jest.mock('@/store/user', () => ({
	useUserStore: jest.fn(() => ({
		isHouseOwner: true,
	})),
}))

jest.mock('@/store/rooms', () => ({
	useRoomsStore: jest.fn(() => ({
		getRoomById: (roomId) => (roomId === 12 ? { id: 12, name: 'Kitchen', floorId: 3, roomType: 'kitchen' } : null),
		getFloorById: (floorId) => (floorId === 3 ? { id: 3, name: 'Ground Floor' } : null),
	})),
}))

jest.mock('@/store/setup', () => ({
	useSetupStore: jest.fn(() => ({
		hasCatalog: true,
		templates: [],
		fetchCatalog: jest.fn(),
	})),
}))

jest.mock('@/components/Tasks/RoomPicker.vue', () => ({
	name: 'RoomPicker',
	props: ['modelValue', 'presetRoomId'],
	emits: ['update:modelValue'],
	template: `<div class="room-picker-stub">
		<span v-if="presetRoomId !== null" data-testid="room-picker-locked">locked-{{ presetRoomId }}</span>
		<button v-else type="button" data-testid="room-picker-stub-select" @click="$emit('update:modelValue', 12)">select</button>
	</div>`,
}))

jest.mock('@/components/Tasks/AssigneeChips.vue', () => ({
	name: 'AssigneeChips',
	props: ['modelValue'],
	emits: ['update:modelValue'],
	template: '<button type="button" data-testid="assignee-chips-stub" @click="$emit(\'update:modelValue\', [\'mom\', \'kid\'])">chips</button>',
}))

const fillRequiredCreateFields = async (wrapper) => {
	await wrapper.find('[data-testid="task-name-input"]').setValue('Vacuum living room')
	await wrapper.find('[data-testid="room-picker-stub-select"]').trigger('click')
}

describe('TaskEditForm', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	const mountForm = (props = {}) => mount(TaskEditForm, {
		props,
		global: { plugins: [createPinia()] },
	})

	it('renders form', () => {
		const wrapper = mountForm()
		expect(wrapper.find('form').exists()).toBe(true)
	})

	it('emits cancel on cancel button', async () => {
		const wrapper = mountForm()
		const cancelBtn = wrapper.findAll('button').find((b) => b.text().includes('Cancel'))
		await cancelBtn.trigger('click')
		expect(wrapper.emitted('cancel')).toBeTruthy()
	})

	it('renders in edit mode with task prop', () => {
		const wrapper = mountForm({ task: { id: 1, name: 'Test Task', energyCost: 5, priority: 2 } })
		expect(wrapper.find('form').exists()).toBe(true)
	})

	it('requires a room in create mode (save disabled without one)', async () => {
		const wrapper = mountForm()
		await wrapper.find('[data-testid="task-name-input"]').setValue('Dishes')

		expect(wrapper.find('[data-testid="save-task-button"]').attributes('disabled')).toBeDefined()
	})

	it('enables save once name and room are set', async () => {
		const wrapper = mountForm()
		await fillRequiredCreateFields(wrapper)

		expect(wrapper.find('[data-testid="save-task-button"]').attributes('disabled')).toBeUndefined()
	})

	it('locks the room picker when a preset room is given', async () => {
		const wrapper = mountForm({ presetRoomId: 7 })
		await wrapper.find('[data-testid="task-name-input"]').setValue('Dishes')

		expect(wrapper.find('[data-testid="room-picker-locked"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="save-task-button"]').attributes('disabled')).toBeUndefined()
	})

	it('hides the room and assignee sections in edit mode (definition-only edit)', () => {
		const wrapper = mountForm({ task: { id: 1, name: 'Test Task' } })

		expect(wrapper.findComponent({ name: 'RoomPicker' }).exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'AssigneeChips' }).exists()).toBe(false)
	})

	it('submits definition plus roomId and assigneeUserIds in create mode', async () => {
		const wrapper = mountForm()
		await fillRequiredCreateFields(wrapper)
		await wrapper.find('[data-testid="assignee-chips-stub"]').trigger('click')
		await wrapper.find('form').trigger('submit')

		const emitted = wrapper.emitted('submit')
		expect(emitted).toBeTruthy()
		const payload = emitted[0][0]
		expect(payload.name).toBe('Vacuum living room')
		expect(payload.roomId).toBe(12)
		expect(payload.assigneeUserIds).toEqual(['mom', 'kid'])
	})

	it('submits definition without roomId/assigneeUserIds and without a scope field in edit mode', async () => {
		const wrapper = mountForm({ task: { id: 1, name: 'Test Task' } })
		await wrapper.find('form').trigger('submit')

		const emitted = wrapper.emitted('submit')
		expect(emitted).toBeTruthy()
		const payload = emitted[0][0]
		expect(payload.roomId).toBeUndefined()
		expect(payload.assigneeUserIds).toBeUndefined()
		expect(payload.scopeType).toBeUndefined()
	})

	it('contains no scope selector anymore (D1)', () => {
		const wrapper = mountForm()
		expect(wrapper.find('#scopeType').exists()).toBe(false)
		expect(wrapper.text()).not.toContain('Single Room')
	})

	it('states the real private-task matrix in the visibility hint (CS-PLAN-061 §3.9, D2)', async () => {
		const wrapper = mountForm()

		await wrapper.find('[data-testid="task-visibility-select"]').setValue('private')

		expect(wrapper.find('[data-testid="task-visibility-hint"]').text())
			.toBe('Only you and the assigned members can see and complete this task. Assigned members can also edit it.')
	})

	it('keeps the admin-only hint owner-worded', async () => {
		const wrapper = mountForm()

		await wrapper.find('[data-testid="task-visibility-select"]').setValue('admin-only')

		expect(wrapper.find('[data-testid="task-visibility-hint"]').text())
			.toBe('Only house owners can see and complete this task')
	})

	it('submits nextDue and persists flexDays within the ratified 0-7 bound (CS-PLAN-063 §3.5)', async () => {
		const wrapper = mountForm()
		await fillRequiredCreateFields(wrapper)

		const flexInput = wrapper.find('[data-testid="task-flex-days-input"]')
		expect(flexInput.attributes('max')).toBe('7')

		await flexInput.setValue('5')
		await wrapper.find('[data-testid="task-next-due-input"]').setValue('2030-05-10')
		await wrapper.find('[data-testid="task-instructions-input"]').setValue('Step 1: sweep')
		await wrapper.find('form').trigger('submit')

		const payload = wrapper.emitted('submit')[0][0]
		expect(payload.nextDue).toBe('2030-05-10')
		expect(payload.flexDays).toBe(5)
		expect(payload.instructions).toBe('Step 1: sweep')
	})

	it('submits nextDue null when the due date is left empty', async () => {
		const wrapper = mountForm()
		await fillRequiredCreateFields(wrapper)
		await wrapper.find('form').trigger('submit')

		const payload = wrapper.emitted('submit')[0][0]
		expect(payload.nextDue).toBeNull()
	})

	it('renders the concise preview with room path, recurrence, due window, assignees, visibility', async () => {
		const wrapper = mountForm()
		await fillRequiredCreateFields(wrapper)
		await wrapper.find('[data-testid="assignee-chips-stub"]').trigger('click')
		await wrapper.find('[data-testid="task-flex-days-input"]').setValue('3')

		const preview = wrapper.find('[data-testid="task-preview"]')
		expect(preview.exists()).toBe(true)
		expect(preview.text()).toContain('Ground Floor → Kitchen')
		expect(preview.text()).toContain('Weekly')
		expect(preview.text()).toContain('tomorrow')
		expect(preview.text()).toContain('±3 days')
		expect(preview.text()).toContain('mom, kid')

		// The preview disappears in edit mode.
		const editWrapper = mountForm({ task: { id: 1, name: 'Test Task' } })
		expect(editWrapper.find('[data-testid="task-preview"]').exists()).toBe(false)
	})

	it('applies a recurrence preset by its own shape only (CS-PLAN-063 D4)', async () => {
		const wrapper = mountForm()

		const selector = wrapper.findComponent({ name: 'RecurrencePresetSelector' })
		selector.vm.$emit('select', { id: 'weekly', name: 'Weekly', icon: 'CalendarDays', recurrence: 'weekly' })
		await wrapper.vm.$nextTick()
		await wrapper.find('form').trigger('submit')

		const payload = wrapper.emitted('submit')[0][0]
		expect(payload.frequencyType).toBe('weekly')
		expect(payload.recurrenceMode).toBe('recurring')

		// One-time preset maps to the once recurrence.
		selector.vm.$emit('select', { id: 'one-time', name: 'One-time', icon: 'CheckCircle', recurrence: null })
		await wrapper.vm.$nextTick()
		await wrapper.find('form').trigger('submit')
		expect(wrapper.emitted('submit')[1][0].recurrenceMode).toBe('once')
	})
})
