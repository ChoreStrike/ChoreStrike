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
	RouterLink: {
		name: 'RouterLink',
		props: ['to'],
		template: '<a class="router-link-stub" :data-to="JSON.stringify(to)"><slot /></a>',
	},
}))

// Mock stores
const mockRoomTasksByRoom = {}
jest.mock('../../../src/store/roomTasks.js', () => ({
	useRoomTasksStore: jest.fn(() => ({
		roomTasksByRoom: mockRoomTasksByRoom,
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

		it('counts only missed work as overdue, never work due today (CS-PLAN-084 D-084-08)', () => {
			const todayMidnightUtc = new Date()
			todayMidnightUtc.setUTCHours(0, 0, 0, 0)
			mockRoomTasksByRoom[2] = [
				{ id: 1, isActive: true, nextDue: todayMidnightUtc.toISOString(), occurrence: { classification: 'agreed' } },
				{ id: 2, isActive: true, nextDue: '2000-01-01T00:00:00Z', occurrence: { classification: 'late' } },
			]
			const card = mount(RoomCard, { props: { room: { ...mockRoom, id: 2 }, compact: false } })
			expect(card.vm.overdueTasks).toBe(1)
			expect(card.vm.dueTodayTasks).toBe(1)
			delete mockRoomTasksByRoom[2]
		})
	})

	it('offers no quick clean (FEAT-UX-048)', () => {
		expect(wrapper.find('[data-testid="quick-clean-1"]').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'HoldButton' }).exists()).toBe(false)
	})

	describe('Compact Mode', () => {
		it('Applies compact class when compact prop is true', async () => {
			await wrapper.setProps({ compact: true })
			expect(wrapper.find('.room-card').classes()).toContain('compact')
		})
	})

	describe('Navigation', () => {
		it('Makes the room name the one real link of the card (CS-PLAN-084 WP-C)', () => {
			const link = wrapper.get('[data-testid="room-link-1"]')
			expect(link.element.tagName).toBe('A')
			expect(JSON.parse(link.attributes('data-to'))).toEqual({ name: 'room', params: { id: 1 } })
			expect(link.text()).toBe('Kitchen')
			// The card itself is no pseudo-button: the stretched link handles clicks.
			expect(wrapper.find('.room-card').attributes('role')).toBeUndefined()
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
