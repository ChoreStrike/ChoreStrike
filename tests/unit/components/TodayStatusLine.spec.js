import { mount } from '@vue/test-utils'
import TodayStatusLine from '@/components/Today/TodayStatusLine.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, params) => Object.entries(params ?? {})
		.reduce((label, [key, value]) => label.replaceAll(`{${key}}`, String(value)), text)),
}))

describe('TodayStatusLine', () => {
	const mountLine = (props = {}) => mount(TodayStatusLine, { props })
	const value = (wrapper, testId) => wrapper.get(`[data-testid="${testId}"] .status-value`).text()

	it('shows the open work against today\'s energy, the weekly goal, the streak and the coins', () => {
		const wrapper = mountLine({
			energy: { planned: 6, capacity: 8 },
			weeklyGoal: { percent: 62, targetPercent: 80 },
			rhythm: { confirmed: 4, pending: false },
			coinMinor: 12850,
		})

		expect(wrapper.get('[data-testid="today-status-line"]').attributes('aria-label')).toBe('My status')
		expect(value(wrapper, 'today-energy')).toBe('6 of 8')
		expect(value(wrapper, 'today-weekly-goal')).toBe('62%')
		expect(value(wrapper, 'today-streak')).toBe('4 days')
		expect(wrapper.get('[data-testid="own-wallet-available"]').text()).toBe('128.5')
		expect(wrapper.get('[data-testid="today-energy"]').classes()).not.toContain('over')
		expect(wrapper.find('.status-note').exists()).toBe(false)
	})

	it('marks the energy tile when more work is open than the day holds', () => {
		const wrapper = mountLine({ energy: { planned: 9, capacity: 8 } })

		expect(wrapper.get('[data-testid="today-energy"]').classes()).toContain('over')
	})

	it('does not mark a day that is exactly full', () => {
		const wrapper = mountLine({ energy: { planned: 8, capacity: 8 } })

		expect(wrapper.get('[data-testid="today-energy"]').classes()).not.toContain('over')
	})

	it('says that today is still pending for the streak, and counts one day in the singular', () => {
		const wrapper = mountLine({ rhythm: { confirmed: 1, pending: true } })

		expect(value(wrapper, 'today-streak')).toBe('1 day')
		expect(wrapper.get('[data-testid="today-streak"] .status-note').text()).toBe('today pending')
	})

	it('shows a dash, never a zero, for a value no read has delivered', () => {
		const wrapper = mountLine()

		for (const testId of ['today-energy', 'today-weekly-goal', 'today-streak', 'today-coins']) {
			expect(value(wrapper, testId)).toBe('–')
		}
	})

	it('shows real zeros once the reads delivered them', () => {
		const wrapper = mountLine({
			energy: { planned: 0, capacity: 0 },
			weeklyGoal: { percent: 0 },
			rhythm: { confirmed: 0, pending: false },
			coinMinor: 0,
		})

		expect(value(wrapper, 'today-energy')).toBe('0 of 0')
		expect(value(wrapper, 'today-weekly-goal')).toBe('0%')
		expect(value(wrapper, 'today-streak')).toBe('0 days')
		expect(value(wrapper, 'today-coins')).toBe('0')
	})
})
