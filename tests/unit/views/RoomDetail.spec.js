import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import RoomDetail from '../../../src/views/RoomDetail.vue'
import { showError } from '@nextcloud/dialogs'
import { logger } from '../../../src/utils/logger.js'

// Mock vue-router
jest.mock('vue-router', () => ({
	useRouter: () => ({
		push: jest.fn(),
	}),
}))

// Mock stores
jest.mock('../../../src/store/rooms.js', () => ({
	useRoomsStore: () => ({
		floors: [{ id: 1, name: 'Ground Floor' }],
		rooms: [{ id: 1, name: 'Kitchen', floorId: 1, cleanliness: 70 }],
		getRoomById: jest.fn((id) => ({ id: 1, name: 'Kitchen', floorId: 1, cleanliness: 70 })),
		getFloorById: jest.fn((id) => ({ id: 1, name: 'Ground Floor' })),
		fetchFloorsWithRooms: jest.fn(),
	}),
}))

const mockDefaultRoomTasks = [{ id: 1, roomId: 1, taskId: 10, isActive: true, nextDue: new Date().toISOString() }]
let mockRoomTasks = mockDefaultRoomTasks

jest.mock('../../../src/store/roomTasks.js', () => ({
	useRoomTasksStore: () => ({
		roomTasks: mockRoomTasks,
		roomTasksByRoom: { 1: mockRoomTasks },
		fetchRoomTasks: jest.fn(),
		fetchRoomTaskStats: jest.fn().mockResolvedValue({
			totalTasks: 5,
			activeTasks: 3,
			overdueTasks: 1,
			dueTodayTasks: 2,
			completionRate: 40,
		}),
		createTaskAggregate: jest.fn(),
		updateRoomTaskDueDate: jest.fn(),
		setAssignees: jest.fn(),
		completeRoomTask: jest.fn(),
		deleteRoomTask: jest.fn(),
	}),
}))

jest.mock('../../../src/store/user.js', () => ({
	useUserStore: () => ({
		isHouseOwner: true,
		role: 'owner',
		canManageTasks: true,
		houseUsers: [],
		fetchHouseUsers: jest.fn().mockResolvedValue([]),
	}),
}))

jest.mock('../../../src/store/tasks.js', () => ({
	useTasksStore: () => ({
		fetchTasks: jest.fn(),
		getTaskById: jest.fn(() => ({
			id: 10,
			name: 'Clean counters',
			energyCost: 2,
			estimatedMinutes: 10,
			priority: 2,
			frequencyType: 'weekly',
			frequencyValue: 1,
		})),
	}),
}))

// Mock child components
jest.mock('../../../src/components/Common/Icon.vue', () => ({
	name: 'Icon',
	props: ['name', 'size'],
	template: '<span class="icon">{{ name }}</span>',
}))

jest.mock('../../../src/components/Tasks/TaskList.vue', () => ({
	name: 'TaskList',
	props: ['roomTasks', 'loading', 'showRoom'],
	template: '<div class="task-list">Task List ({{ roomTasks.length }} tasks)</div>',
}))

jest.mock('../../../src/components/Tasks/TaskEditForm.vue', () => ({
	name: 'TaskEditForm',
	props: ['task', 'loading', 'presetRoomId'],
	template: '<div class="task-edit-form">Task Edit Form</div>',
}))

jest.mock('../../../src/components/Tasks/RoomTaskEditModal.vue', () => ({
	name: 'RoomTaskEditModal',
	props: ['roomTask', 'task'],
	template: '<div class="room-task-edit-modal">Room Task Edit Modal</div>',
}))

jest.mock('../../../src/components/Common/Modal.vue', () => ({
	name: 'Modal',
	template: '<div class="modal"><slot /></div>',
}))

