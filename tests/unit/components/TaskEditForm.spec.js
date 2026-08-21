import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import TaskEditForm from '@/components/Tasks/TaskEditForm.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

jest.mock('@/store/user', () => ({
	useUserStore: jest.fn(() => ({
		isHouseOwner: true,
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
})
