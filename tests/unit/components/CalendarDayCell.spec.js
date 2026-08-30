import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import CalendarDayCell from '@/components/Tasks/CalendarDayCell.vue'
import { useHouseStore } from '../../../src/store/house.js'
import { todayKeyInZone } from '../../../src/utils/dateKeys.js'

describe('CalendarDayCell', () => {
	// CS-PLAN-057: the cell compares against the house-local "today" key.
	// These specs pin a UTC house so the key is deterministic on any host.
	let today

	beforeEach(() => {
		setActivePinia(createPinia())
		useHouseStore().house = { id: 1, timezone: 'UTC' }
		today = todayKeyInZone('UTC')
	})

	it('renders day label', () => {
		const wrapper = mount(CalendarDayCell, {
			props: { day: '2026-07-30', data: { scheduled: [], completed: [] } },
		})
		expect(wrapper.text()).toContain('30')
	})

	it('shows task count badge', () => {
		const wrapper = mount(CalendarDayCell, {
			props: {
				day: '2026-07-30',
				data: { scheduled: [{ id: 1 }, { id: 2 }], completed: [{ id: 3 }] },
			},
		})
		expect(wrapper.text()).toContain('3')
	})

	it('hides badge when no tasks', () => {
		const wrapper = mount(CalendarDayCell, {
			props: { day: '2026-07-30', data: { scheduled: [], completed: [] } },
		})
		expect(wrapper.find('.task-count').exists()).toBe(false)
	})

	it('adds is-today class for today', () => {
		const wrapper = mount(CalendarDayCell, {
			props: { day: today, data: { scheduled: [], completed: [] } },
		})
		expect(wrapper.find('.is-today').exists()).toBe(true)
	})

	it('does not add is-today class for other days', () => {
		const wrapper = mount(CalendarDayCell, {
			props: { day: '2020-01-01', data: { scheduled: [], completed: [] } },
		})
		expect(wrapper.find('.is-today').exists()).toBe(false)
	})

	it('emits select-day on click', async () => {
		const wrapper = mount(CalendarDayCell, {
			props: { day: '2026-07-30', data: { scheduled: [], completed: [] } },
		})
		await wrapper.trigger('click')
		expect(wrapper.emitted('select-day')).toBeTruthy()
		expect(wrapper.emitted('select-day')[0]).toEqual(['2026-07-30'])
	})

	it('adds has-tasks class when tasks present', () => {
		const wrapper = mount(CalendarDayCell, {
			props: { day: '2026-07-30', data: { scheduled: [{ id: 1 }], completed: [] } },
		})
		expect(wrapper.find('.has-tasks').exists()).toBe(true)
	})
})