describe('RoomDetail.vue', () => {
	let pinia

	it('does not hide room tasks when member names fail and offers a read-only retry', async () => {
		const wrapper = mount(RoomDetail, { props: { id: '1' }, global: { plugins: [pinia], stubs: { RouterLink: true } } })
		await flushPromises()
		wrapper.vm.userStore.fetchHouseUsers.mockRejectedValueOnce(new Error('offline'))
		await wrapper.vm.loadMembers()
		await flushPromises()
		expect(wrapper.find('[data-testid="room-detail-error"]').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'TaskList' }).exists()).toBe(true)
		expect(wrapper.get('[data-testid="member-names-error"]').text()).toContain('Failed to load members')
		const reads = wrapper.vm.roomTasksStore.fetchRoomTasks.mock.calls.length
		await wrapper.get('[data-testid="member-names-error"] button').trigger('click')
		await flushPromises()
		expect(wrapper.find('[data-testid="member-names-error"]').exists()).toBe(false)
		expect(wrapper.vm.roomTasksStore.fetchRoomTasks).toHaveBeenCalledTimes(reads)
	})

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		mockRoomTasks = mockDefaultRoomTasks
	})

	// Regression (CS-PLAN-084 final review): the header's Overdue count used
	// the raw `nextDue < now` instant, so a task due today counted as overdue
	// from midnight while its row and the room stats said due (D-084-08).
	it('counts only missed occurrences as overdue in the header, never work due today', () => {
		const startOfToday = new Date()
		startOfToday.setHours(0, 0, 0, 0)
		const pastInstant = startOfToday.toISOString()
		mockRoomTasks = [
			{ id: 1, roomId: 1, taskId: 10, isActive: true, nextDue: pastInstant, occurrence: { classification: 'due' } },
			{ id: 2, roomId: 1, taskId: 10, isActive: true, nextDue: pastInstant, occurrence: { classification: 'late' } },
			{ id: 3, roomId: 1, taskId: 10, isActive: true, nextDue: pastInstant, status: 'due_today' },
			{ id: 4, roomId: 1, taskId: 10, isActive: false, nextDue: pastInstant, occurrence: { classification: 'late' } },
		]
		const wrapper = mount(RoomDetail, { props: { id: '1' }, global: { plugins: [pinia], stubs: { RouterLink: true } } })

		expect(wrapper.get('[data-testid="overdue-stat"] .stat-value').text()).toBe('1')
		expect(wrapper.get('[data-testid="overdue-stat"] .stat-value').classes()).toContain('warning')
	})

	it('renders room detail component', () => {
		const wrapper = mount(RoomDetail, {
			props: { id: '1' },
			global: {
				plugins: [pinia],
				stubs: {
					RouterLink: {
						template: '<a class="router-link-stub"><slot /></a>',
					},
				},
			},
		})

		expect(wrapper.find('.room-detail').exists()).toBe(true)
	})

	it('displays room name in header', () => {
		const wrapper = mount(RoomDetail, {
			props: { id: '1' },
			global: {
				plugins: [pinia],
				stubs: {
					RouterLink: {
						template: '<a class="router-link-stub"><slot /></a>',
					},
				},
			},
		})

		expect(wrapper.find('h1').exists()).toBe(true)
	})

	it('has back link to dashboard', () => {
		const wrapper = mount(RoomDetail, {
			props: { id: '1' },
			global: {
				plugins: [pinia],
				stubs: {
					RouterLink: {
						template: '<a class="router-link-stub"><slot /></a>',
					},
				},
			},
		})

		expect(wrapper.find('.back-link').exists()).toBe(true)
	})

	it('displays room stats', () => {
		const wrapper = mount(RoomDetail, {
			props: { id: '1' },
			global: {
				plugins: [pinia],
				stubs: {
					RouterLink: {
						template: '<a class="router-link-stub"><slot /></a>',
					},
				},
			},
		})

		expect(wrapper.find('.room-stats').exists()).toBe(true)
		expect(wrapper.find('.stat-item').exists()).toBe(true)
	})

	it('renders room overview with cleanliness ring', () => {
		const wrapper = mount(RoomDetail, {
			props: { id: '1' },
			global: {
				plugins: [pinia],
				stubs: {
					RouterLink: {
						template: '<a class="router-link-stub"><slot /></a>',
					},
				},
			},
		})

		expect(wrapper.find('.room-overview').exists()).toBe(true)
		expect(wrapper.find('.cleanliness-ring').exists()).toBe(true)
	})

	// BUG-UX-007 (CS-PLAN-088): a direct room-to-room navigation reuses the
	// view; it loads the new room, and a late answer for the old one never
	// overwrites it.
	it('loads the new room when the id changes and ignores a late answer for the old one', async () => {
		let resolveOld
		const stats = (total) => ({ totalTasks: total, activeTasks: 1, overdueTasks: 0, dueTodayTasks: 0, completionRate: 0 })
		const wrapper = mount(RoomDetail, { props: { id: '1' }, global: { plugins: [pinia], stubs: { RouterLink: true } } })
		await flushPromises()
		const store = wrapper.vm.roomTasksStore
		store.fetchRoomTaskStats.mockImplementation((roomId) => (roomId === 1
			? new Promise((resolve) => { resolveOld = resolve })
			: Promise.resolve(stats(9))))
		// A slow reload of room 1 is still pending when the route moves on.
		const pendingOld = wrapper.vm.initializeRoom()
		await flushPromises()

		await wrapper.setProps({ id: '2' })
		await flushPromises()
		resolveOld(stats(4))
		await pendingOld
		await flushPromises()

		expect(store.fetchRoomTasks).toHaveBeenLastCalledWith(2)
		expect(wrapper.vm.roomStats.totalTasks).toBe(9)
		expect(wrapper.vm.loading).toBe(false)
	})

	// CS-PLAN-088 WP-D review: a room switch refreshes the room list but
	// keeps the loaded task definitions unless the new room uses one the
	// view does not know yet.
	it('keeps the task definitions on a room switch unless the new room uses an unknown one', async () => {
		const wrapper = mount(RoomDetail, { props: { id: '1' }, global: { plugins: [pinia], stubs: { RouterLink: true } } })
		await flushPromises()
		const { roomsStore, roomTasksStore, tasksStore } = wrapper.vm
		expect(tasksStore.fetchTasks).toHaveBeenCalledTimes(1)

		roomTasksStore.roomTasksByRoom[2] = [{ id: 5, roomId: 2, taskId: 10, isActive: true }]
		await wrapper.setProps({ id: '2' })
		await flushPromises()
		expect(roomsStore.fetchFloorsWithRooms).toHaveBeenCalledTimes(2)
		expect(tasksStore.fetchTasks).toHaveBeenCalledTimes(1)

		roomTasksStore.roomTasksByRoom[3] = [{ id: 6, roomId: 3, taskId: 99, isActive: true }]
		tasksStore.getTaskById.mockImplementation((id) => (id === 10 ? { id: 10, name: 'Clean counters' } : undefined))
		await wrapper.setProps({ id: '3' })
		await flushPromises()
		expect(tasksStore.fetchTasks).toHaveBeenCalledTimes(2)
	})

	it('renders the task stats card after loading (CS-PLAN-040)', async () => {
		const wrapper = mount(RoomDetail, {
			props: { id: '1' },
			global: {
				plugins: [pinia],
				stubs: {
					RouterLink: {
						template: '<a class="router-link-stub"><slot /></a>',
					},
				},
			},
		})

		await flushPromises()

		expect(wrapper.find('[data-testid="room-stats-card"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="room-stats-total"]').text()).toBe('5')
		expect(wrapper.find('[data-testid="room-stats-active"]').text()).toBe('3')
		expect(wrapper.find('[data-testid="room-stats-overdue"]').text()).toBe('1')
		expect(wrapper.find('[data-testid="room-stats-due-today"]').text()).toBe('2')
		expect(wrapper.find('[data-testid="room-stats-completion-rate"]').text()).toBe('40%')
	})

	it('renders TaskList component', () => {
		const wrapper = mount(RoomDetail, {
			props: { id: '1' },
			global: {
				plugins: [pinia],
				stubs: {
					RouterLink: {
						template: '<a class="router-link-stub"><slot /></a>',
					},
				},
			},
		})

		expect(wrapper.find('.task-list').exists()).toBe(true)
	})

	it('has add task button', () => {
		const wrapper = mount(RoomDetail, {
			props: { id: '1' },
			global: {
				plugins: [pinia],
				stubs: {
					RouterLink: {
						template: '<a class="router-link-stub"><slot /></a>',
					},
				},
			},
		})

		expect(wrapper.find('.primary-button').text()).toContain('Add Task')
	})

	it('opens add task modal when button clicked', async () => {
		const wrapper = mount(RoomDetail, {
			props: { id: '1' },
			global: {
				plugins: [pinia],
				stubs: {
					RouterLink: {
						template: '<a class="router-link-stub"><slot /></a>',
					},
				},
			},
		})

		await wrapper.find('.primary-button').trigger('click')
		expect(wrapper.vm.showAddTaskModal).toBe(true)
	})

	it('completes a confirmed claim with its reviewed allocation, never reopening the participation dialog', async () => {
		const wrapper = mount(RoomDetail, { props: { id: '1' }, global: { plugins: [pinia], stubs: { RouterLink: true } } })
		await flushPromises()
		const claim = { participants: [{ userId: 'mom', sharePct: 100 }], allocationReviewed: true }
		await wrapper.vm.handleCompleteTask(1, claim)
		expect(wrapper.vm.participation).toBeNull()
		expect(wrapper.vm.roomTasksStore.completeRoomTask).toHaveBeenCalledWith(1, claim)
	})

	it('appends the API code to the completion failure toast (CS-PLAN-084 WP-F)', async () => {
		showError.mockClear()
		const loggerError = jest.spyOn(logger, 'error').mockImplementation(() => {})
		const wrapper = mount(RoomDetail, { props: { id: '1' }, global: { plugins: [pinia], stubs: { RouterLink: true } } })
		await flushPromises()
		wrapper.vm.roomTasksStore.completeRoomTask.mockRejectedValueOnce(Object.assign(new Error('Gone'), { status: 404, code: 'RESOURCE_NOT_FOUND' }))
		await wrapper.vm.handleCompleteTask(1, { participants: [{ userId: 'mom', sharePct: 100 }], allocationReviewed: true })

		expect(showError).toHaveBeenCalledWith('Could not complete the task (RESOURCE_NOT_FOUND)')
		loggerError.mockRestore()
	})

	it('parses room id from props correctly', () => {
		const wrapper = mount(RoomDetail, {
			props: { id: '1' },
			global: {
				plugins: [pinia],
				stubs: {
					RouterLink: {
						template: '<a class="router-link-stub"><slot /></a>',
					},
				},
			},
		})

		expect(wrapper.vm.roomId).toBe(1)
	})
})
