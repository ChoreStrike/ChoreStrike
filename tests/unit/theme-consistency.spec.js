/**
 * Theme-consistency guard for status/cleanliness colors (BUG-UI-001).
 *
 * Raw hex status colors are unreadable in Nextcloud Dark Mode; the swept
 * selector blocks in RoomCard.vue and FloorList.vue must use Nextcloud CSS
 * variables (optionally with a hex fallback inside `var(...)`). CSS
 * variables are not resolvable in jsdom, so the meaningful automated
 * assertion is a lint-style source check: no raw hex outside `var()` and
 * no hardcoded `white` text within the scoped blocks.
 *
 * Out of scope by design: decorative `.room-icon-shell.*` /
 * `.floor-icon-shell.*` colors and `.room-card.needs-cleaning` — a
 * whole-file raw-hex regex would false-positive on them.
 */
const fs = require('fs')
const path = require('path')

const DASHBOARD_DIR = path.join(__dirname, '..', '..', 'src', 'components', 'Dashboard')

/** Selector patterns whose blocks must be theme-variable based. */
const SCOPED_SELECTOR_PATTERNS = [
	/\.status-badge\.[\w-]+/,
	/\.cleanliness-fill\.[\w-]+/,
	/\.cleanliness-badge\.[\w-]+/,
	/\.delete-button:hover/,
]

/**
 * Extract all CSS rule blocks from a Vue SFC <style> section whose selector
 * list matches one of the scoped patterns.
 *
 * @param {string} source raw .vue file content
 * @return {Array<{selector: string, block: string}>} matching rule blocks
 */
function extractScopedBlocks(source) {
	const styleMatch = source.match(/<style[^>]*>([\s\S]*?)<\/style>/)
	if (!styleMatch) {
		throw new Error('No <style> section found')
	}

	const rules = []
	const ruleRegex = /([^{}]+)\{([^{}]*)\}/g
	let match
	while ((match = ruleRegex.exec(styleMatch[1])) !== null) {
		const selector = match[1].trim()
		const block = match[2]
		const isScoped = SCOPED_SELECTOR_PATTERNS.some((pattern) =>
			selector.split(',').some((part) => pattern.test(part.trim())),
		)
		if (isScoped) {
			rules.push({ selector, block })
		}
	}
	return rules
}

/** Strip var(...) calls so their hex fallbacks do not count as raw hex. */
function stripVarCalls(block) {
	return block.replace(/var\([^)]*\)/g, '')
}

describe('theme consistency (RoomCard/FloorList status colors)', () => {
	const files = ['RoomCard.vue', 'FloorList.vue']

	it.each(files)('%s has scoped status selector blocks to guard', (file) => {
		const source = fs.readFileSync(path.join(DASHBOARD_DIR, file), 'utf8')
		const blocks = extractScopedBlocks(source)
		expect(blocks.length).toBeGreaterThan(0)
	})

	it.each(files)('%s scoped status blocks contain no raw hex outside var() fallbacks', (file) => {
		const source = fs.readFileSync(path.join(DASHBOARD_DIR, file), 'utf8')
		const offenders = extractScopedBlocks(source)
			.filter(({ block }) => /#[0-9a-f]{3,8}\b/i.test(stripVarCalls(block)))
			.map(({ selector }) => selector)
		expect(offenders).toEqual([])
	})

	it.each(files)('%s scoped status blocks contain no hardcoded white text color', (file) => {
		const source = fs.readFileSync(path.join(DASHBOARD_DIR, file), 'utf8')
		const offenders = extractScopedBlocks(source)
			.filter(({ block }) => /(^|[\s:;])white\b/i.test(block))
			.map(({ selector }) => selector)
		expect(offenders).toEqual([])
	})
})
