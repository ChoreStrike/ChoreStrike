import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import TaskForm from '../../../src/components/Tasks/TaskForm.vue'

// Mock Nextcloud l10n
jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

// Mock the stores
jest.mock('../../../src/store/tasks.js', () => ({
	useTasksStore: jest.fn(() => ({
		tasks: [
			{ id: 1, name: 'Clean Kitchen', priority: 2, taskTier: 'silver' },
			{ id: 2, name: 'Vacuum Floor', priority: 1, taskTier: 'bronze' },
			{ id: 3, name: 'Deep Clean', priority: 3, taskTier: 'gold' },
		],
		fetchTasks: jest.fn(),
	})),
}))

jest.mock('../../../src/store/roomTasks.js', () => ({
	useRoomTasksStore: jest.fn(() => ({
		roomTasks: [],
	})),
}))

describe('TaskForm', () => {
	let wrapper

	beforeEach(() => {
		setActivePinia(createPinia())
		wrapper = mount(TaskForm, {
			props: {
				roomId: 1,
			},
		})
	})

	describe('Rendering', () => {
		it('Renders form with all fields', () => {
			expect(wrapper.find('form.task-form').exists()).toBe(true)
			expect(wrapper.find('select#taskId').exists()).toBe(true)
			expect(wrapper.find('input#nextDue').exists()).toBe(true)
			expect(wrapper.find('input#assignedToUserId').exists()).toBe(true)
		})

		it('Renders task options from available tasks', () => {
			const options = wrapper.findAll('option[value]')
			expect(options.length).toBe(3)
			expect(options[0].text()).toContain('Clean Kitchen')
			expect(options[1].text()).toContain('Vacuum Floor')
		})

		it('Renders tier indicator in task options', () => {
			const options = wrapper.findAll('option[value]')
			expect(options[0].text()).toContain('silver')
		})
	})

	describe('Form State', () => {
		it('Has initial empty form values', () => {
			const vm = wrapper.vm
			expect(vm.form.taskId).toBeNull()
			expect(vm.form.nextDue).toBe('')
			expect(vm.form.assignedToUserId).toBe('')
		})

		it('Updates form values on input', async () => {
			const select = wrapper.find('select#taskId')
			await select.setValue('1')
			expect(wrapper.vm.form.taskId).toBe(1)

			const dateInput = wrapper.find('input#nextDue')
			await dateInput.setValue('2025-03-15')
			expect(wrapper.vm.form.nextDue).toBe('2025-03-15')

			const assigneeInput = wrapper.find('input#assignedToUserId')
			await assigneeInput.setValue('user123')
			expect(wrapper.vm.form.assignedToUserId).toBe('user123')
		})
	})

	describe('Form Validation', () => {
		it('Disables submit button when taskId is null', () => {
			const submitButton = wrapper.find('button[type="submit"]')
			expect(submitButton.attributes('disabled')).toBeDefined()
		})

		it('Enables submit button when taskId is selected', async () => {
			await wrapper.find('select#taskId').setValue('1')
			const submitButton = wrapper.find('button[type="submit"]')
			expect(submitButton.attributes('disabled')).toBeUndefined()
		})

		it('Requires task selection', async () => {
			const select = wrapper.find('select#taskId')
			expect(select.attributes('required')).toBeDefined()
		})
	})

	describe('Form Submission', () => {
		it('Emits submit event with form data', async () => {
			await wrapper.find('select#taskId').setValue('1')
			await wrapper.find('input#nextDue').setValue('2025-03-15')
			await wrapper.find('input#assignedToUserId').setValue('user123')

			await wrapper.find('form').trigger('submit.prevent')

			expect(wrapper.emitted('submit')).toBeTruthy()
			expect(wrapper.emitted('submit')[0]).toEqual([{
				taskId: 1,
				nextDue: '2025-03-15',
				assignedToUserId: 'user123',
			}])
		})

		it('Does not emit submit when taskId is null', async () => {
			await wrapper.find('form').trigger('submit.prevent')
			expect(wrapper.emitted('submit')).toBeFalsy()
		})

		it('Emits null for empty optional fields', async () => {
			await wrapper.find('select#taskId').setValue('1')
			await wrapper.find('form').trigger('submit.prevent')

			expect(wrapper.emitted('submit')[0]).toEqual([{
				taskId: 1,
				nextDue: null,
				assignedToUserId: null,
			}])
		})
	})

	describe('Cancel Action', () => {
		it('Emits cancel event when cancel button clicked', async () => {
			await wrapper.find('button[type="button"]').trigger('click')
			expect(wrapper.emitted('cancel')).toBeTruthy()
		})
	})

	describe('Props', () => {
		it('Accepts roomId prop', () => {
			expect(wrapper.props('roomId')).toBe(1)
		})

		it('Validates roomId is required', () => {
			const { required } = TaskForm.props.roomId
			expect(required).toBe(true)
		})

		it('Validates roomId is Number type', () => {
			const { type } = TaskForm.props.roomId
			expect(type).toBe(Number)
		})
	})
})
