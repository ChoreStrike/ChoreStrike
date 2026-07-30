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
import { readFileSync } from 'fs'
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
	describe('TaskList.vue', () => {
		const css = readComponent('components/Tasks/TaskList.vue')

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
