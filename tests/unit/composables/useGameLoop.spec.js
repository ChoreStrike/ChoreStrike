/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach, afterEach } from '@jest/globals'
import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import { STEP_MS, useGameLoop } from '@/composables/useGameLoop.js'
import { installAnimationFrames } from '../helpers/gameTestHelpers.js'

/**
 * CS-PLAN-086 §3.4: fixed-timestep loop; Snake defect S3 (the old interval
 * kept running while the tab was hidden) is the auto-pause regression.
 */
describe('useGameLoop', () => {
	let frames
	let onStep
	let onRender
	let onAutoPause

	const setVisibility = (state) => {
		Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => state })
		document.dispatchEvent(new Event('visibilitychange'))
	}

	const createLoop = () => useGameLoop({ onStep, onRender, onAutoPause })

	beforeEach(() => {
		frames = installAnimationFrames()
		onStep = jest.fn()
		onRender = jest.fn()
		onAutoPause = jest.fn()
	})

	afterEach(() => {
		frames.restore()
		Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' })
	})

	it('advances in fixed steps and counts the stepped game time', () => {
		const loop = createLoop()
		loop.start()
		frames.frame(1000)
		expect(onStep).not.toHaveBeenCalled()

		frames.frame(1000 + STEP_MS * 3 + 1)

		expect(onStep).toHaveBeenCalledTimes(3)
		expect(onStep).toHaveBeenCalledWith(STEP_MS)
		expect(onRender).toHaveBeenCalledTimes(2)
		expect(loop.elapsedMs()).toBe(50)
		loop.dispose()
	})

	it('bounds the catch-up after a long frame instead of jumping ahead', () => {
		const loop = createLoop()
		loop.start()
		frames.frame(0)
		frames.frame(5000)

		expect(onStep).toHaveBeenCalledTimes(5)
		loop.dispose()
	})

	it('ignores a second start while running or paused (no double loop)', () => {
		const loop = createLoop()
		expect(loop.start()).toBe(true)
		expect(loop.start()).toBe(false)
		expect(frames.pending()).toBe(1)

		loop.pause()
		expect(loop.start()).toBe(false)
		expect(loop.status.value).toBe('paused')
		loop.dispose()
	})

	it('S3: pauses when the page is hidden and steps nothing while hidden', () => {
		const loop = createLoop()
		loop.start()
		frames.frame(0)

		setVisibility('hidden')

		expect(loop.status.value).toBe('paused')
		expect(onAutoPause).toHaveBeenCalledTimes(1)
		expect(global.cancelAnimationFrame).toHaveBeenCalled()
		expect(frames.pending()).toBe(0)
		frames.frame(60000)
		expect(onStep).not.toHaveBeenCalled()
		loop.dispose()
	})

	it('pauses on window blur, but not when it is already paused', () => {
		const loop = createLoop()
		loop.start()
		window.dispatchEvent(new Event('blur'))
		window.dispatchEvent(new Event('blur'))

		expect(loop.status.value).toBe('paused')
		expect(onAutoPause).toHaveBeenCalledTimes(1)
		loop.dispose()
	})

	it('resumes without counting the paused time', () => {
		const loop = createLoop()
		loop.start()
		frames.frame(0)
		frames.frame(STEP_MS * 2)
		loop.pause()

		expect(loop.resume()).toBe(true)
		frames.frame(90000)
		expect(onStep).toHaveBeenCalledTimes(2)
		frames.frame(90000 + STEP_MS)

		expect(onStep).toHaveBeenCalledTimes(3)
		expect(loop.elapsedMs()).toBe(Math.round(STEP_MS * 3))
		expect(loop.resume()).toBe(false)
		loop.dispose()
	})

	it('stops inside a step without running the remaining catch-up steps', () => {
		let loop = null
		onStep = jest.fn(() => loop.stop())
		loop = createLoop()
		loop.start()
		frames.frame(0)
		frames.frame(STEP_MS * 4)

		expect(onStep).toHaveBeenCalledTimes(1)
		expect(loop.status.value).toBe('stopped')
		expect(frames.pending()).toBe(0)
		expect(loop.start()).toBe(true)
		loop.dispose()
	})

	it('cleans up its frame and listeners when the owning component unmounts', () => {
		let loop = null
		const Host = defineComponent({
			setup() {
				loop = createLoop()
				loop.start()
				return () => h('div')
			},
		})
		const wrapper = mount(Host)
		expect(frames.pending()).toBe(1)

		wrapper.unmount()

		expect(frames.pending()).toBe(0)
		expect(loop.status.value).toBe('stopped')
		setVisibility('hidden')
		window.dispatchEvent(new Event('blur'))
		expect(onAutoPause).not.toHaveBeenCalled()
	})
})
