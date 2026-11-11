import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
import TodayTaskRow from '@/components/Today/TodayTaskRow.vue'
import { confirmHold } from '../helpers/hold.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, params) => Object.entries(params ?? {})
		.reduce((label, [key, value]) => label.replaceAll(`{${key}}`, String(value)), text)),
}))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn() }))
jest.mock('@/utils/logger', () => ({ logger: { error: jest.fn() } }))

let mockRoomTasksStore
let mockChecklistStore
let mockRoomsStore
let mockUserStore
let mockHouseStore

jest.mock('@/store/roomTasks.js', () => ({ useRoomTasksStore: () => mockRoomTasksStore }))
jest.mock('@/store/checklist.js', () => ({ useChecklistStore: () => mockChecklistStore }))
jest.mock('@/store/rooms.js', () => ({ useRoomsStore: () => mockRoomsStore }))
jest.mock('@/store/user.js', () => ({ useUserStore: () => mockUserStore }))
jest.mock('@/store/house.js', () => ({ useHouseStore: () => mockHouseStore }))

const dayKey = (offset) => new Date(Date.now() + offset * 86400000).toISOString().slice(0, 10)

const item = (overrides = {}) => ({
	roomTask: { id: 11, taskId: 1, roomId: 1, isActive: true },
	task: { id: 1, name: 'Do the dishes', energyCost: 3, frequencyType: 'daily', frequencyValue: 3, flexDays: 2 },
	room: { id: 1, name: 'Kitchen', floorId: 3 },
	assignees: ['mom'],
	checklistItemCount: 0,
	...overrides,
})

