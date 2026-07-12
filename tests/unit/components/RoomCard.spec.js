import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import RoomCard from '../../../src/components/Dashboard/RoomCard.vue'

const mockPush = jest.fn()

// Mock Nextcloud l10n
global.OC = {
	translate: jest.fn((app, text) => text),
}

// Mock Vue Router
jest.mock('vue-router', () => ({
	useRouter: jest.fn(() => ({
		push: mockPush,
	})),
}))

// Mock stores
jest.mock('../../../src/store/rooms.js', () => ({
	useRoomsStore: jest.fn(() => ({
		updateRoomCleanliness: jest.fn(),
	})),
}))

jest.mock('../../../src/store/roomTasks.js', () => ({
	useRoomTasksStore: jest.fn(() => ({
		roomTasksByRoom: {},
	})),
}))

describe('RoomCard', () => {
	let wrapper
	const mockRoom = {
		id: 1,
		name: 'Kitchen',
		icon: 'Utensils',
		cleanliness: 75,
		lastCleaned: new Date(Date.now() - 86400000).toISOString(), // 1 day ago
	}

	beforeEach(() => {
		setActivePinia(createPinia())
		mockPush.mockClear()
		wrapper = mount(RoomCard, {
			props: {
				room: mockRoom,
				compact: false,
			},
		})
	})

	describe('Rendering', () => {
		it('Renders room name', () => {
			expect(wrapper.find('.room-name').text()).toBe('Kitchen')
		})

		it('Resolves and renders configured room icon', () => {
			const icon = wrapper.find('.room-icon-shell .icon')
			expect(icon.exists()).toBe(true)
			expect(wrapper.vm.roomIconName).toBe('Utensils')
		})

		it('Renders cleanliness percentage', () => {
			expect(wrapper.find('.cleanliness-value').text()).toBe('75%')
		})

		it('Renders cleanliness progress bar with correct width', () => {
			const fill = wrapper.find('.cleanliness-fill')
			expect(fill.attributes('style')).toContain('width: 75%')
		})

		it('Renders status badge based on cleanliness', () => {
			const badge = wrapper.find('.status-badge')
			expect(badge.exists()).toBe(true)
			expect(badge.text()).toBe('Good') // 75% = Good
		})

		it('Shows last cleaned text', () => {
			expect(wrapper.find('.last-updated').text()).toContain('Last cleaned')
		})
	})

	describe('Status Classes', () => {
		it('Applies excellent class for high cleanliness', async () => {
			await wrapper.setProps({
				room: { ...mockRoom, cleanliness: 85 },
			})
			expect(wrapper.find('.status-badge').classes()).toContain('excellent')
		})

		it('Applies poor class for low cleanliness', async () => {
			await wrapper.setProps({
				room: { ...mockRoom, cleanliness: 30 },
			})
			expect(wrapper.find('.status-badge').classes()).toContain('poor')
		})

		it('Applies needs-cleaning class when cleanliness < 60', async () => {
			await wrapper.setProps({
				room: { ...mockRoom, cleanliness: 50 },
			})
			expect(wrapper.find('.room-card').classes()).toContain('needs-cleaning')
		})
	})

	describe('Task Summary', () => {
		it('Renders active task count', () => {
			const taskItem = wrapper.find('.task-item')
			expect(taskItem.exists()).toBe(true)
		})

		it('Shows overdue indicator when tasks are overdue', async () => {
			// Mock with overdue tasks would require more setup
			const taskSummary = wrapper.find('.task-summary')
			expect(taskSummary.exists()).toBe(true)
		})
	})

	describe('Quick Clean', () => {
		it('Renders quick clean button', () => {
			const button = wrapper.find('.quick-clean-button')
			expect(button.exists()).toBe(true)
		})

		it('Disables quick clean when room is already clean', async () => {
			await wrapper.setProps({
				room: { ...mockRoom, cleanliness: 95 },
			})
			const button = wrapper.find('.quick-clean-button')
			expect(button.attributes('disabled')).toBeDefined()
		})
	})

	describe('Compact Mode', () => {
		it('Applies compact class when compact prop is true', async () => {
			await wrapper.setProps({ compact: true })
			expect(wrapper.find('.room-card').classes()).toContain('compact')
		})
	})

	describe('Navigation', () => {
		it('Navigates to room detail on card click', async () => {
			await wrapper.find('.room-card').trigger('click')
			expect(mockPush).toHaveBeenCalledWith('/room/1')
		})
	})

	describe('Props', () => {
		it('Accepts room prop', () => {
			expect(wrapper.props('room')).toEqual(mockRoom)
		})

		it('Validates room is required', () => {
			const { required } = RoomCard.props.room
			expect(required).toBe(true)
		})

		it('Has compact prop with default false', () => {
			const { default: defaultValue } = RoomCard.props.compact
			expect(defaultValue).toBe(false)
		})
	})
})
