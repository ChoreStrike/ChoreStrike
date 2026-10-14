import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import RoomCard from '../../../src/components/Dashboard/RoomCard.vue'
import { confirmHold } from '../helpers/hold.js'
import { showError } from '@nextcloud/dialogs'
import { logger } from '../../../src/utils/logger.js'

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
const mockUpdateRoomCleanliness = jest.fn()
jest.mock('../../../src/store/rooms.js', () => ({
	useRoomsStore: jest.fn(() => ({
		updateRoomCleanliness: mockUpdateRoomCleanliness,
	})),
}))

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
		mockUpdateRoomCleanliness.mockReset()
		showError.mockClear()
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

	describe('Quick Clean', () => {
		it('Renders a labelled quick clean hold button', () => {
			const button = wrapper.find('[data-testid="quick-clean-1"]')
			expect(button.exists()).toBe(true)
			expect(button.attributes('aria-label')).toBe('Quick clean (+10% cleanliness)')
		})

		it('Cleans only after the hold is confirmed, without opening the room', async () => {
			await wrapper.find('[data-testid="quick-clean-1"]').trigger('click')
			expect(mockUpdateRoomCleanliness).not.toHaveBeenCalled()
			expect(mockPush).not.toHaveBeenCalled()

			await confirmHold(wrapper, '[data-testid="quick-clean-1"]')
			expect(mockUpdateRoomCleanliness).toHaveBeenCalledWith(1, 10)
		})

		it('Shows a failure toast with the API code when quick clean fails (RF-084-03)', async () => {
			mockUpdateRoomCleanliness.mockRejectedValue(Object.assign(new Error('Forbidden'), { status: 403, code: 'FORBIDDEN' }))
			const loggerError = jest.spyOn(logger, 'error').mockImplementation(() => {})

			await confirmHold(wrapper, '[data-testid="quick-clean-1"]')

			expect(showError).toHaveBeenCalledWith('Could not clean the room (FORBIDDEN)')
			expect(wrapper.find('[data-testid="quick-clean-1"]').attributes('disabled')).toBeUndefined()
			loggerError.mockRestore()
		})

		it('Sends one quick clean while the first is in flight', async () => {
			let resolve
			mockUpdateRoomCleanliness.mockImplementation(() => new Promise(done => { resolve = done }))
			const hold = wrapper.findComponent({ name: 'HoldButton' })
			hold.vm.$emit('confirm')
			hold.vm.$emit('confirm')
			await wrapper.vm.$nextTick()

			expect(mockUpdateRoomCleanliness).toHaveBeenCalledTimes(1)
			expect(wrapper.find('[data-testid="quick-clean-1"]').attributes('disabled')).toBeDefined()
			resolve()
		})

		it('Disables quick clean when room is already clean', async () => {
			await wrapper.setProps({
				room: { ...mockRoom, cleanliness: 95 },
			})
			const button = wrapper.find('[data-testid="quick-clean-1"]')
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
