import { resolveRoomIcon, getRoomIconOption, ROOM_ICON_OPTIONS } from '@/utils/roomIcons'

jest.mock('@/utils/icons.js', () => ({
	hasIcon: jest.fn((name) => ['Home', 'Bath', 'Utensils', 'Bed'].includes(name)),
	getIcon: jest.fn(),
	icons: {},
}))

describe('roomIcons', () => {
	describe('ROOM_ICON_OPTIONS', () => {
		it('contains value and label for each option', () => {
			for (const opt of ROOM_ICON_OPTIONS) {
				expect(opt).toHaveProperty('value')
				expect(opt).toHaveProperty('label')
			}
		})

		it('has at least 10 options', () => {
			expect(ROOM_ICON_OPTIONS.length).toBeGreaterThanOrEqual(10)
		})
	})

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

	describe('getRoomIconOption', () => {
		it('finds option by icon name', () => {
			const opt = getRoomIconOption('Home')
			expect(opt).toBeDefined()
			expect(opt.value).toBe('Home')
		})

		it('returns undefined for unknown icon', () => {
			expect(getRoomIconOption('NonExistent')).toBeUndefined()
		})
	})
})
