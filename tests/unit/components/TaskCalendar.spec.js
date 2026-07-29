import { mount } from '@vue/test-utils'
import TaskCalendar from '../../../src/components/Tasks/TaskCalendar.vue'
import CalendarDayCell from '../../../src/components/Tasks/CalendarDayCell.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

describe('TaskCalendar', () => {
	const defaultProps = {
		mode: 'week',
		days: ['2026-07-28', '2026-07-29', '2026-07-30', '2026-07-31', '2026-08-01', '2026-08-02', '2026-08-03'],
		tasksByDay: {
			'2026-07-28': {
				scheduled: [{ roomTask: { id: 1, nextDue: '2026-07-28' }, task: { name: 'Dishes' }, room: { name: 'Kitchen' } }],
				completed: [],
			},
		},
	}

	it('renders week grid with 7 day cells', () => {
		const wrapper = mount(TaskCalendar, { props: defaultProps })
		expect(wrapper.find('[data-testid="task-calendar"]').exists()).toBe(true)
		expect(wrapper.findAllComponents(CalendarDayCell)).toHaveLength(7)
	})

	it('renders month grid with 30 day cells', () => {
		const days = Array.from({ length: 30 }, (_, i) => {
			const d = new Date(2026, 6, 28 + i)
			return d.toISOString().slice(0, 10)
		})
		const wrapper = mount(TaskCalendar, { props: { ...defaultProps, mode: 'month', days } })
		expect(wrapper.findAllComponents(CalendarDayCell)).toHaveLength(30)
	})

	it('emits select-day when a day cell is clicked', async () => {
		const wrapper = mount(TaskCalendar, { props: defaultProps })
		const cell = wrapper.find('[data-testid="calendar-day-2026-07-28"]')
		await cell.trigger('click')
		expect(wrapper.emitted('select-day')).toBeTruthy()
		expect(wrapper.emitted('select-day')[0]).toEqual(['2026-07-28'])
	})

	it('passes tasksByDay data to day cells', () => {
		const wrapper = mount(TaskCalendar, { props: defaultProps })
		const cell = wrapper.find('[data-testid="calendar-day-2026-07-28"]')
		expect(cell.find('[data-testid="calendar-day-count-2026-07-28"]').text()).toBe('1')
	})
})
