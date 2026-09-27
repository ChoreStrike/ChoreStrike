/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * MemberFairnessTable component suite (CR-053-22; WP3 reshaped).
 * Pins the fairness invariants after the CS-PLAN-070 WP3 cutover: the
 * exact contributionMinor column, no Coin/freeze columns (money is the
 * wallet, freezes are retired), and capacity sums only available days.
 */

import { describe, it, expect } from '@jest/globals'
import { mount } from '@vue/test-utils'

import MemberFairnessTable from '../../../src/components/Insights/MemberFairnessTable.vue'

const FULL_WEEK = {
	energyBudget: { monday: 10, tuesday: 10, wednesday: 10, thursday: 10, friday: 10, saturday: 15, sunday: 15 },
	weekdayAvailability: { monday: true, tuesday: true, wednesday: true, thursday: true, friday: true, saturday: true, sunday: true },
}

function mountTable(members, completedEnergyByUser = {}) {
	return mount(MemberFairnessTable, {
		props: { members, completedEnergyByUser },
	})
}

const MEMBER = {
	id: 7,
	nextcloudUserId: 'mom',
	displayName: 'Mom',
	contributionMinor: 12000,
	currentStreak: 5,
	schedulingPreferences: FULL_WEEK,
}

describe('MemberFairnessTable', () => {
	it('renders one row per member with the exact contributionMinor column', () => {
		const wrapper = mountTable([MEMBER])
		expect(wrapper.find('[data-testid="member-fairness-table"]').exists()).toBe(true)
		expect(wrapper.findAll('[data-testid="fairness-row"]')).toHaveLength(1)

		expect(wrapper.find('[data-testid="header-contribution"]').exists()).toBe(true)
		// WP3: exact minor units, 100 = 1 energy → 12000 renders as 120.
		expect(wrapper.find('[data-testid="contribution-7"]').text()).toBe('120')
		// Coins/freezes are gone — money is the wallet, pauses replaced
		// freezes (decision D8/D4b).
		expect(wrapper.find('[data-testid="header-coins"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="freezes-7"]').exists()).toBe(false)
	})

	it('sums the weekly capacity only over available days', () => {
		const weekendOff = {
			...MEMBER,
			schedulingPreferences: {
				energyBudget: FULL_WEEK.energyBudget,
				weekdayAvailability: { ...FULL_WEEK.weekdayAvailability, saturday: false, sunday: false },
			},
		}
		const wrapper = mountTable([weekendOff])
		// 5 × 10 energy; the two unavailable weekend days (15 each) are excluded.
		expect(wrapper.find('[data-testid="load-7"] .load-label').text()).toContain('0 / 50')
	})

	it('uses the documented 10/15 defaults for unset energy budgets', () => {
		const noBudgets = { ...MEMBER, schedulingPreferences: { weekdayAvailability: { ...FULL_WEEK.weekdayAvailability } } }
		const wrapper = mountTable([noBudgets])
		// 5 × 10 + 2 × 15 = 80.
		expect(wrapper.find('[data-testid="load-7"] .load-label').text()).toContain('0 / 80')
	})

	it('maps completed energy per user id into the load cell', () => {
		const wrapper = mountTable([MEMBER], { 7: 25 })
		expect(wrapper.find('[data-testid="load-7"] .load-label').text()).toContain('25 / 80')
	})

	it('renders no freeze badge anymore (retired with the WP3 cutover)', () => {
		const wrapper = mountTable([MEMBER])
		expect(wrapper.find('[data-testid="active-freeze-badge"]').exists()).toBe(false)
	})

	it('marks overload bars and falls back to the user id as name', () => {
		const overloaded = { ...MEMBER, displayName: '' }
		const wrapper = mountTable([overloaded], { 7: 160 })
		expect(wrapper.find('.member-name').text()).toBe('mom')
		expect(wrapper.find('[data-testid="load-7"] .load-bar').classes()).toContain('over')
	})
})
