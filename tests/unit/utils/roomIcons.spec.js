import { resolveRoomIcon } from '@/utils/roomIcons'

// ROOM_ICON_OPTIONS / getRoomIconOption specs were removed together with the
// pruned exports (CS-PLAN-055): resolveRoomIcon is the live room-icon API.

jest.mock('@/utils/icons.js', () => ({
	hasIcon: jest.fn((name) => ['Home', 'Bath', 'Utensils', 'Bed'].includes(name)),
	getIcon: jest.fn(),
	icons: {},
}))

describe('roomIcons', () => {
	describe('resolveRoomIcon', () => {
		it('returns the icon name if valid', () => {
			expect(resolveRoomIcon('Home')).toBe('Home')
		})

		it('matches kitchen keywords', () => {
			expect(resolveRoomIcon(null, 'Kitchen')).toBe('Utensils')
		})

		it('matches bathroom keywords', () => {
			expect(resolveRoomIcon(null, 'Bad')).toBe('Bath')
		})

		it('falls back to Home for unknown room', () => {
			expect(resolveRoomIcon(null, 'Dungeon')).toBe('Home')
		})

		it('falls back to Home for empty room name', () => {
			expect(resolveRoomIcon(null, '')).toBe('Home')
		})
	})
})
