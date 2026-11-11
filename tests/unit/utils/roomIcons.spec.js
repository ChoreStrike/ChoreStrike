import { iconForRoomType, ROOM_TYPE_TO_ICON } from '@/utils/roomIcons'

jest.mock('@/utils/icons.js', () => ({
	hasIcon: jest.fn((name) => ['Home', 'Bath', 'Utensils', 'Bed'].includes(name)),
	getIcon: jest.fn(),
	icons: {},
}))

describe('roomIcons', () => {
	describe('iconForRoomType', () => {
		it('returns the registered icon of a room type', () => {
			expect(iconForRoomType('kitchen')).toBe('Utensils')
			expect(iconForRoomType('bathroom')).toBe('Bath')
		})

		it('falls back to Home for an unknown room type', () => {
			expect(iconForRoomType('dungeon')).toBe('Home')
			expect(iconForRoomType(undefined)).toBe('Home')
		})

		it('falls back to Home when the mapped icon is not registered', () => {
			expect(ROOM_TYPE_TO_ICON.living).toBe('Sofa')
			expect(iconForRoomType('living')).toBe('Home')
		})
	})
})
