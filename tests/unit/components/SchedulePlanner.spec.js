/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import SchedulePlanner from '../../../src/components/Scheduling/SchedulePlanner.vue'
import { useSchedulingStore } from '../../../src/store/scheduling.js'
import { useTasksStore } from '../../../src/store/tasks.js'
import { api } from '../../../src/services/api.js'
import { showSuccess, showError } from '@nextcloud/dialogs'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, vars) => {
		if (!vars) return text
		return text.replace(/\{(\w+)\}/g, (match, key) => Object.prototype.hasOwnProperty.call(vars, key) ? String(vars[key]) : match)
	}),
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

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('SchedulePlanner (merged Preview + Suggestions)', () => {
	let store
	let tasksStore

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSchedulingStore()
		tasksStore = useTasksStore()
		jest.clearAllMocks()
	})

	const mountComponent = () => mount(SchedulePlanner)

	const loadWithPayload = async (payload) => {
		tasksStore.tasks.push({ id: 1, name: 'A', energyCost: 3 })
		api.post.mockResolvedValueOnce(payload)
		const wrapper = mountComponent()
		await wrapper.find('[data-testid="generate-schedule-button"]').trigger('click')
		await flushPromises()
		return wrapper
	}

	it('renders the merged surface with the stable preview testid', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('[data-testid="schedule-planner"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="generate-schedule-button"]').exists()).toBe(true)
	})

	it('shows the empty state before the first load', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('.empty-state').exists()).toBe(true)
		expect(wrapper.find('[data-testid="load-preview-button"]').exists()).toBe(true)
	})

	it('posts only { taskCount } and renders one actionable suggestion per weekday', async () => {
		const wrapper = await loadWithPayload({
			monday: { availableMembers: 2, totalEnergy: 20, recommendedTasks: 4 },
			saturday: { availableMembers: 1, totalEnergy: 15, recommendedTasks: 3 },
		})

		expect(api.post).toHaveBeenCalledWith('/api/v1/scheduling/suggestions', { taskCount: 1 })
		expect(wrapper.findAll('.suggestion-item')).toHaveLength(2)
		expect(wrapper.find('[data-testid="scheduling-suggestions"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="apply-suggestion-monday"]').exists()).toBe(true)
	})

	it('previews the personal share before persisting', async () => {
		const wrapper = await loadWithPayload({
			monday: { availableMembers: 2, totalEnergy: 20, recommendedTasks: 4 },
		})

		// 20 energy / 2 members → 10.
		expect(wrapper.find('[data-testid="share-preview-monday"]').text()).toContain('Sets your Monday budget to 10 energy')
	})

	it('applies a suggestion with one bulk PUT carrying both complete weeks', async () => {
		store.memberAvailability = [
			{ userId: 'a', weekdayAvailability: { monday: true }, energyBudget: { monday: 8 } },
			{ userId: 'b', weekdayAvailability: { monday: true }, energyBudget: { monday: 12 } },
		]
		store.weekdayAvailability.friday = false
		store.energyBudget.tuesday = 7
		api.put.mockResolvedValue({})

		const wrapper = await loadWithPayload({
			friday: { availableMembers: 2, totalEnergy: 24, recommendedTasks: 4 },
		})

		await wrapper.find('[data-testid="apply-suggestion-friday"]').trigger('click')
		await flushPromises()

		expect(api.put).toHaveBeenCalledTimes(1)
		expect(api.put).toHaveBeenCalledWith('/api/v1/scheduling/preferences', {
			weekdayAvailability: {
				monday: true, tuesday: true, wednesday: true, thursday: true,
				// Applying makes the target day available again.
				friday: true, saturday: true, sunday: true,
			},
			energyBudget: {
				monday: 10,
				tuesday: 7,
				wednesday: 10,
				thursday: 10,
				// 24 energy / 2 members → 12.
				friday: 12,
				saturday: 15,
				sunday: 15,
			},
		})
		expect(showSuccess).toHaveBeenCalled()
	})

	it('disables Apply when the suggested day has no energy', async () => {
		const wrapper = await loadWithPayload({
			monday: { availableMembers: 0, totalEnergy: 0, recommendedTasks: 0 },
		})

		expect(wrapper.find('[data-testid="apply-suggestion-monday"]').attributes('disabled')).toBeDefined()
	})

	it('shows an error toast and keeps state when loading fails', async () => {
		api.post.mockRejectedValueOnce(new Error('boom'))

		const wrapper = mountComponent()
		await wrapper.find('[data-testid="generate-schedule-button"]').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalled()
		expect(wrapper.find('[data-testid="scheduling-suggestions"]').exists()).toBe(false)
	})
})
