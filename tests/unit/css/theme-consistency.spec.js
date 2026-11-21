/**
 * Regression guard for BUG-UI-001: overdue / priority / status styles must use
 * Nextcloud theme CSS variables (which flip correctly in Dark Mode), not
 * hardcoded light hex colors that turn into white-on-white in Dark Mode.
 *
 * This is a static-analysis test: it reads the affected component files and
 * asserts that the dark-mode-critical selectors reference `var(--color-*)`.
 * It cannot verify rendered pixels, but it prevents the exact regression
 * (someone re-introducing a hardcoded light background) cheaply and reliably.
 */
import { readdirSync, readFileSync } from 'fs'
import { resolve } from 'path'

const SRC = resolve(__dirname, '..', '..', '..', 'src')

function readComponent(relPath) {
	return readFileSync(resolve(SRC, relPath), 'utf8')
}

/**
 * Extract the declaration following a CSS property within a selector block.
 * Returns the raw declaration lines (e.g. the `background:` / `color:` lines).
 */
function declarationsForSelector(css, selector) {
	// Match the selector up to its closing brace (handles nested braces minimally
	// because these selectors have flat bodies).
	const pattern = new RegExp(
		`${selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*\\{([^}]*)\\}`,
		'g',
	)
	const lines = []
	let match
	while ((match = pattern.exec(css)) !== null) {
		lines.push(match[1])
	}
	return lines.join('\n')
}

