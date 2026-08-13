import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import TaskTemplatePicker from '../../../src/components/Tasks/TaskTemplatePicker.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

let mockSetupStore
let mockRoomTasksStore

jest.mock('../../../src/store/setup.js', () => ({
	useSetupStore: () => mockSetupStore,
}))
jest.mock('../../../src/store/roomTasks.js', () => ({
	useRoomTasksStore: () => mockRoomTasksStore,
}))

describe('TaskTemplatePicker.vue', () => {
	let pinia

	const templates = [
		{ id: 'kitchen_counters', name: 'Wipe counters', roomTypes: ['kitchen'], levels: { putzteufel: {}, putzmuffel: null } },
		{ id: 'household_trash', name: 'Take out trash', roomTypes: ['household'], levels: { putzteufel: {}, putzmuffel: {} } },
		{ id: 'bathroom_toilet', name: 'Clean toilet', roomTypes: ['bathroom'], levels: { putzteufel: {}, putzmuffel: {} } },
	]

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		mockSetupStore = {
			loading: false,
			hasCatalog: true,
			levels: [
				{ id: 'putzteufel', label: 'Putzteufel', description: 'a' },
				{ id: 'putzmuffel', label: 'Putzmuffel', description: 'b' },
			],
			templates,
			fetchCatalog: jest.fn().mockResolvedValue({}),
		}
		mockRoomTasksStore = {
			createFromTemplate: jest.fn().mockResolvedValue({ id: 99 }),
		}
	})

	const mountPicker = (props = {}) => mount(TaskTemplatePicker, {
		props: { roomId: 5, roomType: 'kitchen', ...props },
		global: { plugins: [pinia] },
	})

	it('filters templates by room type and includes household templates', async () => {
		const wrapper = mountPicker({ roomType: 'kitchen' })
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="picker-template-kitchen_counters"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="picker-template-household_trash"]').exists()).toBe(true)
		// bathroom-only template is filtered out for a kitchen room
		expect(wrapper.find('[data-testid="picker-template-bathroom_toilet"]').exists()).toBe(false)
	})

	it('marks a level-exclusive template as not available', async () => {
		const wrapper = mountPicker({ roomType: 'kitchen' })
		await wrapper.vm.$nextTick()

		// kitchen_counters has putzmuffel === null
		await wrapper.find('[data-testid="picker-level-putzmuffel"]').trigger('click')
		const item = wrapper.find('[data-testid="picker-template-kitchen_counters"]')
		expect(item.attributes('disabled')).toBeDefined()
	})

	it('emits custom when switching to the custom-task tab', async () => {
		const wrapper = mountPicker()
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="picker-tab-custom"]').trigger('click')
		expect(wrapper.emitted('custom')).toBeTruthy()
	})

	it('toggles the override form', async () => {
		const wrapper = mountPicker({ roomType: 'kitchen' })
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="picker-adjust-kitchen_counters"]').trigger('click')
		expect(wrapper.find('[data-testid="picker-overrides-kitchen_counters"]').exists()).toBe(true)
	})

	it('creates a room task from the template and emits created', async () => {
		const wrapper = mountPicker({ roomType: 'kitchen' })
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="picker-template-kitchen_counters"]').trigger('click')

		expect(mockRoomTasksStore.createFromTemplate).toHaveBeenCalledWith(
			expect.objectContaining({ roomId: 5, templateId: 'kitchen_counters', level: 'putzteufel' }),
		)
		expect(wrapper.emitted('created')).toBeTruthy()
	})
})
