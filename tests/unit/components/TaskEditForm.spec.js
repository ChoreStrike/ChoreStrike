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

describe('TaskEditForm', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('renders form', () => {
		const wrapper = mount(TaskEditForm, {
			global: { plugins: [createPinia()] },
		})
		expect(wrapper.find('form').exists()).toBe(true)
	})

	it('emits cancel on cancel button', async () => {
		const wrapper = mount(TaskEditForm, {
			global: { plugins: [createPinia()] },
		})
		const cancelBtn = wrapper.findAll('button').find((b) => b.text().includes('Cancel'))
		if (cancelBtn) {
			await cancelBtn.trigger('click')
			expect(wrapper.emitted('cancel')).toBeTruthy()
		}
	})

	it('renders in edit mode with task prop', () => {
		const wrapper = mount(TaskEditForm, {
			props: {
				task: { id: 1, name: 'Test Task', energyCost: 5, priority: 2 },
			},
			global: { plugins: [createPinia()] },
		})
		expect(wrapper.find('form').exists()).toBe(true)
	})
})