describe('BUG-UI-001 — Dark-Mode-safe colors in overdue/priority styles', () => {
	// CS-PLAN-091 WP-B6: the one task row; it shows no priority badge (D-091-85).
	describe('TaskRow.vue (the one task row)', () => {
		const css = readComponent('components/Tasks/TaskRow.vue')

		// Every row is a card of its own (the operator's GUI review of WP-B6).
		it('frames every row as a card with theme variables only', () => {
			const card = declarationsForSelector(css, '.task-row-group')
			expect(card).toMatch(/border:\s*1px solid var\(--color-border\)/)
			expect(card).toMatch(/background:\s*var\(--color-main-background\)/)
			expect(card).not.toMatch(/#[0-9a-f]{3,6}\b/i)
			// The frame is the card's: the row inside draws no line of its own.
			expect(declarationsForSelector(css, '.task-row')).not.toMatch(/border/)
		})

		it('marks an overdue card with theme variables only', () => {
			const block = declarationsForSelector(css, '.task-row-group.overdue')
			expect(block).toMatch(/border-color:\s*var\(--color-element-error\)/)
			expect(block).not.toMatch(/#[0-9a-f]{3,6}\b/i)
		})

		it('marks an opened card with theme variables only', () => {
			const block = declarationsForSelector(css, '.task-row-group.open')
			expect(block).toMatch(/border-color:\s*var\(--color-primary-element-light-hover\)/)
			expect(block).toMatch(/background:\s*var\(--color-primary-element-light\)/)
			expect(block).not.toMatch(/#[0-9a-f]{3,6}\b/i)
		})

		it('uses theme variables for the overdue badge and the overdue due text', () => {
			expect(declarationsForSelector(css, '.overdue-badge')).toMatch(/background:\s*var\(--color-main-background\)/)
			expect(declarationsForSelector(css, '.overdue-badge')).toMatch(/color:\s*var\(--color-error-text\)/)
			expect(declarationsForSelector(css, '.task-due.overdue')).toMatch(/color:\s*var\(--color-error-text\)/)
		})

		it('shows no priority badge', () => {
			expect(css).not.toContain('priority-badge')
		})
	})
})

/**
 * UX-017 (CS-PLAN-084 WP-B): since Nextcloud 32 the status variables are
 * semantic pairs — `--color-<state>` is a pale tint (a background), text uses
 * `--color-<state>-text`, borders/fills/icons use `--color-element-<state>`.
 * `--color-primary` is theme-independent; accent text/borders use
 * `--color-primary-element`. Painting with the tint made status colors
 * invisible on NC34/35 — this guard keeps every component on the pairs.
 */
describe('UX-017 — Nextcloud 34/35 semantic color pairs', () => {
	const files = readdirSync(SRC, { recursive: true })
		.filter(file => /\.(vue|css)$/.test(file))
		.map(file => ({ file: file.replace(/\\/g, '/'), lines: readFileSync(resolve(SRC, file), 'utf8').split('\n') }))
	const offenders = (pattern) => files.flatMap(({ file, lines }) => lines
		.map((line, index) => pattern.test(line) ? `${file}:${index + 1}: ${line.trim()}` : null)
		.filter(Boolean))

	it('never paints text, borders, fills or icons with a pale status tint', () => {
		expect(offenders(/^\s*(color|border(-[a-z-]+)?|outline(-color)?|fill|stroke)\s*:.*var\(--color-(success|warning|error|info)(,[^)]*)?\)/)).toEqual([])
	})

	it('never uses the theme-independent primary for text or borders', () => {
		expect(offenders(/^\s*(color|border(-[a-z-]+)?|outline(-color)?)\s*:.*var\(--color-primary(,[^)]*)?\)/)).toEqual([])
	})

	it('never puts white text on a status tint badge', () => {
		const tinted = files.flatMap(({ file, lines }) => lines
			.map((line, index) => /^\s*background(-color)?\s*:\s*var\(--color-(success|warning|error|info)\)/.test(line)
				&& /color:\s*(white|#fff\b|#ffffff\b|var\(--color-primary-text)/i.test(lines.slice(index - 3, index + 4).join('\n'))
				? `${file}:${index + 1}` : null)
			.filter(Boolean))
		expect(tinted).toEqual([])
	})

	it('keys the dark token overrides to the Nextcloud theme on <body>, not to :root or the OS alone', () => {
		const tokens = readComponent('styles/tokens.css')
		expect(tokens).toMatch(/body\[data-theme-dark\],\s*body\[data-theme-dark-highcontrast\]\s*\{/)
		expect(tokens).toMatch(/@media \(prefers-color-scheme: dark\) \{\s*body\[data-theme-default\]\s*\{/)
		expect(tokens).not.toMatch(/:root\[data-theme-dark\]|\[data-theme-dark\] :root|:root:not\(\[data-theme-light\]\)/)
	})
})

/**
 * CS-PLAN-090 WP-K (D-090-67): a colour scheme reaches only what reads a
 * token, so no component paints with a colour literal. Literals live in
 * `tokens.css` and `schemes.css`; the arcade boards keep their own game
 * palette (their `var()` fallbacks), like `confetti.js` (not a stylesheet).
 */
describe('WP-K — colour literals live in the token files', () => {
	const ALLOWED = new Set(['styles/tokens.css', 'styles/schemes.css'])
	const GAME_BOARDS = /^components\/Minigames\/games\//
	const LITERAL = /#[0-9a-f]{3,8}\b|\b(rgba?|hsla?)\(|(?<![\w-])(white|black)(?![\w-])/i

	/**
	 * Declarations of a stylesheet or of a component's <style> blocks,
	 * comments removed.
	 * @param {string} file Path below src/.
	 * @param {string} text File content.
	 * @return {string[]} `property: value` strings.
	 */
	function declarations(file, text) {
		const css = file.endsWith('.vue')
			? [...text.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map(match => match[1]).join('\n')
			: text
		return css.replace(/\/\*[\s\S]*?\*\//g, '')
			.split(/[;{}]/)
			.map(part => part.trim())
			.filter(part => /^[a-z-]+\s*:/.test(part))
	}

	it('finds no colour literal outside the token files and the game boards', () => {
		const offenders = readdirSync(SRC, { recursive: true })
			.map(file => file.replace(/\\/g, '/'))
			.filter(file => /\.(vue|css)$/.test(file) && !ALLOWED.has(file) && !GAME_BOARDS.test(file))
			.flatMap(file => declarations(file, readFileSync(resolve(SRC, file), 'utf8'))
				.filter(declaration => LITERAL.test(declaration.replace(/^[a-z-]+\s*:/, '')))
				.map(declaration => `${file}: ${declaration}`))
		expect(offenders).toEqual([])
	})

	it('recognises the literal forms it guards', () => {
		const sample = declarations('x.css', 'a { color: #fff; border: solid white; box-shadow: 0 0 1px rgba(0, 0, 0, .2); background: var(--x); outline: 1px solid currentcolor; }')
		expect(sample.filter(declaration => LITERAL.test(declaration.replace(/^[a-z-]+\s*:/, '')))).toHaveLength(3)
	})
})
