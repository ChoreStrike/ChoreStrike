describe('confetti utility', () => {
	let confettiModule
	let mockConfetti

	beforeEach(() => {
		jest.resetModules()
		mockConfetti = jest.fn()
		jest.doMock('canvas-confetti', () => ({
			__esModule: true,
			default: mockConfetti,
		}))
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	it('fires confetti when reduced motion is not preferred', () => {
		Object.defineProperty(window, 'matchMedia', {
			writable: true,
			value: jest.fn().mockReturnValue({ matches: false }),
		})

		confettiModule = require('@/utils/confetti')
		confettiModule.fireConfetti()

		expect(mockConfetti).toHaveBeenCalledTimes(1)
		expect(mockConfetti).toHaveBeenCalledWith(
			expect.objectContaining({ disableForReducedMotion: true }),
		)
	})

	it('does not fire confetti when reduced motion is preferred', () => {
		Object.defineProperty(window, 'matchMedia', {
			writable: true,
			value: jest.fn().mockReturnValue({ matches: true }),
		})

		confettiModule = require('@/utils/confetti')
		confettiModule.fireConfetti()

		expect(mockConfetti).not.toHaveBeenCalled()
	})

	it('fires milestone confetti from both sides', () => {
		Object.defineProperty(window, 'matchMedia', {
			writable: true,
			value: jest.fn().mockReturnValue({ matches: false }),
		})

		confettiModule = require('@/utils/confetti')
		confettiModule.fireMilestoneConfetti()

		expect(mockConfetti).toHaveBeenCalledTimes(2)
	})

	it('areCelebrationsEnabled returns false when reduced motion is preferred', () => {
		Object.defineProperty(window, 'matchMedia', {
			writable: true,
			value: jest.fn().mockReturnValue({ matches: true }),
		})

		confettiModule = require('@/utils/confetti')
		expect(confettiModule.areCelebrationsEnabled()).toBe(false)
	})

	it('areCelebrationsEnabled returns true when reduced motion is not preferred', () => {
		Object.defineProperty(window, 'matchMedia', {
			writable: true,
			value: jest.fn().mockReturnValue({ matches: false }),
		})

		confettiModule = require('@/utils/confetti')
		expect(confettiModule.areCelebrationsEnabled()).toBe(true)
	})
})
