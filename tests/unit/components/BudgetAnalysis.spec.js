/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { mount } from '@vue/test-utils'
import BudgetAnalysis from '../../../src/components/Scheduling/BudgetAnalysis.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

describe('BudgetAnalysis', () => {
	const mountWith = (budget) => mount(BudgetAnalysis, {
		props: { budget },
	})

	it('renders three analysis cards', () => {
		const wrapper = mountWith({ monday: 10 })
		expect(wrapper.findAll('.analysis-card')).toHaveLength(3)
	})

	it('computes the average daily energy from the passed budget', () => {
		const budget = {
			monday: 10, tuesday: 10, wednesday: 10, thursday: 10,
			friday: 10, saturday: 15, sunday: 15,
		}
		const wrapper = mountWith(budget)
		expect(wrapper.text()).toContain('11')
	})

	it('shows the peak day short label', () => {
		const budget = {
			monday: 3, tuesday: 5, wednesday: 5, thursday: 5,
			friday: 5, saturday: 20, sunday: 4,
		}
		const wrapper = mountWith(budget)
		const values = wrapper.findAll('.card-value').map(node => node.text())
		expect(values[1]).toBe('Sat')
	})

	it('counts low energy days (<= 5)', () => {
		const budget = {
			monday: 2, tuesday: 5, wednesday: 9, thursday: 9,
			friday: 9, saturday: 1, sunday: 9,
		}
		const wrapper = mountWith(budget)
		const values = wrapper.findAll('.card-value').map(node => node.text())
		expect(values[2]).toBe('3')
	})
})
