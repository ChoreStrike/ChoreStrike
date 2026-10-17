/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

/**
 * Manual requestAnimationFrame driver for game-loop tests: frames run only
 * when a test calls `frame(time)`, so timing is fully deterministic.
 *
 * @return {{frame: function(number): void, pending: function(): number, restore: function(): void}}
 */
export function installAnimationFrames() {
	const originalRequest = global.requestAnimationFrame
	const originalCancel = global.cancelAnimationFrame
	let nextId = 1
	let callbacks = new Map()

	global.requestAnimationFrame = jest.fn((callback) => {
		const id = nextId++
		callbacks.set(id, callback)
		return id
	})
	global.cancelAnimationFrame = jest.fn((id) => {
		callbacks.delete(id)
	})

	return {
		frame(time) {
			const due = callbacks
			callbacks = new Map()
			for (const callback of due.values()) {
				callback(time)
			}
		},
		pending() {
			return callbacks.size
		},
		restore() {
			global.requestAnimationFrame = originalRequest
			global.cancelAnimationFrame = originalCancel
		},
	}
}

/**
 * Deterministic random source (mulberry32) for seeded game-logic tests.
 *
 * @param {number} seed Integer seed
 * @return {function(): number} Random numbers in [0, 1)
 */
export function seededRandom(seed) {
	let value = seed >>> 0
	return () => {
		value = (value + 0x6D2B79F5) >>> 0
		let mixed = value
		mixed = Math.imul(mixed ^ (mixed >>> 15), mixed | 1)
		mixed ^= mixed + Math.imul(mixed ^ (mixed >>> 7), mixed | 61)
		return ((mixed ^ (mixed >>> 14)) >>> 0) / 4294967296
	}
}
