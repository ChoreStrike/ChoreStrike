import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import SchedulePreview from '../../../src/components/Scheduling/SchedulePreview.vue'
import { api } from '../../../src/services/api.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

jest.mock('@nextcloud/dialogs', () => ({
	showSuccess: jest.fn(),
	showError: jest.fn(),
}))

jest.mock('../../../src/services/api.js', () => ({
	api: {
		get: jest.fn(),
		put: jest.fn(),
		post: jest.fn(),
	},
}))

const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('SchedulePreview', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
	})

	const mountComponent = () => {
		return mount(SchedulePreview)
	}

	it('renders with data-testid', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('[data-testid="schedule-preview"]').exists()).toBe(true)
	})

	it('shows empty state initially', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('.empty-state').exists()).toBe(true)
	})

	it('renders load preview button', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('[data-testid="load-preview-button"]').exists()).toBe(true)
	})

	it('renders generate button in header', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('[data-testid="generate-schedule-button"]').exists()).toBe(true)
	})

	it('loads suggestions via the store with active tasks', async () => {
		// Real backend shape: an object keyed by weekday (not an array).
		api.post.mockResolvedValueOnce({
			monday: { availableMembers: 2, totalEnergy: 20, recommendedTasks: 4 },
			saturday: { availableMembers: 1, totalEnergy: 15, recommendedTasks: 3 },
		})

		const wrapper = mountComponent()
		await wrapper.find('[data-testid="generate-schedule-button"]').trigger('click')
		await flushPromises()

		// The store posts the active task list (here empty) — not member data.
		expect(api.post).toHaveBeenCalledWith('/api/v1/scheduling/suggestions', { tasks: [] })
		// The weekday-keyed payload is normalized to two rendered suggestions.
		expect(wrapper.findAll('.suggestion-item')).toHaveLength(2)
	})
})
