/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest } from '@jest/globals'
import { mount } from '@vue/test-utils'
import CalendarAgenda from '../../../src/components/Tasks/CalendarAgenda.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? String(vars[key]) : match))),
}))

const day = (over = {}) => ({
	day: '2026-11-25',
	isToday: false,
	isPast: false,
	beyond: false,
	load: null,
	overdue: [],
	entries: [],
	completed: [],
	...over,
})
const entry = (over = {}) => ({
	key: 's-1',
	name: 'Vacuum',
	room: 'Hall',
	energy: 2,
	windowStartOn: '2026-11-25',
	windowEndOn: '2026-11-27',
	overload: false,
	userIds: ['mom'],
	...over,
})
const members = [{ nextcloudUserId: 'mom', displayName: 'Parent' }, { nextcloudUserId: 'kid', displayName: 'Kid' }]
const mountAgenda = (days, props = {}) => mount(CalendarAgenda, { props: { days, members, userId: 'mom', ...props } })

describe('CalendarAgenda (CS-PLAN-091 WP-C)', () => {
	it('renders one labelled section per day and marks today', () => {
		const wrapper = mountAgenda([day({ day: '2026-11-24', isPast: true }), day({ isToday: true })])

		const sections = wrapper.findAll('section')
		expect(sections).toHaveLength(2)
		expect(sections[0].attributes('data-testid')).toBe('calendar-agenda-day-2026-11-24')
		expect(sections[0].attributes('aria-label')).toBeTruthy()
		expect(sections[0].find('.today-badge').exists()).toBe(false)
		expect(sections[1].classes()).toContain('today')
		expect(sections[1].get('.today-badge').text()).toBe('Today')
	})

	it('shows an entry with its energy and the days of its window', () => {
		const wrapper = mountAgenda([day({ entries: [entry(), entry({ key: 's-2', name: 'Bins', windowEndOn: '2026-11-25' })] })])

		const rows = wrapper.findAll('[data-testid="calendar-agenda-entry"]')
		expect(rows[0].text()).toContain('Vacuum')
		expect(rows[0].get('.entry-energy').text()).toContain('2')
		expect(rows[0].get('.entry-note').text()).toMatch(/^\S+–\S+$/)
		expect(rows[1].get('.entry-note').text()).toMatch(/^only \S+$/)
	})

	it('names the other people of an entry, never the viewer alone', () => {
		const wrapper = mountAgenda([day({
			entries: [entry(), entry({ key: 's-2', userIds: ['kid', 'mom'] }), entry({ key: 's-3', userIds: ['kid'] })],
		})])

		const people = wrapper.findAll('[data-testid="calendar-agenda-entry"]').map((row) => row.find('.entry-people').exists() ? row.get('.entry-people').text() : '')
		expect(people).toEqual(['', 'Kid', 'Kid'])
	})

	it('says so when an entry fits no day of its window', () => {
		const wrapper = mountAgenda([day({ entries: [entry({ overload: true })] })])

		const row = wrapper.get('[data-testid="calendar-agenda-entry"]')
		expect(row.classes()).toContain('overload')
		expect(row.get('.entry-note').text()).toBe('does not fit')
	})

	it('lists overdue work first and what was done last', () => {
		const wrapper = mountAgenda([day({
			isToday: true,
			overdue: [{ key: 'o-9', name: 'Bathroom', room: 'Bath', energy: 3, userIds: ['mom'] }],
			entries: [entry()],
			completed: [{ key: 'c-0', name: 'Dishes', room: 'Kitchen' }, { key: 'c-1', name: null, room: 'Kitchen' }],
		})])

		const rows = wrapper.findAll('.entry')
		expect(rows.map((row) => row.attributes('data-testid'))).toEqual(['calendar-agenda-overdue', 'calendar-agenda-entry', 'calendar-agenda-done', 'calendar-agenda-done'])
		expect(rows[0].text()).toContain('Bathroom')
		expect(rows[0].get('.entry-note').text()).toBe('overdue')
		expect(rows[2].get('.entry-note').text()).toBe('done')
		// A task the viewer may not see carries no name.
		expect(rows[3].get('.entry-name').text()).toBe('Unknown task')
	})

	it('shows the placed energy against the day\'s energy and says when it is too much', () => {
		const wrapper = mountAgenda([
			day({ load: { budget: 6, placed: 4, preferred: false, absent: false } }),
			day({ day: '2026-11-26', load: { budget: 6, placed: 8, preferred: true, absent: false } }),
			day({ day: '2026-11-27', load: { budget: 6, placed: 0, preferred: false, absent: true } }),
			day({ day: '2026-11-28' }),
		])

		const fits = wrapper.get('[data-testid="calendar-agenda-load-2026-11-25"]')
		expect(fits.text()).toBe('4/6')
		expect(fits.attributes('aria-label')).toBe('4 of 6 energy placed')
		expect(fits.classes()).not.toContain('over')
		expect(wrapper.findAll('.load-bar span')[0].attributes('style')).toContain('width: 67%')

		const over = wrapper.get('[data-testid="calendar-agenda-load-2026-11-26"]')
		expect(over.classes()).toContain('over')
		expect(over.attributes('aria-label')).toBe('8 of 6 energy placed: more than the day holds')
		expect(wrapper.findAll('.load-bar span')[1].attributes('style')).toContain('width: 100%')
		expect(wrapper.findAll('[data-testid="calendar-agenda-preferred"]')).toHaveLength(1)

		// An absence replaces the load; a day outside the horizon shows none.
		const absent = wrapper.get('[data-testid="calendar-agenda-day-2026-11-27"]')
		expect(absent.text()).toContain('Absent')
		expect(absent.find('.load-bar').exists()).toBe(false)
		expect(wrapper.get('[data-testid="calendar-agenda-day-2026-11-28"]').find('.load-text').exists()).toBe(false)
	})

	// The suggestion places today's work on what is left after the work already done.
	it('counts the energy done today with what is placed on it', () => {
		const wrapper = mountAgenda([
			day({ isToday: true, load: { budget: 6, placed: 4, doneMinor: 250, preferred: false, absent: false } }),
			day({ day: '2026-11-26', load: { budget: 6, placed: 4, doneMinor: 0, preferred: false, absent: false } }),
		])

		const today = wrapper.get('[data-testid="calendar-agenda-load-2026-11-25"]')
		expect(today.text()).toBe('6.5/6')
		expect(today.classes()).toContain('over')
		expect(today.attributes('aria-label')).toBe('6.5 of 6 energy done or placed: more than the day holds')
		expect(wrapper.get('[data-testid="calendar-agenda-load-2026-11-26"]').attributes('aria-label')).toBe('4 of 6 energy placed')
	})

	it('says when a day is empty, in words that fit the day', () => {
		const wrapper = mountAgenda([day({ day: '2026-11-24', isPast: true }), day()])

		expect(wrapper.findAll('.entry.empty').map((row) => row.text())).toEqual(['Nothing done', 'Nothing planned'])
	})

	it('explains the days beyond the suggestion only where the week holds one', () => {
		expect(mountAgenda([day()]).find('[data-testid="calendar-agenda-beyond"]').exists()).toBe(false)

		// The number of days is the server's (RF-091-14), never a copy in the client.
		const beyond = [day(), day({ day: '2026-12-09', beyond: true })]
		const note = mountAgenda(beyond, { horizonDays: 10 }).get('[data-testid="calendar-agenda-beyond"]')
		expect(note.text()).toContain('10 days ahead')
		expect(mountAgenda(beyond).find('[data-testid="calendar-agenda-beyond"]').exists()).toBe(false)
	})
})
