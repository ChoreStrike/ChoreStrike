/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

/**
 * CS-PLAN-090 WP-K (D-090-62/67): every colour scheme passes contrast in the
 * light and the dark Nextcloud theme, and the stylesheet, the client list
 * and the server list name the same schemes. The ratios use WCAG 2 relative
 * luminance against Nextcloud 35's own backgrounds (main, hover and dark
 * background of each theme): 4.5:1 for text on the accent, for the accent
 * used as text (links, active labels) and for the energy value. The browser
 * contrast spec samples the rendered views per scheme and theme.
 */
import { readFileSync } from 'fs'
import { resolve } from 'path'
import { COLOR_SCHEMES } from '../../../src/utils/colorSchemes.js'

const ROOT = resolve(__dirname, '..', '..', '..')
const css = readFileSync(resolve(ROOT, 'src/styles/schemes.css'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')

const BACKGROUNDS = {
	light: ['#FFFFFF', '#F5F5F5', '#EDEDED'],
	dark: ['#171717', '#212121', '#292929'],
}
// Theme selection (layer 2): text on the accent and on the primary colour.
const TEXT_ON_ELEMENT = { light: '#FFFFFF', dark: '#000000' }
const TEXT_ON_PRIMARY = '#FFFFFF'

function luminance(hex) {
	const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
		.map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4))
	return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

function ratio(a, b) {
	const [x, y] = [luminance(a) + 0.05, luminance(b) + 0.05]
	return Math.max(x, y) / Math.min(x, y)
}

/** The palette block of each scheme (layer 1) as a map of token → value. */
function palettes() {
	const blocks = {}
	for (const match of css.matchAll(/body\[data-cs-scheme="(\w+)"\],\s*\[data-cs-swatch="\1"\]\s*\{([^}]*)\}/g)) {
		blocks[match[1]] = Object.fromEntries([...match[2].matchAll(/(--cs-scheme-[a-z-]+):\s*([^;]+);/g)]
			.map((declaration) => [declaration[1], declaration[2].trim()]))
	}
	return blocks
}

const PALETTES = palettes()
const SCHEMES = COLOR_SCHEMES.filter((scheme) => scheme !== 'standard')
const THEMED_ROLES = ['element', 'element-hover', 'tint', 'tint-hover', 'tint-text', 'energy']
const SHARED_ROLES = ['primary', 'primary-hover', 'confetti']

describe('colour schemes — one list everywhere', () => {
	it('lists Standard and five schemes on the client and on the server', () => {
		const service = readFileSync(resolve(ROOT, 'lib/Service/ColorSchemeService.php'), 'utf8')
		const serverList = service.match(/SCHEMES = \[([^\]]*)\]/)[1].match(/'(\w+)'/g).map((key) => key.slice(1, -1))

		expect(COLOR_SCHEMES).toEqual(['standard', 'ocean', 'forest', 'sunset', 'berry', 'slate'])
		expect(serverList).toEqual(COLOR_SCHEMES)
	})

	it('has a complete palette for every scheme but Standard, and none else', () => {
		expect(Object.keys(PALETTES).sort()).toEqual([...SCHEMES].sort())
		for (const scheme of SCHEMES) {
			const expected = [
				...THEMED_ROLES.flatMap((role) => [`--cs-scheme-${role}-light`, `--cs-scheme-${role}-dark`]),
				...SHARED_ROLES.map((role) => `--cs-scheme-${role}`),
			]
			expect(Object.keys(PALETTES[scheme]).sort()).toEqual(expected.sort())
			for (const value of Object.values(PALETTES[scheme])) {
				expect(value).toMatch(/^#[0-9A-F]{6}$/)
			}
		}
	})

	it('scopes the colours to the app content and its teleported dialogs, never the header', () => {
		const application = css.match(/body\[data-cs-scheme\] :is\(([^)]*)\)\s*\{([^}]*)\}/)
		expect(application[1].split(',').map((part) => part.trim())).toEqual(['#app-chorestrike-vue', '.modal-overlay', '.wizard-overlay'])
		expect(readFileSync(resolve(ROOT, 'templates/main.php'), 'utf8')).toContain('id="app-chorestrike-vue"')
		expect(readFileSync(resolve(ROOT, 'src/components/Common/Modal.vue'), 'utf8')).toMatch(/<Teleport to="body">\s*<div v-if="show"\s*class="modal-overlay"/)
		expect(readFileSync(resolve(ROOT, 'src/components/Setup/SetupWizard.vue'), 'utf8')).toMatch(/class="wizard-overlay"/)
		// Only that block sets a primary variable: the header keeps the instance colour.
		const declared = (text) => text.match(/(^|[\s;{])--(color-primary[a-z-]*|primary-invert-if-[a-z]+|gradient-primary-background):/g) ?? []
		expect(declared(application[2])).toHaveLength(16)
		expect(declared(css)).toHaveLength(16)
	})

	it('keys the dark selection to Nextcloud\'s theme attributes on <body>, like tokens.css', () => {
		expect(css).toMatch(/body\[data-cs-scheme\]\[data-theme-dark\],\s*body\[data-theme-dark\] \[data-cs-swatch\]\s*\{/)
		expect(css).toMatch(/@media \(prefers-color-scheme: dark\) \{\s*body\[data-cs-scheme\]\[data-theme-default\],\s*body\[data-theme-default\] \[data-cs-swatch\]\s*\{/)
	})
})

describe.each(SCHEMES)('colour scheme %s — contrast', (scheme) => {
	const palette = (role, theme) => PALETTES[scheme][theme ? `--cs-scheme-${role}-${theme}` : `--cs-scheme-${role}`]

	describe.each(['light', 'dark'])('%s theme', (theme) => {
		const backgrounds = BACKGROUNDS[theme]

		it('has 4.5:1 for text on the accent and on its hover state', () => {
			expect(ratio(TEXT_ON_ELEMENT[theme], palette('element', theme))).toBeGreaterThanOrEqual(4.5)
			expect(ratio(TEXT_ON_ELEMENT[theme], palette('element-hover', theme))).toBeGreaterThanOrEqual(4.5)
		})

		it('has 4.5:1 for the accent against every background, so it may carry text (3:1 for graphics follows)', () => {
			for (const background of backgrounds) {
				expect(ratio(palette('element', theme), background)).toBeGreaterThanOrEqual(4.5)
			}
		})

		it('has 4.5:1 for the tint text on the tint and on its hover state', () => {
			expect(ratio(palette('tint-text', theme), palette('tint', theme))).toBeGreaterThanOrEqual(4.5)
			expect(ratio(palette('tint-text', theme), palette('tint-hover', theme))).toBeGreaterThanOrEqual(4.5)
		})

		it('has 4.5:1 for the energy value against every background', () => {
			for (const background of backgrounds) {
				expect(ratio(palette('energy', theme), background)).toBeGreaterThanOrEqual(4.5)
			}
		})
	})

	it('has 4.5:1 for text on the primary colour and on its hover state', () => {
		expect(ratio(TEXT_ON_PRIMARY, palette('primary'))).toBeGreaterThanOrEqual(4.5)
		expect(ratio(TEXT_ON_PRIMARY, palette('primary-hover'))).toBeGreaterThanOrEqual(4.5)
	})
})

describe('the contrast helper', () => {
	it('matches the WCAG reference values', () => {
		expect(ratio('#000000', '#FFFFFF')).toBeCloseTo(21, 5)
		expect(ratio('#767676', '#FFFFFF')).toBeCloseTo(4.54, 2)
	})
})