describe('TodayTaskRow', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		mockRoomTasksStore = reactive({
			completingIds: {},
			justCompletedIds: {},
			isCompleting: (id) => mockRoomTasksStore.completingIds[id] === true,
			wasJustCompleted: (id) => mockRoomTasksStore.justCompletedIds[id] === true,
		})
		mockChecklistStore = reactive({
			items: {},
			progress: {},
			getTaskItems: (taskId) => mockChecklistStore.items[taskId] || [],
			getRoomTaskProgress: (roomTaskId) => mockChecklistStore.progress[roomTaskId] || [],
			fetchTaskItems: jest.fn().mockResolvedValue([]),
			fetchRoomTaskProgress: jest.fn().mockResolvedValue([]),
			toggleItem: jest.fn().mockResolvedValue({}),
		})
		mockRoomsStore = { getFloorById: (floorId) => (floorId === 3 ? { id: 3, name: 'Upstairs' } : undefined) }
		mockUserStore = { houseUsers: [{ nextcloudUserId: 'mom', displayName: 'Parent' }] }
		mockHouseStore = { timezone: 'UTC' }
	})

	const mountRow = (props = {}) => mount(TodayTaskRow, { props: { item: item(), ...props } })

	it('shows the same facts as a task list: name, energy, recurrence, floor and room, people', () => {
		const wrapper = mountRow({ item: item({ occurrence: { energy: 2 }, task: { ...item().task, instructions: 'Rinse first.' } }) })

		expect(wrapper.get('.task-name').text()).toBe('Do the dishes')
		expect(wrapper.get('[data-testid="task-energy"]').text()).toMatch(/ 2$/)
		expect(wrapper.get('[data-testid="task-recurrence"]').text()).toContain('Every 3 days ±2')
		expect(wrapper.get('[data-testid="task-location"]').text()).toBe('Upstairs · Kitchen')
		expect(wrapper.get('[data-testid="task-assignees"]').text()).toContain('Assigned to: Parent')
		expect(wrapper.get('.task-instructions').text()).toBe('Rinse first.')
	})

	it('names the people the occurrence obliges before the standing assignees', () => {
		mockUserStore.houseUsers.push({ nextcloudUserId: 'teen', displayName: 'Teen' })
		const wrapper = mountRow({ item: item({ proposal: { obligatedUserIds: ['teen'] } }) })

		expect(wrapper.get('[data-testid="task-assignees"]').text()).toContain('Assigned to: Teen')
	})

	it('falls back to the description and shows no instruction line without either', () => {
		expect(mountRow({ item: item({ task: { ...item().task, description: 'Short note' } }) }).get('.task-instructions').text()).toBe('Short note')
		expect(mountRow().find('.task-instructions').exists()).toBe(false)
	})

	it('emits the row on a confirmed hold and names the task on the button', async () => {
		const row = item()
		const wrapper = mountRow({ item: row })
		const button = wrapper.get('[data-testid="today-complete-button-11"]')

		expect(button.attributes('aria-label')).toBe('Mark “Do the dishes” as done')
		await confirmHold(wrapper, '[data-testid="today-complete-button-11"]')

		expect(wrapper.emitted('complete')).toEqual([[row]])
	})

	it('marks the button busy while the completion is in flight', async () => {
		const wrapper = mountRow()
		mockRoomTasksStore.completingIds = { 11: true }
		await wrapper.vm.$nextTick()

		const button = wrapper.get('[data-testid="today-complete-button-11"]')
		expect(button.attributes('disabled')).toBeDefined()
		expect(button.attributes('aria-busy')).toBe('true')
	})

	it('shows a finished one-time row with its check mark instead of the button', () => {
		const wrapper = mountRow({ item: item({ roomTask: { id: 11, isActive: false } }) })

		expect(wrapper.find('[data-testid="today-completed-check-11"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="today-complete-button-11"]').exists()).toBe(false)
		expect(wrapper.get('[data-testid="today-task-item"]').classes()).toContain('completed')
		expect(wrapper.get('[data-testid="today-task-item"]').classes()).not.toContain('cs-just-completed')
	})

	it('shows the check mark and the feedback while a recurring row was just completed (D-090-30)', async () => {
		const wrapper = mountRow()
		const row = wrapper.get('[data-testid="today-task-item"]')
		expect(row.classes()).not.toContain('cs-just-completed')

		mockRoomTasksStore.justCompletedIds = { 11: true }
		await wrapper.vm.$nextTick()

		expect(row.classes()).toEqual(expect.arrayContaining(['cs-just-completed', 'completed']))
		expect(wrapper.find('[data-testid="today-completed-check-11"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="today-complete-button-11"]').exists()).toBe(false)

		mockRoomTasksStore.justCompletedIds = {}
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="today-complete-button-11"]').exists()).toBe(true)
	})

	describe('an overdue row', () => {
		const overdue = (occurrence) => mountRow({ variant: 'overdue', item: item({ occurrence }) })

		it('shows the missed date with the end of its window and the days since', () => {
			const wrapper = overdue({ plannedDate: dayKey(-3), windowEnd: `${dayKey(-3)}T20:00:00+00:00` })

			expect(wrapper.get('[data-testid="today-task-item"]').classes()).toContain('overdue')
			expect(wrapper.get('[data-testid="catchup-planned"]').text()).toMatch(/^planned .+, until .+/)
			expect(wrapper.get('[data-testid="today-overdue-days"]').text()).toBe('3 days')
		})

		it('counts from the end of a window that reached past the planned day', () => {
			const wrapper = overdue({ plannedDate: dayKey(-3), windowEnd: `${dayKey(-1)}T20:00:00+00:00` })

			expect(wrapper.get('[data-testid="today-overdue-days"]').text()).toBe('1 day')
		})

		it('counts from the planned day without a window end and then names no time', () => {
			const wrapper = overdue({ plannedDate: dayKey(-2) })

			expect(wrapper.get('[data-testid="catchup-planned"]').text()).not.toContain('until')
			expect(wrapper.get('[data-testid="today-overdue-days"]').text()).toBe('2 days')
		})

		it('shows no day count for a window that ended today', () => {
			const wrapper = overdue({ plannedDate: dayKey(0), windowEnd: `${dayKey(0)}T00:00:01+00:00` })

			expect(wrapper.find('[data-testid="today-overdue-days"]').exists()).toBe(false)
		})

		it('reads the occurrence from the room task when the row carries none', () => {
			const wrapper = mountRow({ variant: 'overdue', item: item({ roomTask: { id: 11, isActive: true, occurrence: { plannedDate: dayKey(-2) } } }) })

			expect(wrapper.get('[data-testid="today-overdue-days"]').text()).toBe('2 days')
		})

		it('shows neither line nor count without an occurrence block', () => {
			const wrapper = mountRow({ variant: 'overdue' })

			expect(wrapper.find('[data-testid="catchup-planned"]').exists()).toBe(false)
			expect(wrapper.find('[data-testid="today-overdue-days"]').exists()).toBe(false)
		})
	})

	it('names the planned day of a row that is only possible today, and no day for a due row', () => {
		const occurrence = { plannedDate: dayKey(1), windowEnd: `${dayKey(2)}T20:00:00+00:00` }
		const possible = mountRow({ variant: 'possible', item: item({ occurrence }) })
		const due = mountRow({ item: item({ occurrence }) })

		expect(possible.get('[data-testid="today-open-window-planned-11"]').text()).toMatch(/^planned /)
		expect(possible.get('[data-testid="today-open-window-planned-11"]').text()).not.toContain('until')
		expect(possible.find('[data-testid="today-overdue-days"]').exists()).toBe(false)
		expect(due.find('.task-planned').exists()).toBe(false)
	})

	describe('the checklist', () => {
		const withChecklist = () => mountRow({ item: item({ checklistItemCount: 2 }) })
		const toggle = (wrapper) => wrapper.get('[data-testid="today-checklist-toggle-11"]')

		it('offers the toggle only for a row with checklist items, without reading anything', () => {
			expect(mountRow().find('[data-testid="today-checklist-toggle-11"]').exists()).toBe(false)
			expect(toggle(withChecklist()).get('span:last-child').text()).toBe('Checklist')
			expect(mockChecklistStore.fetchTaskItems).not.toHaveBeenCalled()
		})

		it('reads items and progress on expand, shows the execute-only list and toggles an item', async () => {
			mockChecklistStore.items[1] = [{ id: 77, label: 'Rinse plates', sortOrder: 1 }, { id: 78, label: 'Load', sortOrder: 2 }]
			mockChecklistStore.progress[11] = [{ checklistItemId: 77, isChecked: true }]
			const wrapper = withChecklist()

			await toggle(wrapper).trigger('click')
			await flushPromises()

			expect(mockChecklistStore.fetchTaskItems).toHaveBeenCalledWith(1)
			expect(mockChecklistStore.fetchRoomTaskProgress).toHaveBeenCalledWith(11)
			expect(toggle(wrapper).attributes('aria-expanded')).toBe('true')
			expect(toggle(wrapper).get('span:last-child').text()).toBe('Checklist (1/2)')
			expect(wrapper.find('[data-testid="task-checklist"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="checklist-add-item-button"]').exists()).toBe(false)

			await wrapper.get('[data-testid="checklist-item-78"] input').setValue(true)
			expect(mockChecklistStore.toggleItem).toHaveBeenCalledWith(11, 78)

			await toggle(wrapper).trigger('click')
			expect(wrapper.find('[data-testid="task-checklist"]').exists()).toBe(false)
		})

		it('collapses again when the items were deleted since the row was read', async () => {
			const wrapper = withChecklist()

			await toggle(wrapper).trigger('click')
			await flushPromises()

			expect(wrapper.find('[data-testid="task-checklist"]').exists()).toBe(false)
		})

		it('reports a failed read and a failed toggle', async () => {
			const { showError } = jest.requireMock('@nextcloud/dialogs')
			mockChecklistStore.items[1] = [{ id: 77, label: 'Rinse plates', sortOrder: 1 }]
			mockChecklistStore.fetchRoomTaskProgress.mockRejectedValueOnce(new Error('offline'))
			const wrapper = withChecklist()

			await toggle(wrapper).trigger('click')
			await flushPromises()
			expect(showError).toHaveBeenCalledWith('Could not load the checklist')

			mockChecklistStore.toggleItem.mockRejectedValueOnce(Object.assign(new Error('Conflict'), { status: 409, code: 'CONFLICT' }))
			await wrapper.get('[data-testid="checklist-item-77"] input').setValue(true)
			await flushPromises()
			expect(showError).toHaveBeenCalledWith('Could not update the checklist (CONFLICT)')
		})

		it('reads the progress again when the open row was just completed, and not when it is closed', async () => {
			mockChecklistStore.items[1] = [{ id: 77, label: 'Rinse plates', sortOrder: 1 }]
			const open = withChecklist()
			await toggle(open).trigger('click')
			await flushPromises()
			mockChecklistStore.fetchRoomTaskProgress.mockClear()

			mockRoomTasksStore.justCompletedIds = { 11: true }
			await flushPromises()
			expect(mockChecklistStore.fetchRoomTaskProgress).toHaveBeenCalledTimes(1)

			mockRoomTasksStore.justCompletedIds = {}
			await flushPromises()
			mockChecklistStore.fetchRoomTaskProgress.mockClear()
			await toggle(open).trigger('click')
			mockRoomTasksStore.justCompletedIds = { 11: true }
			await flushPromises()
			expect(mockChecklistStore.fetchRoomTaskProgress).not.toHaveBeenCalled()
		})
	})
})
