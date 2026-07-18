import { describe, it, expect, beforeEach } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

// Mock the game store so we can drive the weekly goal snapshot.
const stateRef = { value: null }
jest.mock('../../../src/store/game.js', () => ({
	useGameStore: () => ({
		get weeklyGoal() {
			return stateRef.value
		},
	}),
}))

// Mock Icon to avoid pulling in the SVG machinery.
jest.mock('../../../src/components/Common/Icon.vue', () => ({
	name: 'Icon',
	props: ['name', 'size'],
	template: '<span class="icon-stub" />',
}))

import HouseholdGoalBar from '../../../src/components/Dashboard/HouseholdGoalBar.vue'

describe('HouseholdGoalBar.vue', () => {
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		stateRef.value = {
			dueCount: 0,
			completedCount: 0,
			percent: 0,
			targetPercent: 80,
			achieved: false,
			weekStart: null,
			weekEnd: null,
		}
	})

	it('renders the bar with the configured title', () => {
		const wrapper = mount(HouseholdGoalBar, { global: { plugins: [pinia] } })

		expect(wrapper.find('[data-testid="household-goal-bar"]').exists()).toBe(true)
		expect(wrapper.text()).toMatch(/Weekly Household Goal/)
	})

	it('shows 0% and the empty copy when no activity has happened', () => {
		const wrapper = mount(HouseholdGoalBar, { global: { plugins: [pinia] } })

		expect(wrapper.find('[data-testid="household-goal-percent"]').text()).toBe('0%')
		expect(wrapper.find('[data-testid="household-goal-counts"]').text())
			.toMatch(/0 .* 0/)
		// No achievement badge and no target copy because there's nothing due
		// nor completed yet (the bar avoids misleading the user on a fresh
		// house).
		expect(wrapper.find('[data-testid="household-goal-achieved"]').exists()).toBe(false)
	})

	it('renders percent and counts from the server snapshot', () => {
		stateRef.value = {
			dueCount: 5,
			completedCount: 3,
			percent: 60,
			targetPercent: 80,
			achieved: false,
			weekStart: '2026-07-13T00:00:00+00:00',
			weekEnd: '2026-07-19T23:59:59+00:00',
		}

		const wrapper = mount(HouseholdGoalBar, { global: { plugins: [pinia] } })

		expect(wrapper.find('[data-testid="household-goal-percent"]').text()).toBe('60%')
		expect(wrapper.find('[data-testid="household-goal-counts"]').text())
			.toMatch(/3 .* 5/)
		expect(wrapper.find('[data-testid="household-goal-achieved"]').exists()).toBe(false)
		// Target marker visible because target < 100.
		expect(wrapper.find('[data-testid="goal-target-marker"]').exists()).toBe(true)
	})

	it('renders the achievement badge when the goal is achieved', () => {
		stateRef.value = {
			dueCount: 5,
			completedCount: 5,
			percent: 100,
			targetPercent: 80,
			achieved: true,
			weekStart: '2026-07-13T00:00:00+00:00',
			weekEnd: '2026-07-19T23:59:59+00:00',
		}

		const wrapper = mount(HouseholdGoalBar, { global: { plugins: [pinia] } })

		expect(wrapper.find('[data-testid="household-goal-achieved"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="household-goal-achieved"]').text()).toMatch(/Goal achieved/)
		expect(wrapper.find('.household-goal-bar.achieved').exists()).toBe(true)
	})

	it('omits the target marker when target is 100', () => {
		stateRef.value = {
			dueCount: 2,
			completedCount: 1,
			percent: 50,
			targetPercent: 100,
			achieved: false,
			weekStart: null,
			weekEnd: null,
		}

		const wrapper = mount(HouseholdGoalBar, { global: { plugins: [pinia] } })

		expect(wrapper.find('[data-testid="goal-target-marker"]').exists()).toBe(false)
	})

	it('falls back to defaults when the snapshot is missing', () => {
		stateRef.value = null

		const wrapper = mount(HouseholdGoalBar, { global: { plugins: [pinia] } })

		expect(wrapper.find('[data-testid="household-goal-percent"]').text()).toBe('0%')
		expect(wrapper.find('[data-testid="household-goal-counts"]').text())
			.toMatch(/0 .* 0/)
	})
})
