/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { loadState } from '@nextcloud/initial-state'

/**
 * Colour schemes (CS-PLAN-090 WP-K, FEAT-UX-057, D-090-61/62/67).
 *
 * A scheme changes the accent colours inside the app for one person; the
 * palettes live in `src/styles/schemes.css` and the keys match
 * `ColorSchemeService::SCHEMES` on the server. *Standard* keeps the colour
 * of the Nextcloud instance and sets no attribute at all.
 */
export const COLOR_SCHEMES = ['standard', 'ocean', 'forest', 'sunset', 'berry', 'slate']
export const DEFAULT_COLOR_SCHEME = 'standard'
export const SCHEME_ATTRIBUTE = 'data-cs-scheme'
export const HIGH_CONTRAST_QUERY = '(prefers-contrast: more)'

/**
 * A listed scheme, or *Standard* for anything else.
 * @param {unknown} value Raw value (server payload, initial state).
 * @return {string}
 */
export function normalizeColorScheme(value) {
	return COLOR_SCHEMES.includes(value) ? value : DEFAULT_COLOR_SCHEME
}

/**
 * The scheme the page was delivered with (initial state of
 * `PageController`), so the first paint already has it.
 * @return {string}
 */
export function initialColorScheme() {
	return normalizeColorScheme(loadState('chorestrike', 'color-scheme', DEFAULT_COLOR_SCHEME))
}

/**
 * Whether Nextcloud shows a high-contrast theme: one the person chose, or
 * the system's while they follow the system (`data-theme-default`, where
 * Nextcloud applies its high-contrast variables by media query).
 * @param {HTMLElement} body The page body with Nextcloud's theme attributes.
 * @param {((query: string) => MediaQueryList)|undefined} matchMedia Bound `window.matchMedia`.
 * @return {boolean}
 */
export function isHighContrast(body, matchMedia) {
	if (body.hasAttribute('data-theme-light-highcontrast') || body.hasAttribute('data-theme-dark-highcontrast')) {
		return true
	}
	return body.hasAttribute('data-theme-default') && Boolean(matchMedia?.(HIGH_CONTRAST_QUERY)?.matches)
}

/**
 * Put the effective scheme on the body. The attribute sits there because
 * the app's dialogs are teleported to it; `schemes.css` scopes the colours
 * to the app content and those dialogs. Under a high-contrast theme every
 * scheme yields to *Standard*, because the person chose that theme for
 * contrast.
 * @param {unknown} scheme The chosen scheme.
 * @param {HTMLElement} body The page body.
 * @param {((query: string) => MediaQueryList)|undefined} matchMedia Bound `window.matchMedia`.
 * @return {string} The scheme in effect.
 */
export function applyColorScheme(scheme, body, matchMedia) {
	const effective = isHighContrast(body, matchMedia) ? DEFAULT_COLOR_SCHEME : normalizeColorScheme(scheme)
	if (effective === DEFAULT_COLOR_SCHEME) {
		body.removeAttribute(SCHEME_ATTRIBUTE)
	} else {
		body.setAttribute(SCHEME_ATTRIBUTE, effective)
	}
	return effective
}
