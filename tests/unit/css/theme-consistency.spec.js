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
	describe('TaskListItem.vue (row styles since CS-PLAN-067 §3.4)', () => {
		const css = readComponent('components/Tasks/TaskListItem.vue')

		it('uses a theme variable for the overdue task background', () => {
			const block = declarationsForSelector(css, '.task-item.overdue')
			expect(block).toMatch(/background:\s*var\(--color-error/)
			expect(block).not.toMatch(/background:\s*#fdf2f2\b/)
		})

		it('uses theme variables for the priority badge backgrounds', () => {
			for (const level of ['low', 'medium', 'high']) {
				const block = declarationsForSelector(css, `.priority-badge.${level}`)
				expect(block).toMatch(/background:\s*var\(--color-(success|warning|error)-hover/)
			}
		})
	})

	describe('FlexWindowDisplay.vue', () => {
		const css = readComponent('components/Tasks/FlexWindowDisplay.vue')

		it('uses a theme variable for the overdue status background', () => {
			const block = declarationsForSelector(css, '.status-overdue')
			expect(block).toMatch(/background:\s*var\(--color-error/)
		})

		it('uses a theme variable for the early status background', () => {
			const block = declarationsForSelector(css, '.status-early')
			expect(block).toMatch(/background:\s*var\(--color-success/)
		})
	})

	describe('RoomCard.vue', () => {
		const css = readComponent('components/Dashboard/RoomCard.vue')

		it('uses the error theme variable for the overdue count color', () => {
			const block = declarationsForSelector(css, '.task-item.overdue')
			expect(block).toMatch(/color:\s*var\(--color-error/)
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
