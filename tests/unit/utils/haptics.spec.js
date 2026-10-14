/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, afterEach } from '@jest/globals'
import { hapticsSupported, pulseCompletion } from '@/utils/haptics.js'

describe('haptics (CS-PLAN-084 WP-F)', () => {
	const original = Object.getOwnPropertyDescriptor(navigator, 'vibrate')

	const setVibrate = (value) => {
		Object.defineProperty(navigator, 'vibrate', { value, configurable: true, writable: true })
	}

	afterEach(() => {
		if (original) {
			Object.defineProperty(navigator, 'vibrate', original)
		} else {
			delete navigator.vibrate
		}
	})

	it('is a silent no-op without web vibration (e.g. iOS Safari)', () => {
		setVibrate(undefined)

		expect(hapticsSupported()).toBe(false)
		expect(pulseCompletion()).toBe(false)
	})

	it('sends one short pulse where vibration exists', () => {
		const vibrate = jest.fn(() => true)
		setVibrate(vibrate)

		expect(hapticsSupported()).toBe(true)
		expect(pulseCompletion()).toBe(true)
		expect(vibrate).toHaveBeenCalledTimes(1)
		expect(vibrate.mock.calls[0][0]).toBeLessThanOrEqual(30)
	})

	it('never throws when the browser blocks vibration', () => {
		setVibrate(jest.fn(() => { throw new Error('blocked by policy') }))

		expect(pulseCompletion()).toBe(false)
	})

	it('reports a refused vibration as not started', () => {
		setVibrate(jest.fn(() => false))

		expect(pulseCompletion()).toBe(false)
	})
})
