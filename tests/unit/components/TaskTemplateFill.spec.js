/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { beforeEach, describe, expect, it, jest } from '@jest/globals'
import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
import TaskTemplateFill from '../../../src/components/Tasks/TaskTemplateFill.vue'
import { logger } from '../../../src/utils/logger.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, params) => Object.entries(params ?? {})
		.reduce((label, [key, value]) => label.replaceAll(`{${key}}`, String(value)), text)),
}))
jest.mock('@/utils/logger', () => ({ logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() } }))

let mockSetupStore
jest.mock('../../../src/store/setup.js', () => ({ useSetupStore: () => mockSetupStore }))

const templates = [
	{ id: 'kitchen_counters', name: 'Wipe counters', description: 'All of them', energyCost: 2, priority: 2, category: 'kitchen', roomTypes: ['kitchen'], levels: { putzteufel: { frequencyType: 'daily', frequencyValue: 1 }, putzmuffel: null } },
	{ id: 'kitchen_oven', name: 'Clean the oven', energyCost: 4, roomTypes: ['kitchen'], levels: { putzmuffel: { frequencyType: 'monthly', frequencyValue: 2 } } },
	{ id: 'household_trash', name: 'Take out trash', energyCost: 1, roomTypes: ['household'], levels: { putzteufel: { frequencyType: 'weekly', frequencyValue: 1 } } },
	{ id: 'bathroom_toilet', name: 'Clean toilet', energyCost: 2, roomTypes: ['bathroom', 'generic'], levels: { putzteufel: { frequencyType: 'weekly', frequencyValue: 1 } } },
]

/**
 * *From template* of the one form (FEAT-UX-043, form part): a catalog
 * template only fills the form.
 */
describe('TaskTemplateFill', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		mockSetupStore = reactive({
			hasCatalog: true,
			roomTypes: [{ id: 'kitchen', defaultName: 'Kitchen' }, { id: 'household', defaultName: 'Household' }, { id: 'bathroom', defaultName: 'Bathroom' }, { id: 'generic', defaultName: 'Other room' }],
			templates,
			fetchCatalog: jest.fn().mockResolvedValue({}),
		})
	})

	const mountFill = async (props = {}) => {
		const wrapper = mount(TaskTemplateFill, { props })
		await wrapper.get('[data-testid="task-template-open"]').trigger('click')
		await flushPromises()
		return wrapper
	}
	const offered = (wrapper) => wrapper.findAll('[data-testid^="task-template-"]')
		.map((button) => button.attributes('data-testid'))
		.filter((testid) => !['task-template-open', 'task-template-room-type', 'task-template-fill'].includes(testid))

	it('opens and closes with its state on the toggle, without a read when the catalog is there', async () => {
		const wrapper = mount(TaskTemplateFill)
		const toggle = wrapper.get('[data-testid="task-template-open"]')
		expect(toggle.attributes('aria-expanded')).toBe('false')

		await toggle.trigger('click')
		expect(toggle.attributes('aria-expanded')).toBe('true')
		expect(wrapper.get('.template-panel').attributes('id')).toBe(toggle.attributes('aria-controls'))
		expect(mockSetupStore.fetchCatalog).not.toHaveBeenCalled()

		await toggle.trigger('click')
		expect(wrapper.find('[data-testid="task-template-room-type"]').exists()).toBe(false)
	})

	it('offers the templates of the chosen room\'s type first, with the household\'s, and the others by their type', async () => {
		const wrapper = await mountFill({ preferredRoomType: 'kitchen' })
		expect(wrapper.get('[data-testid="task-template-room-type"]').element.value).toBe('kitchen')
		expect(offered(wrapper)).toEqual(['task-template-kitchen_counters', 'task-template-kitchen_oven', 'task-template-household_trash'])

		await wrapper.get('[data-testid="task-template-room-type"]').setValue('household')
		expect(offered(wrapper)).toEqual(['task-template-household_trash'])
	})

	it('follows a room chosen later, and starts with the generic type without one', async () => {
		const wrapper = await mountFill()
		expect(offered(wrapper)).toEqual(['task-template-household_trash', 'task-template-bathroom_toilet'])

		await wrapper.setProps({ preferredRoomType: 'kitchen' })
		expect(offered(wrapper)).toEqual(['task-template-kitchen_counters', 'task-template-kitchen_oven', 'task-template-household_trash'])
	})

	it('says when a type has no templates', async () => {
		mockSetupStore.templates = []

		expect((await mountFill({ preferredRoomType: 'kitchen' })).text()).toContain('No templates for this room type')
	})

	it('previews the rhythm and the energy of a template', async () => {
		const wrapper = await mountFill({ preferredRoomType: 'kitchen' })

		expect(wrapper.get('[data-testid="task-template-kitchen_counters"] .template-preview').text()).toMatch(/· 2 energy$/)
	})

	it('fills the form with a template and closes', async () => {
		const wrapper = await mountFill({ preferredRoomType: 'kitchen' })

		await wrapper.get('[data-testid="task-template-kitchen_counters"]').trigger('click')

		expect(wrapper.emitted('fill')).toEqual([[{
			details: { name: 'Wipe counters', description: 'All of them', instructions: '', energyCost: 2, priority: 2, category: 'kitchen' },
			rhythm: { mode: 'after', every: 1, unit: 'day', weekdays: [], monthDay: null, flexDays: 1 },
		}]])
		expect(wrapper.find('[data-testid="task-template-room-type"]').exists()).toBe(false)
	})

	it('keeps the focus in the form after a pick: on the button that opened the templates', async () => {
		const wrapper = mount(TaskTemplateFill, { props: { preferredRoomType: 'kitchen' }, attachTo: document.body })
		await wrapper.get('[data-testid="task-template-open"]').trigger('click')
		await flushPromises()

		await wrapper.get('[data-testid="task-template-kitchen_counters"]').trigger('click')
		await flushPromises()

		expect(document.activeElement).toBe(wrapper.get('[data-testid="task-template-open"]').element)
		wrapper.unmount()
	})

	it('takes the rhythm of another level when a template has none for the default one', async () => {
		const wrapper = await mountFill({ preferredRoomType: 'kitchen' })

		await wrapper.get('[data-testid="task-template-kitchen_oven"]').trigger('click')

		expect(wrapper.emitted('fill')[0][0].rhythm).toMatchObject({ every: 2, unit: 'month' })
	})

	it('reads a missing catalog once and says when it cannot', async () => {
		mockSetupStore.hasCatalog = false
		mockSetupStore.fetchCatalog.mockRejectedValueOnce(new Error('offline'))

		const wrapper = await mountFill()

		expect(mockSetupStore.fetchCatalog).toHaveBeenCalledTimes(1)
		expect(wrapper.get('[role="alert"]').text()).toBe('Could not load the templates')
		expect(logger.error).toHaveBeenCalledWith('Template catalog load failed', expect.any(Error), expect.objectContaining({ component: 'TaskTemplateFill' }))

		// Opening again is the retry: a read that works now shows the templates.
		mockSetupStore.fetchCatalog.mockImplementationOnce(async () => {
			mockSetupStore.hasCatalog = true
		})
		await wrapper.get('[data-testid="task-template-open"]').trigger('click')
		await wrapper.get('[data-testid="task-template-open"]').trigger('click')
		await flushPromises()

		expect(mockSetupStore.fetchCatalog).toHaveBeenCalledTimes(2)
		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="task-template-room-type"]').exists()).toBe(true)
	})
})
