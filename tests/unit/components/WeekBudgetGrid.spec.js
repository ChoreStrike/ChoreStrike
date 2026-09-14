/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * WeekBudgetGrid component suite (CR-053-22, CS-PLAN-068 §3.6). The grid
 * emits ONLY the edited day's delta (never a stale full-budget snapshot —
 * CS-PLAN-065 fast-typing fix).
 */

import { describe, it, expect } from '@jest/globals'
import { mount } from '@vue/test-utils'

import WeekBudgetGrid from '../../../src/components/Scheduling/WeekBudgetGrid.vue'

const FULL_BUDGET = { monday: 10, tuesday: 10, wednesday: 10, thursday: 10, friday: 10, saturday: 15, sunday: 15 }

function mountGrid(budget = FULL_BUDGET, disabled = false) {
	return mount(WeekBudgetGrid, {
		props: { budget, disabled },
	})
}

describe('WeekBudgetGrid', () => {
	it('renders exactly seven day cells with slider and number input', () => {
		const wrapper = mountGrid()
		expect(wrapper.findAll('.budget-item')).toHaveLength(7)
		expect(wrapper.findAll('[data-testid^="energy-slider-"]')).toHaveLength(7)
		expect(wrapper.findAll('[data-testid^="energy-input-"]')).toHaveLength(7)
	})

	it('binds the current budget value into both controls', () => {
		const wrapper = mountGrid()
		expect(wrapper.find('[data-testid="energy-slider-monday"]').element.value).toBe('10')
		expect(wrapper.find('[data-testid="energy-input-saturday"]').element.value).toBe('15')
	})

	it('emits only the edited day delta from the slider', async () => {
		const wrapper = mountGrid()
		await wrapper.find('[data-testid="energy-slider-wednesday"]').setValue('7')
		const events = wrapper.emitted('update-day')
		expect(events).toHaveLength(1)
		expect(events[0][0]).toEqual({ key: 'wednesday', value: 7 })
	})

	it('emits only the edited day delta from the number input', async () => {
		const wrapper = mountGrid()
		await wrapper.find('[data-testid="energy-input-friday"]').setValue('12')
		const events = wrapper.emitted('update-day')
		expect(events).toHaveLength(1)
		expect(events[0][0]).toEqual({ key: 'friday', value: 12 })
	})

	it('propagates the disabled flag to both controls', () => {
		const wrapper = mountGrid(FULL_BUDGET, true)
		expect(wrapper.find('[data-testid="energy-slider-monday"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="energy-input-monday"]').attributes('disabled')).toBeDefined()
	})

	it('renders a degenerate midpoint cell without exploding on a missing budget entry', () => {
		const wrapper = mountGrid({})
		// A range input bound to `undefined` falls back to the jsdom
		// midpoint of the 1..20 scale — the grid must not crash.
		expect(wrapper.find('[data-testid="energy-slider-monday"]').element.value).toBe('10.5')
	})
})
