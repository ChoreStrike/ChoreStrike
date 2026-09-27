/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * ActivityFeed component suite (CR-053-22, CS-PLAN-068 §3.6).
 */

import { describe, it, expect } from '@jest/globals'
import { mount } from '@vue/test-utils'

import ActivityFeed from '../../../src/components/Insights/ActivityFeed.vue'

const recentIso = (minutesAgo) => new Date(Date.now() - minutesAgo * 60000).toISOString()

function mountFeed(items) {
	return mount(ActivityFeed, {
		props: { items },
	})
}

describe('ActivityFeed', () => {
	it('renders the empty state when no activity exists', () => {
		const wrapper = mountFeed([])
		expect(wrapper.find('[data-testid="activity-empty"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="activity-item"]').exists()).toBe(false)
	})

	it('renders one row per completed task with actor, task and room', () => {
		const wrapper = mountFeed([
			{ id: 1, displayName: 'Mom', taskTitle: 'Do the dishes', roomName: 'Kitchen', completedAt: recentIso(2), totalEnergy: 2 },
			{ id: 2, displayName: 'Kid', taskTitle: 'Make bed', roomName: '', completedAt: recentIso(5), totalEnergy: 1 },
		])
		const rows = wrapper.findAll('[data-testid="activity-item"]')
		expect(rows).toHaveLength(2)
		expect(rows[0].text()).toContain('Mom')
		expect(rows[0].text()).toContain('Do the dishes')
		expect(rows[0].text()).toContain('Kitchen')
		// Without a room the room fragment is omitted entirely.
		expect(rows[0].find('.activity-room').exists()).toBe(true)
		expect(rows[1].find('.activity-room').exists()).toBe(false)
	})

	it('marks undone rows and falls back for unknown actor/task', () => {
		const wrapper = mountFeed([
			{ id: 1, displayName: '', taskTitle: '', roomName: 'Kitchen', completedAt: recentIso(1), undoneAt: recentIso(0), totalEnergy: 2 },
		])
		const row = wrapper.find('[data-testid="activity-item"]')
		expect(row.classes()).toContain('undone')
		expect(row.text()).toContain('\u21A9')
		expect(row.text()).toContain('Unknown member')
		expect(row.text()).toContain('Unknown task')
	})

	it('renders frozen effort only when the viewer may see it', () => {
		const wrapper = mountFeed([
			{ id: 1, displayName: 'Mom', taskTitle: 'T', roomName: '', completedAt: recentIso(3), totalEnergy: 3 },
			{ id: 2, displayName: 'Dad', taskTitle: null, roomName: '', completedAt: recentIso(4), totalEnergy: null, canCorrect: false },
		])
		expect(wrapper.find('[data-testid="activity-energy-1"]').text()).toContain('3')
		expect(wrapper.find('[data-testid="activity-energy-2"]').exists()).toBe(false)
	})

	describe('WP3 history correction (§3.6)', () => {
		it('offers the correction action only on correctable live rows', () => {
			const wrapper = mountFeed([
				{ id: 1, displayName: 'Mom', taskTitle: 'T', completedAt: recentIso(1), totalEnergy: 2, canCorrect: true },
				{ id: 2, displayName: 'Dad', taskTitle: 'T', completedAt: recentIso(2), totalEnergy: 2, canCorrect: false },
				{ id: 3, displayName: 'Kid', taskTitle: 'T', completedAt: recentIso(3), totalEnergy: 1, canCorrect: true, undoneAt: recentIso(0) },
			])
			expect(wrapper.find('[data-testid="activity-correct-1"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="activity-correct-2"]').exists()).toBe(false)
			// An undone row keeps its history but is not correctable anymore.
			expect(wrapper.find('[data-testid="activity-correct-3"]').exists()).toBe(false)
			expect(wrapper.find('[data-testid="activity-undo-1"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="activity-undo-2"]').exists()).toBe(false)
			expect(wrapper.find('[data-testid="activity-undo-3"]').exists()).toBe(false)
		})

		it('emits the correct event with the item when clicked', async () => {
			const item = { id: 7, displayName: 'Mom', taskTitle: 'T', completedAt: recentIso(1), totalEnergy: 2, canCorrect: true }
			const wrapper = mountFeed([item])
			await wrapper.find('[data-testid="activity-correct-7"]').trigger('click')
			expect(wrapper.emitted('correct')).toHaveLength(1)
			expect(wrapper.emitted('correct')[0]).toEqual([item])
		})

		it('emits a targeted undo and disables stale or in-flight rows', async () => {
			const item = { id: 7, version: 3, canCorrect: true, totalEnergy: 2 }
			const wrapper = mountFeed([item])
			expect(wrapper.find('[data-testid="activity-undo-7"]').attributes('data-version')).toBe('3')
			await wrapper.find('[data-testid="activity-undo-7"]').trigger('click')
			expect(wrapper.emitted('undo')).toEqual([[item]])
			await wrapper.setProps({ busyId: 7 })
			expect(wrapper.find('[data-testid="activity-undo-7"]').attributes('disabled')).toBeDefined()
			await wrapper.setProps({ busyId: null, blockedIds: [7] })
			expect(wrapper.find('[data-testid="activity-undo-7"]').attributes('disabled')).toBeDefined()
		})
	})

	describe('relative date buckets', () => {
		const feed = (minutesAgo) => mountFeed([
			{ id: 1, displayName: 'M', taskTitle: 'T', completedAt: recentIso(minutesAgo) },
		]).find('.activity-time').text()

		it('shows "just now" under a minute', () => {
			expect(feed(0.2)).toContain('just now')
		})

		it('shows minutes under an hour', () => {
			expect(feed(5)).toContain('min ago')
		})

		it('shows hours under a day', () => {
			expect(feed(120)).toContain('h ago')
		})

		it('shows days under a week', () => {
			expect(feed(3 * 24 * 60)).toContain('d ago')
		})

		it('falls back to the absolute date beyond a week', () => {
			expect(feed(10 * 24 * 60)).not.toContain('ago')
		})

		it('renders nothing for a missing date', () => {
			const wrapper = mountFeed([
				{ id: 1, displayName: 'M', taskTitle: 'T', completedAt: null },
			])
			expect(wrapper.find('.activity-time').text()).toBe('')
		})
	})
})
