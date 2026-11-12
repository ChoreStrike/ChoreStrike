/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * ActivityFeed component suite (CR-053-22, CS-PLAN-068 §3.6; one line per
 * entry and day groups since CS-PLAN-090 WP-E).
 */

import { describe, it, expect, jest, afterEach } from '@jest/globals'
import { mount } from '@vue/test-utils'

import ActivityFeed from '../../../src/components/Insights/ActivityFeed.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: jest.fn((app, text) => text) }))

// A fixed "now": 12 November 2026, 10:00 UTC.
const NOW = new Date('2026-11-12T10:00:00Z')
const at = (iso) => iso

function mountFeed(items, props = {}) {
	jest.useFakeTimers()
	jest.setSystemTime(NOW)
	return mount(ActivityFeed, { props: { items, timezone: 'UTC', ...props } })
}
const row = (wrapper, id) => wrapper.get(`[data-testid="activity-row-${id}"]`)
const share = (userId, coinMinor, sharePct = 100) => ({ userId, displayName: userId, sharePct, energyMinor: coinMinor / 10, coinMinor })

describe('ActivityFeed', () => {
	afterEach(() => {
		jest.useRealTimers()
	})

	it('renders the empty state when no activity exists', () => {
		const wrapper = mountFeed([])
		expect(wrapper.find('[data-testid="activity-empty"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="activity-item"]').exists()).toBe(false)
	})

	it('shows one line per completion: time, person, task and the coins it paid', () => {
		const wrapper = mountFeed([
			{ id: 1, displayName: 'Mom', taskTitle: 'Do the dishes', roomName: 'Kitchen', completedAt: at('2026-11-12T08:10:00Z'), totalEnergy: 3, allocation: [share('mom', 3000)] },
		])

		const line = row(wrapper, 1)
		expect(line.find('.activity-time').text()).toMatch(/^0?8:10/)
		expect(line.find('.activity-actor').text()).toBe('Mom')
		expect(line.find('.activity-task').text()).toBe('Do the dishes')
		expect(line.get('[data-testid="activity-coins-1"]').text()).toBe('+30')
		// The room, the energy and the corrections wait behind a tap.
		expect(line.text()).not.toContain('Kitchen')
		expect(wrapper.find('[data-testid="activity-details-1"]').exists()).toBe(false)
	})

	it('adds up the coins of every share of a split completion', () => {
		const wrapper = mountFeed([
			{ id: 1, displayName: 'Mom', taskTitle: 'T', completedAt: at('2026-11-12T08:00:00Z'), totalEnergy: 4, allocation: [share('mom', 2400, 60), share('kid', 1600, 40)] },
		])

		expect(wrapper.get('[data-testid="activity-coins-1"]').text()).toBe('+40')
	})

	it('groups the lines by the household\'s day, today and yesterday by name', () => {
		const wrapper = mountFeed([
			{ id: 1, displayName: 'A', taskTitle: 'T1', completedAt: at('2026-11-12T08:00:00Z') },
			{ id: 2, displayName: 'B', taskTitle: 'T2', completedAt: at('2026-11-12T00:30:00Z') },
			{ id: 3, displayName: 'C', taskTitle: 'T3', completedAt: at('2026-11-11T23:30:00Z') },
			{ id: 4, displayName: 'D', taskTitle: 'T4', completedAt: at('2026-11-05T12:00:00Z') },
		])

		const days = wrapper.findAll('.activity-day')
		expect(days.map((day) => day.findAll('[data-testid="activity-item"]').length)).toEqual([2, 1, 1])
		const labels = wrapper.findAll('[data-testid="activity-day"]').map((heading) => heading.text())
		expect(labels.slice(0, 2)).toEqual(['Today', 'Yesterday'])
		expect(labels[2]).toMatch(/5/)
	})

	it('reads day and time in the household\'s timezone, not the browser\'s', () => {
		// 00:30 UTC on the 12th is still the 11th in Los Angeles (16:30).
		const wrapper = mountFeed([
			{ id: 2, displayName: 'B', taskTitle: 'T2', completedAt: at('2026-11-12T00:30:00Z') },
		], { timezone: 'America/Los_Angeles' })

		// "Now" is 02:00 on the 12th there, so the entry was yesterday.
		expect(wrapper.get('[data-testid="activity-day"]').text()).toBe('Yesterday')
		expect(row(wrapper, 2).find('.activity-time').text()).toMatch(/^0?4:30/)
	})

	it('marks undone lines, pays them no coins, and falls back for an unknown actor or task', () => {
		const wrapper = mountFeed([
			{ id: 1, displayName: '', taskTitle: '', roomName: 'Kitchen', completedAt: at('2026-11-12T08:00:00Z'), undoneAt: at('2026-11-12T08:05:00Z'), totalEnergy: 2, allocation: [share('mom', 2000)] },
		])

		const item = wrapper.get('[data-testid="activity-item"]')
		expect(item.classes()).toContain('undone')
		expect(item.text()).toContain('↩')
		expect(item.text()).toContain('Unknown member')
		expect(item.text()).toContain('Unknown task')
		expect(wrapper.find('[data-testid="activity-coins-1"]').exists()).toBe(false)
	})

	it('shows neither coins nor effort for work the viewer may not see', async () => {
		const wrapper = mountFeed([
			{ id: 2, displayName: 'Dad', taskTitle: null, completedAt: at('2026-11-12T08:00:00Z'), totalEnergy: null, allocation: [], canCorrect: false },
		])

		expect(wrapper.find('[data-testid="activity-coins-2"]').exists()).toBe(false)
		await row(wrapper, 2).trigger('click')
		expect(wrapper.find('[data-testid="activity-energy-2"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="activity-correct-2"]').exists()).toBe(false)
	})

	it('opens the details of one line at a time: room, frozen effort and who did how much', async () => {
		const wrapper = mountFeed([
			{ id: 1, displayName: 'Mom', taskTitle: 'T1', roomName: 'Kitchen', completedAt: at('2026-11-12T08:00:00Z'), totalEnergy: 4, allocation: [share('Mom', 2400, 60), share('Kid', 1600, 40)] },
			{ id: 2, displayName: 'Kid', taskTitle: 'T2', roomName: '', completedAt: at('2026-11-12T07:00:00Z'), totalEnergy: 1, allocation: [share('Kid', 1000)] },
		])

		await row(wrapper, 1).trigger('click')
		expect(row(wrapper, 1).attributes('aria-expanded')).toBe('true')
		const details = wrapper.get('[data-testid="activity-details-1"]')
		expect(details.text()).toContain('Kitchen')
		expect(details.get('[data-testid="activity-energy-1"]').text()).toContain('4')
		expect(details.text()).toContain('Mom 60%')
		expect(details.text()).toContain('Kid 40%')

		await row(wrapper, 2).trigger('click')
		expect(wrapper.find('[data-testid="activity-details-1"]').exists()).toBe(false)
		expect(row(wrapper, 1).attributes('aria-expanded')).toBe('false')
		// Without a room the room fragment is omitted entirely.
		expect(wrapper.get('[data-testid="activity-details-2"]').find('.activity-room').exists()).toBe(false)

		await row(wrapper, 2).trigger('click')
		expect(wrapper.find('[data-testid="activity-details-2"]').exists()).toBe(false)
	})

	describe('WP3 history correction (§3.6), behind a tap on the line', () => {
		const items = () => [
			{ id: 1, displayName: 'Mom', taskTitle: 'T', completedAt: at('2026-11-12T08:00:00Z'), totalEnergy: 2, canCorrect: true },
			{ id: 2, displayName: 'Dad', taskTitle: 'T', completedAt: at('2026-11-12T07:00:00Z'), totalEnergy: 2, canCorrect: false },
			{ id: 3, displayName: 'Kid', taskTitle: 'T', completedAt: at('2026-11-12T06:00:00Z'), totalEnergy: 1, canCorrect: true, undoneAt: at('2026-11-12T06:05:00Z') },
		]
		const actions = async (wrapper, id) => {
			await row(wrapper, id).trigger('click')
			return ['correct', 'undo'].map((action) => wrapper.find(`[data-testid="activity-${action}-${id}"]`).exists())
		}

		it('shows no correction on a closed line', () => {
			const wrapper = mountFeed(items())
			expect(wrapper.find('[data-testid="activity-correct-1"]').exists()).toBe(false)
			expect(wrapper.find('[data-testid="activity-undo-1"]').exists()).toBe(false)
		})

		it('offers both corrections only on a correctable, still-valid line', async () => {
			const wrapper = mountFeed(items())
			expect(await actions(wrapper, 1)).toEqual([true, true])
			expect(await actions(wrapper, 2)).toEqual([false, false])
			// An undone line keeps its history but is not correctable anymore.
			expect(await actions(wrapper, 3)).toEqual([false, false])
		})

		it('emits the correction with its item', async () => {
			const item = { id: 7, displayName: 'Mom', taskTitle: 'T', completedAt: at('2026-11-12T08:00:00Z'), totalEnergy: 2, canCorrect: true }
			const wrapper = mountFeed([item])
			await row(wrapper, 7).trigger('click')
			await wrapper.get('[data-testid="activity-correct-7"]').trigger('click')
			expect(wrapper.emitted('correct')).toEqual([[item]])
		})

		it('emits a targeted undo and disables stale or in-flight lines', async () => {
			const item = { id: 7, version: 3, canCorrect: true, totalEnergy: 2, completedAt: at('2026-11-12T08:00:00Z') }
			const wrapper = mountFeed([item])
			await row(wrapper, 7).trigger('click')
			expect(wrapper.get('[data-testid="activity-undo-7"]').attributes('data-version')).toBe('3')
			await wrapper.get('[data-testid="activity-undo-7"]').trigger('click')
			expect(wrapper.emitted('undo')).toEqual([[item]])
			await wrapper.setProps({ busyId: 7 })
			expect(wrapper.get('[data-testid="activity-undo-7"]').attributes('disabled')).toBeDefined()
			await wrapper.setProps({ busyId: null, blockedIds: [7] })
			expect(wrapper.get('[data-testid="activity-undo-7"]').attributes('disabled')).toBeDefined()
			expect(wrapper.get('[data-testid="activity-correct-7"]').attributes('disabled')).toBeDefined()
		})
	})

	it('renders no time for a missing date', () => {
		const wrapper = mountFeed([{ id: 1, displayName: 'M', taskTitle: 'T', completedAt: null }])
		expect(row(wrapper, 1).find('.activity-time').text()).toBe('')
	})
})
