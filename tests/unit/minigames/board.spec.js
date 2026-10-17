/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, afterEach } from '@jest/globals'
import { MAX_BOARD_PX, canvasPixelRatio, fitBoard, readBoardPalette } from '@/components/Minigames/board.js'
import { SWIPE_MIN_PX, keyAction, swipeDirection } from '@/components/Minigames/gameInput.js'

describe('board sizing (Snake defect S4)', () => {
	it('never exceeds a narrow phone container and keeps whole cells', () => {
		// Pixel 7 / iPhone 14 widths minus modal padding.
		for (const width of [300, 343, 380]) {
			const size = fitBoard(width, 800, 20, 20)
			expect(size.width).toBeLessThanOrEqual(width)
			expect(size.width % 20).toBe(0)
			expect(size.height).toBe(size.width)
		}
	})

	it('caps the board on wide screens and respects the height budget', () => {
		expect(fitBoard(1920, 1080, 20, 20)).toEqual({ width: MAX_BOARD_PX, height: MAX_BOARD_PX })
		expect(fitBoard(1920, 300, 20, 20)).toEqual({ width: 300, height: 300 })
		expect(fitBoard(400, 1000, 20, 10)).toEqual({ width: 400, height: 200 })
	})

	it('keeps at least one pixel per cell for a not yet laid out container', () => {
		expect(fitBoard(0, 0, 20, 20)).toEqual({ width: 20, height: 20 })
	})

	it('caps the canvas pixel ratio at 2', () => {
		expect(canvasPixelRatio(3)).toBe(2)
		expect(canvasPixelRatio(1.5)).toBe(1.5)
		expect(canvasPixelRatio(undefined)).toBe(1)
		expect(canvasPixelRatio(0.5)).toBe(1)
	})
})

describe('board palette', () => {
	afterEach(() => {
		document.documentElement.style.removeProperty('--color-main-background')
	})

	it('reads the theme variables with fallbacks', () => {
		document.documentElement.style.setProperty('--color-main-background', '#101010')
		const palette = readBoardPalette(document.documentElement)

		expect(palette.floor).toBe('#101010')
		expect(palette.hose).toBe('#00679e')
		expect(Object.keys(palette).sort()).toEqual(['crumb', 'floor', 'furniture', 'golden', 'hose', 'nozzle', 'outline', 'sparkle', 'tile'])
	})
})

describe('game input mapping', () => {
	it('maps arrows and WASD to directions and Space/P to the pause toggle', () => {
		expect(keyAction('ArrowUp')).toEqual({ type: 'direction', direction: 'up' })
		expect(keyAction('KeyA')).toEqual({ type: 'direction', direction: 'left' })
		expect(keyAction('Space')).toEqual({ type: 'toggle' })
		expect(keyAction('KeyP')).toEqual({ type: 'toggle' })
		expect(keyAction('Enter')).toBeNull()
		expect(keyAction('Tab')).toBeNull()
	})

	it('maps swipes to their dominant direction and ignores taps', () => {
		expect(swipeDirection(SWIPE_MIN_PX, 3)).toBe('right')
		expect(swipeDirection(-40, 10)).toBe('left')
		expect(swipeDirection(5, -60)).toBe('up')
		expect(swipeDirection(-10, 30)).toBe('down')
		expect(swipeDirection(SWIPE_MIN_PX - 1, 0)).toBeNull()
	})
})
