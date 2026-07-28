import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import SchedulingSuggestions from '../../../src/components/Scheduling/SchedulingSuggestions.vue'
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

describe('SchedulingSuggestions', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
	})

	const mountComponent = () => {
		return mount(SchedulingSuggestions)
	}

	it('renders with data-testid', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('[data-testid="scheduling-suggestions"]').exists()).toBe(true)
	})

	it('shows empty state initially', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('.empty-state').exists()).toBe(true)
	})

	it('renders load suggestions button', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('[data-testid="load-suggestions-button"]').exists()).toBe(true)
	})

	it('renders header with title', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('h2').text()).toBe('Smart Scheduling Suggestions')
	})

	it('refreshes suggestions via the store with active tasks', async () => {
		// Real backend shape: an object keyed by weekday (not an array).
		api.post.mockResolvedValueOnce({
			monday: { availableMembers: 2, totalEnergy: 20, recommendedTasks: 4 },
			wednesday: { availableMembers: 2, totalEnergy: 18, recommendedTasks: 3 },
		})

		const wrapper = mountComponent()
		await wrapper.find('[data-testid="load-suggestions-button"]').trigger('click')
		await flushPromises()

		expect(api.post).toHaveBeenCalledWith('/api/v1/scheduling/suggestions', { tasks: [] })
		// The weekday-keyed payload is normalized to two rendered cards.
		expect(wrapper.findAll('.suggestion-card')).toHaveLength(2)
	})
})
