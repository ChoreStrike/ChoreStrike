/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * MemberFairnessTable component suite (CR-053-22, CS-PLAN-068 §3.6).
 * Pins the fairness invariants: contribution and coins stay SEPARATE
 * columns (energy-and-balancing.md), capacity sums only available days and
 * the frozen badge appears only for a YESTERDAY freeze consumption.
 */

import { describe, it, expect } from '@jest/globals'
import { mount } from '@vue/test-utils'

import MemberFairnessTable from '../../../src/components/Insights/MemberFairnessTable.vue'

const FULL_WEEK = {
	energyBudget: { monday: 10, tuesday: 10, wednesday: 10, thursday: 10, friday: 10, saturday: 15, sunday: 15 },
	weekdayAvailability: { monday: true, tuesday: true, wednesday: true, thursday: true, friday: true, saturday: true, sunday: true },
}

function yesterdayKey() {
	const yesterday = new Date()
	yesterday.setDate(yesterday.getDate() - 1)
	return `${yesterday.getFullYear()}-${String(yesterday.getMonth() + 1).padStart(2, '0')}-${String(yesterday.getDate()).padStart(2, '0')}`
}

function twoDaysAgoKey() {
	const day = new Date()
	day.setDate(day.getDate() - 2)
	return `${day.getFullYear()}-${String(day.getMonth() + 1).padStart(2, '0')}-${String(day.getDate()).padStart(2, '0')}`
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
	contributionScore: 120,
	coins: 340,
	currentStreak: 5,
	streakFreezes: 1,
	schedulingPreferences: FULL_WEEK,
	lastFreezeDate: null,
}

describe('MemberFairnessTable', () => {
	it('renders one row per member with separate contribution and coins columns', () => {
		const wrapper = mountTable([MEMBER])
		expect(wrapper.find('[data-testid="member-fairness-table"]').exists()).toBe(true)
		expect(wrapper.findAll('[data-testid="fairness-row"]')).toHaveLength(1)

		expect(wrapper.find('[data-testid="header-contribution"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="header-coins"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="contribution-7"]').text()).toBe('120')
		expect(wrapper.find('[data-testid="coins-7"]').text()).toBe('340')
		// The two metrics must never merge into one cell.
		expect(wrapper.find('[data-testid="contribution-7"]').text()).not.toBe(wrapper.find('[data-testid="coins-7"]').text())
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

	it('shows the frozen badge only for a yesterday freeze consumption', () => {
		const frozenYesterday = { ...MEMBER, lastFreezeDate: `${yesterdayKey()} 08:00:00` }
		const frozenEarlier = { ...MEMBER, lastFreezeDate: `${twoDaysAgoKey()} 08:00:00` }
		const wrapper = mountTable([frozenYesterday, frozenEarlier])
		const rows = wrapper.findAll('[data-testid="fairness-row"]')
		expect(rows[0].find('[data-testid="active-freeze-badge"]').exists()).toBe(true)
		expect(rows[1].find('[data-testid="active-freeze-badge"]').exists()).toBe(false)
	})

	it('marks overload bars and falls back to the user id as name', () => {
		const overloaded = { ...MEMBER, displayName: '' }
		const wrapper = mountTable([overloaded], { 7: 160 })
		expect(wrapper.find('.member-name').text()).toBe('mom')
		expect(wrapper.find('[data-testid="load-7"] .load-bar').classes()).toContain('over')
	})
})
