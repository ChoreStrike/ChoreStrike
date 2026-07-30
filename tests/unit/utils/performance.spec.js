import { debounce, throttle } from '@/utils/performance'

describe('performance utils', () => {
	beforeEach(() => {
		jest.useFakeTimers()
	})

	afterEach(() => {
		jest.useRealTimers()
	})

	describe('debounce', () => {
		it('delays execution', () => {
			const fn = jest.fn()
			const debounced = debounce(fn, 300)

			debounced()
			expect(fn).not.toHaveBeenCalled()

			jest.advanceTimersByTime(300)
			expect(fn).toHaveBeenCalledTimes(1)
		})

		it('resets timer on repeated calls', () => {
			const fn = jest.fn()
			const debounced = debounce(fn, 300)

			debounced()
			jest.advanceTimersByTime(200)
			debounced()
			jest.advanceTimersByTime(200)
			expect(fn).not.toHaveBeenCalled()

			jest.advanceTimersByTime(100)
			expect(fn).toHaveBeenCalledTimes(1)
		})

		it('passes arguments to the debounced function', () => {
			const fn = jest.fn()
			const debounced = debounce(fn, 100)

			debounced('a', 'b')
			jest.advanceTimersByTime(100)

			expect(fn).toHaveBeenCalledWith('a', 'b')
		})
	})

	describe('throttle', () => {
		it('executes immediately on first call', () => {
			const fn = jest.fn()
			const throttled = throttle(fn, 100)

			throttled()
			expect(fn).toHaveBeenCalledTimes(1)
		})

		it('blocks subsequent calls within limit', () => {
			const fn = jest.fn()
			const throttled = throttle(fn, 100)

			throttled()
			throttled()
			throttled()
			expect(fn).toHaveBeenCalledTimes(1)
		})

		it('allows calls after limit expires', () => {
			const fn = jest.fn()
			const throttled = throttle(fn, 100)

			throttled()
			jest.advanceTimersByTime(100)
			throttled()
			expect(fn).toHaveBeenCalledTimes(2)
		})
	})
})
