import { isMobileViewport, isTabletViewport } from '@/utils/mobile'

describe('mobile utils', () => {
	const originalInnerWidth = window.innerWidth

	afterEach(() => {
		Object.defineProperty(window, 'innerWidth', {
			writable: true,
			value: originalInnerWidth,
		})
	})

	describe('isMobileViewport', () => {
		it('returns true for narrow viewport', () => {
			Object.defineProperty(window, 'innerWidth', { writable: true, value: 375 })
			expect(isMobileViewport()).toBe(true)
		})

		it('returns false for wide viewport', () => {
			Object.defineProperty(window, 'innerWidth', { writable: true, value: 1200 })
			expect(isMobileViewport()).toBe(false)
		})

		it('returns false at exactly 768', () => {
			Object.defineProperty(window, 'innerWidth', { writable: true, value: 768 })
			expect(isMobileViewport()).toBe(false)
		})
	})

	describe('isTabletViewport', () => {
		it('returns true for tablet width', () => {
			Object.defineProperty(window, 'innerWidth', { writable: true, value: 800 })
			expect(isTabletViewport()).toBe(true)
		})

		it('returns false for mobile width', () => {
			Object.defineProperty(window, 'innerWidth', { writable: true, value: 500 })
			expect(isTabletViewport()).toBe(false)
		})

		it('returns false for desktop width', () => {
			Object.defineProperty(window, 'innerWidth', { writable: true, value: 1200 })
			expect(isTabletViewport()).toBe(false)
		})
	})
})
