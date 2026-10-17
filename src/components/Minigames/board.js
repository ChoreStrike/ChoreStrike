/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

/** Largest board edge in CSS pixels; phones get the available width. */
export const MAX_BOARD_PX = 480

/** Upper bound of the device pixel ratio used for the canvas backing store. */
export const MAX_PIXEL_RATIO = 2

/**
 * Fit a cols × rows board into the available space in whole-cell steps
 * (fixes Snake defect S4: the board is never wider than its container).
 *
 * @param {number} availableWidth Container width in CSS pixels
 * @param {number} availableHeight Height budget in CSS pixels
 * @param {number} cols Board columns
 * @param {number} rows Board rows
 * @return {{width: number, height: number}} Board size in CSS pixels
 */
export function fitBoard(availableWidth, availableHeight, cols, rows) {
	const cell = Math.max(1, Math.floor(Math.min(
		Math.min(availableWidth, MAX_BOARD_PX) / cols,
		Math.min(availableHeight, MAX_BOARD_PX) / rows,
	)))
	return { width: cell * cols, height: cell * rows }
}

/**
 * @param {number} devicePixelRatio The window's device pixel ratio
 * @return {number} Ratio for the canvas backing store (1..2)
 */
export function canvasPixelRatio(devicePixelRatio) {
	return Math.min(MAX_PIXEL_RATIO, Math.max(1, devicePixelRatio || 1))
}

/**
 * Board colors from the Nextcloud theme variables and the app's celebration
 * tokens, so canvas boards follow the light and dark themes. Read when the
 * board is laid out, not per frame.
 *
 * @param {Element} element Element inside the themed document
 * @return {object} Named canvas colors
 */
export function readBoardPalette(element) {
	const style = getComputedStyle(element)
	const read = (name, fallback) => style.getPropertyValue(name).trim() || fallback
	return {
		floor: read('--color-main-background', '#ffffff'),
		tile: read('--color-background-dark', '#ededed'),
		furniture: read('--color-text-maxcontrast', '#6b6b6b'),
		hose: read('--color-primary-element', '#00679e'),
		nozzle: read('--color-main-text', '#222222'),
		outline: read('--color-main-text', '#222222'),
		crumb: read('--cs-celebrate-bronze', '#CD7F32'),
		golden: read('--cs-celebrate-gold', '#FDCB6E'),
		sparkle: read('--cs-celebrate-gold', '#FDCB6E'),
	}
}
