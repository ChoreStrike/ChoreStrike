import { describe, it, expect, vi, beforeEach } from '@jest/globals'
import { mount } from '@vue/test-utils'
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
		rooms: [{ id: 1, name: 'Kitchen', floorId: 1, cleanliness: 70, monsterCount: 2 }],
		getRoomById: vi.fn((id) => ({ id: 1, name: 'Kitchen', floorId: 1, cleanliness: 70, monsterCount: 2 })),
		getFloorById: vi.fn((id) => ({ id: 1, name: 'Ground Floor' })),
		fetchFloorsWithRooms: jest.fn(),
	}),
}))

jest.mock('../../../src/store/roomTasks.js', () => ({
	useRoomTasksStore: () => ({
		roomTasks: [{ id: 1, roomId: 1, name: 'Clean counters', isActive: true, nextDue: new Date().toISOString() }],
		roomTasksByRoom: { 1: [{ id: 1, roomId: 1, name: 'Clean counters', isActive: true, nextDue: new Date().toISOString() }] },
		fetchRoomTasks: jest.fn(),
	}),
}))

// Mock child components
jest.mock('../../../src/components/Common/Icon.vue', () => ({
	name: 'Icon',
	props: ['name', 'size'],
	template: '<span class="icon">{{ name }}</span>',
}))

jest.mock('../../../src/components/Common/MonsterSprite.vue', () => ({
	name: 'MonsterSprite',
	props: ['monsterType', 'count', 'size'],
	template: '<div class="monster-sprite">Monsters: {{ count }}</div>',
}))

jest.mock('../../../src/components/Tasks/TaskList.vue', () => ({
	name: 'TaskList',
	props: ['tasks', 'loading', 'showRoom'],
	template: '<div class="task-list">Task List ({{ tasks.length }} tasks)</div>',
}))

jest.mock('../../../src/components/Tasks/TaskForm.vue', () => ({
	name: 'TaskForm',
	props: ['task'],
	template: '<div class="task-form">Task Form</div>',
}))

jest.mock('../../../src/components/Common/Modal.vue', () => ({
	name: 'Modal',
	template: '<div class="modal"><slot /></div>',
}))

describe('RoomDetail.vue', () => {
	let pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
	})

	it('renders room detail component', () => {
		const wrapper = mount(RoomDetail, {
			props: { id: '1' },
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.room-detail').exists()).toBe(true)
	})

	it('displays room name in header', () => {
		const wrapper = mount(RoomDetail, {
			props: { id: '1' },
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('h1').exists()).toBe(true)
	})

	it('has back link to dashboard', () => {
		const wrapper = mount(RoomDetail, {
			props: { id: '1' },
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.back-link').exists()).toBe(true)
	})

	it('displays room stats', () => {
		const wrapper = mount(RoomDetail, {
			props: { id: '1' },
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.room-stats').exists()).toBe(true)
		expect(wrapper.find('.stat-item').exists()).toBe(true)
	})

	it('renders MonsterSprite component', () => {
		const wrapper = mount(RoomDetail, {
			props: { id: '1' },
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.monster-sprite').exists()).toBe(true)
	})

	it('renders TaskList component', () => {
		const wrapper = mount(RoomDetail, {
			props: { id: '1' },
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.task-list').exists()).toBe(true)
	})

	it('has add task button', () => {
		const wrapper = mount(RoomDetail, {
			props: { id: '1' },
			global: {
				plugins: [pinia],
			},
		})

		expect(wrapper.find('.primary-button').text()).toContain('Add Task')
	})

	it('opens add task modal when button clicked', async () => {
		const wrapper = mount(RoomDetail, {
			props: { id: '1' },
			global: {
				plugins: [pinia],
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
			},
		})

		expect(wrapper.vm.roomId).toBe(1)
	})
})
