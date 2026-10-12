import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import RoomDetail from '../../../src/views/RoomDetail.vue'

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

jest.mock('../../../src/store/roomTasks.js', () => ({
	useRoomTasksStore: () => ({
		roomTasks: [{ id: 1, roomId: 1, taskId: 10, isActive: true, nextDue: new Date().toISOString() }],
		roomTasksByRoom: { 1: [{ id: 1, roomId: 1, taskId: 10, isActive: true, nextDue: new Date().toISOString() }] },
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
