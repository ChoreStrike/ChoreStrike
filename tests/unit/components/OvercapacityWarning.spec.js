/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import OvercapacityWarning from '../../../src/components/Scheduling/OvercapacityWarning.vue'
import { useSchedulingStore } from '../../../src/store/scheduling.js'
import { useRoomTasksStore } from '../../../src/store/roomTasks.js'
import { useTasksStore } from '../../../src/store/tasks.js'

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

describe('OvercapacityWarning.vue', () => {
	let pinia
	let schedulingStore
	let roomTasksStore
	let tasksStore

	const localDateKey = (offset) => {
		const date = new Date()
		date.setHours(0, 0, 0, 0)
		date.setDate(date.getDate() + offset)
		const y = date.getFullYear()
		const m = String(date.getMonth() + 1).padStart(2, '0')
		const d = String(date.getDate()).padStart(2, '0')
		return `${y}-${m}-${d}`
	}

	beforeEach(() => {
		setActivePinia(createPinia())
		pinia = createPinia()
		setActivePinia(pinia)

		schedulingStore = useSchedulingStore()
		roomTasksStore = useRoomTasksStore()
		tasksStore = useTasksStore()

		schedulingStore.memberAvailability = []
		schedulingStore.energyBudget = { monday: 10, tuesday: 10, wednesday: 10, thursday: 10, friday: 10, saturday: 15, sunday: 15 }
		schedulingStore.weekdayAvailability = { monday: true, tuesday: true, wednesday: true, thursday: true, friday: true, saturday: true, sunday: true }
	})

	const seedDueTask = (roomTaskId, taskDef, offsetDays) => {
		tasksStore.tasks.push({ id: taskDef.id, name: taskDef.name, energyCost: taskDef.energyCost, priority: taskDef.priority })
		const due = new Date()
		due.setHours(12, 0, 0, 0)
		due.setDate(due.getDate() + offsetDays)
		roomTasksStore.roomTasks.push({
			id: roomTaskId,
			taskId: taskDef.id,
			roomId: 1,
			isActive: true,
			nextDue: due.toISOString(),
		})
	}

	it('is hidden when planned energy stays under capacity', () => {
		seedDueTask(1, { id: 10, name: 'Small chore', energyCost: 2, priority: 2 }, 1)

		const wrapper = mount(OvercapacityWarning, { global: { plugins: [pinia] } })

		expect(wrapper.find('[data-testid="overcapacity-warning"]').exists()).toBe(false)
	})

	it('warns with the day balance and deferred tasks when over capacity', () => {
		const offset = 1
		const dayName = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'][
			new Date(`${localDateKey(offset)}T00:00:00`).getDay()
		]
		schedulingStore.energyBudget[dayName] = 5

		seedDueTask(1, { id: 10, name: 'High prio', energyCost: 2, priority: 3 }, offset)
		seedDueTask(2, { id: 11, name: 'Low prio big', energyCost: 6, priority: 1 }, offset)

		const wrapper = mount(OvercapacityWarning, { global: { plugins: [pinia] } })

		expect(wrapper.find('[data-testid="overcapacity-warning"]').exists()).toBe(true)

		const days = wrapper.findAll('[data-testid="overcapacity-day"]')
		expect(days).toHaveLength(1)

		const balance = wrapper.find(`[data-testid="overcapacity-balance-${localDateKey(offset)}"]`)
		expect(balance.text()).toContain('8 / 5')

		// High prio fits into capacity (2 ≤ 5), low prio big is deferred.
		const deferred = wrapper.findAll('.deferred-task')
		expect(deferred).toHaveLength(1)
		expect(deferred[0].text()).toContain('Low prio big')
	})

	it('orders deferred tasks lowest priority first', () => {
		const offset = 2
		const dayName = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'][
			new Date(`${localDateKey(offset)}T00:00:00`).getDay()
		]
		schedulingStore.energyBudget[dayName] = 3

		seedDueTask(1, { id: 10, name: 'Medium', energyCost: 4, priority: 2 }, offset)
		seedDueTask(2, { id: 11, name: 'Low', energyCost: 4, priority: 1 }, offset)
		seedDueTask(3, { id: 12, name: 'High', energyCost: 3, priority: 3 }, offset)

		const wrapper = mount(OvercapacityWarning, { global: { plugins: [pinia] } })

		const deferred = wrapper.findAll('.deferred-task')
		expect(deferred.map(node => node.text())).toHaveLength(2)
		expect(deferred[0].text()).toContain('Low')
		expect(deferred[1].text()).toContain('Medium')
	})
})
