/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

/** Largest board edge in CSS pixels; phones get the available width. */
export const MAX_BOARD_PX = 480

/** Upper bound of the device pixel ratio used for the canvas backing store. */
export const MAX_PIXEL_RATIO = 2

/**
 * The arcade palette (CS-PLAN-086 WP-G): one named color per token of
 * `src/styles/tokens.css` with the light-theme value as its fallback, so a
 * headless context still draws a complete board.
 */
const ARCADE_COLORS = Object.freeze({
	floor: ['--cs-arcade-floor', '#FFF8EC'],
	floorAlt: ['--cs-arcade-floor-alt', '#F3E4C8'],
	grout: ['--cs-arcade-grout', '#E3D2B4'],
	ink: ['--cs-arcade-ink', '#10233D'],
	paper: ['--cs-arcade-paper', '#FFFFFF'],
	wood: ['--cs-arcade-wood', '#D9965B'],
	woodLight: ['--cs-arcade-wood-light', '#EDB97F'],
	woodDark: ['--cs-arcade-wood-dark', '#A8673A'],
	blue: ['--cs-arcade-blue', '#0984E3'],
	blueLight: ['--cs-arcade-blue-light', '#74B9FF'],
	teal: ['--cs-arcade-teal', '#00CEC9'],
	tealLight: ['--cs-arcade-teal-light', '#DFF7F6'],
	coral: ['--cs-arcade-coral', '#FF7675'],
	coralLight: ['--cs-arcade-coral-light', '#FAB1A0'],
	yellow: ['--cs-arcade-yellow', '#FDCB6E'],
	green: ['--cs-arcade-green', '#00B894'],
	bronze: ['--cs-arcade-bronze', '#CD7F32'],
	cocoa: ['--cs-arcade-cocoa', '#6D4C3D'],
	tan: ['--cs-arcade-tan', '#B08968'],
	dust: ['--cs-arcade-dust', '#8E9AAF'],
	dustLight: ['--cs-arcade-dust-light', '#DFE6E9'],
})

/** Every named board color, in token order. */
export const PALETTE_KEYS = Object.freeze(Object.keys(ARCADE_COLORS))

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
 * Board colors from the arcade tokens, which follow the light and dark
 * Nextcloud themes through `tokens.css`. Read when the board is laid out,
 * not per frame.
 *
 * @param {Element} element Element inside the themed document
 * @return {object} Named canvas colors (one per PALETTE_KEYS entry)
 */
export function readBoardPalette(element) {
	const style = getComputedStyle(element)
	const palette = {}
	for (const [key, [name, fallback]] of Object.entries(ARCADE_COLORS)) {
		palette[key] = style.getPropertyValue(name).trim() || fallback
	}
	return palette
}
