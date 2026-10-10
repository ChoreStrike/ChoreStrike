import { getIcon, hasIcon } from '@/utils/icons'

describe('icons utils', () => {
	describe('hasIcon', () => {
		it('returns true for known icons', () => {
			expect(hasIcon('Home')).toBe(true)
			expect(hasIcon('Close')).toBe(true)
		})

		it('returns false for unknown icons', () => {
			expect(hasIcon('NonExistentIcon')).toBe(false)
		})
	})

	describe('getIcon', () => {
		it('returns a component for known icons', () => {
			const icon = getIcon('Home')
			expect(icon).toBeDefined()
		})

		it('registers the neutral My Day sun as a real SVG icon', () => {
			expect(hasIcon('Sun')).toBe(true)
			expect(getIcon('Sun').name).toBe('SunFluentSvgIcon')
			expect(getIcon('Sun').render).toEqual(expect.any(Function))
		})

		it('returns undefined for unknown icons', () => {
			expect(getIcon('NonExistentIcon')).toBeUndefined()
		})
	})
})
