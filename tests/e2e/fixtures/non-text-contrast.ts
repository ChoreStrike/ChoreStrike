/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Non-text contrast (WCAG 1.4.11, BUG-UX-006): axe's `color-contrast` rule
 * measures text only. This probe measures the graphics that carry meaning —
 * Fluent icons, progress-bar tracks and their fills — against the surface
 * they sit on and reports every one below 3:1.
 */

import type { Page } from '@playwright/test'

/**
 * Shapes whose fill, outline or border must stand out from their surface
 * (progress tracks, flex-window markers and connectors), and the fills drawn
 * on the tracks.
 */
const SHAPES = ['.health-bar-background', '.goal-bar-track', '.cleanliness-bar', '.load-bar', '.point-marker', '.connector-line']
const FILLS = ['.health-bar-fill', '.goal-bar-fill', '.cleanliness-fill', '.load-fill']

/** Every visible graphic under `#content` below 3:1, as readable lines. */
export async function nonTextContrastViolations(page: Page): Promise<string[]> {
	return page.evaluate(({ shapes, fills }) => {
		const colors = (value: string): number[][] => [...value.matchAll(/rgba?\(([^)]+)\)/g)]
			.map((match) => match[1].split(',').map((part) => Number.parseFloat(part)))
		const channel = (value: number): number => {
			const c = value / 255
			return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
		}
		const luminance = ([r, g, b]: number[]): number => 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b)
		const ratio = (a: number[], b: number[]): number => {
			const [high, low] = [luminance(a), luminance(b)].sort((x, y) => y - x)
			return (high + 0.05) / (low + 0.05)
		}
		const blend = ([r, g, b, a = 1]: number[], [br, bg, bb]: number[]): number[] => [r * a + br * (1 - a), g * a + bg * (1 - a), b * a + bb * (1 - a)]
		// The opaque surface behind an element: its ancestors' backgrounds blended.
		const surface = (element: Element | null): number[] => {
			const layers: number[][] = []
			for (let node = element; node; node = node.parentElement) {
				const [color] = colors(getComputedStyle(node).backgroundColor)
				if (color && (color[3] ?? 1) > 0) {
					layers.push(color)
					if ((color[3] ?? 1) >= 1) {
						break
					}
				}
			}
			return layers.reduceRight((base, layer) => blend(layer, base), [255, 255, 255])
		}
		const visible = (element: Element): boolean => {
			const box = element.getBoundingClientRect()
			return box.width > 0 && box.height > 0
		}
		const label = (element: Element): string => `${element.tagName.toLowerCase()}.${[...element.classList].join('.')}`
		const violations: string[] = []

		for (const svg of document.querySelectorAll('#content .icon-svg svg')) {
			const [fill] = colors(getComputedStyle(svg).fill)
			const host = svg.closest('[class]:not(.icon-svg):not(.icon-svg-content)')
			if (visible(svg) && fill && host) {
				const value = ratio(fill, surface(svg.parentElement))
				if (value < 3) {
					violations.push(`icon in ${label(host)}: ${value.toFixed(2)}:1`)
				}
			}
		}

		for (const shape of document.querySelectorAll(shapes.join(','))) {
			if (!visible(shape)) {
				continue
			}
			const behind = surface(shape.parentElement)
			const style = getComputedStyle(shape)
			const candidates = [
				colors(style.backgroundColor)[0],
				colors(style.boxShadow)[0],
				Number.parseFloat(style.borderTopWidth) > 0 ? colors(style.borderTopColor)[0] : undefined,
			].filter((color): color is number[] => color !== undefined)
			const best = Math.max(1, ...candidates.map((color) => ratio(blend(color, behind), behind)))
			if (best < 3) {
				violations.push(`shape ${label(shape)}: ${best.toFixed(2)}:1`)
			}
		}

		for (const fill of document.querySelectorAll(fills.join(','))) {
			if (!visible(fill)) {
				continue
			}
			const behind = surface(fill.parentElement)
			const style = getComputedStyle(fill)
			for (const color of [...colors(style.backgroundColor), ...colors(style.backgroundImage)]) {
				if ((color[3] ?? 1) === 0) {
					continue
				}
				const value = ratio(blend(color, behind), behind)
				if (value < 3) {
					violations.push(`fill ${label(fill)}: ${value.toFixed(2)}:1`)
				}
			}
		}
		return violations
	}, { shapes: SHAPES, fills: FILLS })
}
